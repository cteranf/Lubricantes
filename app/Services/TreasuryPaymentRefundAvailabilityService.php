<?php

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Exceptions\PaymentRefundConflictException;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderFulfillmentHistory;
use App\Models\OrderHandlingProcess;
use App\Models\OrderItem;
use App\Models\PaymentRefund;
use App\Models\PaymentResolutionCase;
use App\Models\PaymentResolutionHistory;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\ValueObjects\TreasuryPaymentRefundAvailability;

/** Advisory snapshot only; commands always reacquire and revalidate locked evidence. */
class TreasuryPaymentRefundAvailabilityService
{
    use TreasuryPaymentRefundPredicates;

    public function __construct(private InventoryBalanceLockService $balanceLocks) {}

    public function forOrder(int $orderId): TreasuryPaymentRefundAvailability
    {
        if ($orderId < 1) {
            return new TreasuryPaymentRefundAvailability;
        }
        try {
            $state = $this->readFinancialState($orderId);
            if ($state === null) {
                return new TreasuryPaymentRefundAvailability;
            }
            $approved = $state['transactions']->where('transaction_type', PaymentTransaction::PAYMENT)->where('status', PaymentTransaction::APPROVED);
            if ($approved->isEmpty() && $state['refunds']->isEmpty() && $state['cases']->isEmpty()
                && ! $state['transactions']->contains('transaction_type', PaymentTransaction::REFUND)
                && ! $state['histories']->contains(fn ($history) => in_array($history->event, [PaymentSubmissionHistory::REFUND_MARKED_PENDING, PaymentSubmissionHistory::REFUND_COMPLETED], true))
                && ! $state['submissions']->contains(fn ($submission) => in_array($submission->status, [PaymentSubmission::APPROVED, PaymentSubmission::REFUND_PENDING, PaymentSubmission::REFUNDED], true))
                && ! in_array($state['order']->payment_status, ['approved', 'refund_pending', 'refunded'], true)) {
                return new TreasuryPaymentRefundAvailability;
            }
            $this->assertSubmissions($state['submissions']);
            $this->assertLocatedOrder($orderId, $state['order'], $state['submission']);
            $state['originalTransaction'] = $this->originalApprovedPayment($state['transactions']);
            $this->assertPaymentMatchesOrder($state);
            $refunds = $state['refunds'];
            $submissionStatus = match ($refunds->first()?->status) {
                PaymentRefund::COMPLETED => PaymentSubmission::REFUNDED,
                PaymentRefund::PENDING => PaymentSubmission::REFUND_PENDING,
                default => $state['order']->payment_status === 'refunded' ? PaymentSubmission::REFUNDED : PaymentSubmission::APPROVED,
            };
            $this->assertSubmissionStructure($state, $submissionStatus);
            // A: financial integrity is independent of cancellation and compensation.
            $normalEvidence = ['consumedReservations' => 0, 'fulfillmentHistories' => collect()];
            if ($state['cases']->isNotEmpty()) {
                $normalEvidence = [
                    'consumedReservations' => InventoryReservation::query()->where('order_id', $orderId)
                        ->where('status', InventoryReservation::CONSUMED)->orderBy('id')->get()->count(),
                    'fulfillmentHistories' => OrderFulfillmentHistory::query()->where('order_id', $orderId)->orderBy('id')->get(),
                ];
            }
            $this->assertRefundFinancialIntegrity($state, $normalEvidence, $submissionStatus);

            // B: normal business exclusions never require compensated inventory.
            if ($state['order']->status !== 'canceled'
                || $state['order']->delivered_at || $state['order']->picked_up_at
                || $state['delivery']?->status === OrderDelivery::DELIVERED
                || $state['order']->payment_status === 'refunded') {
                return new TreasuryPaymentRefundAvailability;
            }
            $evidence = $this->readCompensatedInventory($state['order']);
            if ($refunds->isEmpty()) {
                $this->assertInitialFinancialState($state, $evidence);

                return new TreasuryPaymentRefundAvailability(can_mark_refund_pending: true);
            }
            if ($refunds->count() !== 1) {
                $this->conflict();
            }
            $refund = $refunds->first();
            if ($refund->status === PaymentRefund::COMPLETED) {
                $this->validatedCompletedRefund($state, $evidence, (int) $refund->id);

                return new TreasuryPaymentRefundAvailability;
            }
            $this->assertPendingCompletionFields($state);
            $this->validatedPendingRefund($state, $evidence, (int) $refund->requested_by, (string) $refund->reason);
            $this->assertRequestedAtNotFuture($refund, now()->startOfSecond());

            return new TreasuryPaymentRefundAvailability(can_complete_refund: true);
        } catch (PaymentRefundConflictException|InventoryException) {
            return new TreasuryPaymentRefundAvailability(requires_manual_review: true);
        }
    }

    private function assertRefundFinancialIntegrity(array $state, array $evidence, string $submissionStatus): void
    {
        $refunds = $state['refunds'];
        if ($refunds->isEmpty()) {
            if ($state['order']->payment_status !== 'approved'
                || $state['transactions']->contains('transaction_type', PaymentTransaction::REFUND)
                || $state['histories']->contains(fn ($history) => in_array($history->event, [PaymentSubmissionHistory::REFUND_MARKED_PENDING, PaymentSubmissionHistory::REFUND_COMPLETED], true))) {
                $this->conflict();
            }
            $this->assertSubmissionAndCase($state, $evidence, $submissionStatus);

            return;
        }
        if ($refunds->count() !== 1) {
            $this->conflict();
        }
        $refund = $refunds->first();
        if ($refund->status === PaymentRefund::COMPLETED) {
            $this->validatedCompletedRefund($state, $evidence, (int) $refund->id, requireOperationalEligibility: false);

            return;
        }
        $this->assertPendingCompletionFields($state);
        $this->validatedPendingRefund($state, $evidence, (int) $refund->requested_by, (string) $refund->reason, requireOperationalEligibility: false);
        $this->assertRequestedAtNotFuture($refund, now()->startOfSecond());
    }

    protected function readFinancialState(int $orderId): ?array
    {
        $order = Order::query()->whereKey($orderId)->first();
        if (! $order) {
            return null;
        }
        $submissions = PaymentSubmission::query()->where('order_id', $orderId)->orderBy('id')->get();
        $submission = $submissions->first();
        $transactions = PaymentTransaction::query()->where(fn ($query) => $query->where('order_id', $orderId)
            ->orWhereIn('payment_submission_id', $submissions->pluck('id')))->orderBy('id')->get();
        $cases = PaymentResolutionCase::query()->where(fn ($query) => $query
            ->whereIn('payment_submission_id', $submissions->pluck('id'))
            ->orWhereIn('received_payment_transaction_id', $transactions->pluck('id')))->orderBy('id')->get();
        $refundKeys = $transactions->map(fn ($payment) => $this->refundKey((int) $payment->id));
        $refunds = PaymentRefund::query()->where(fn ($query) => $query
            ->whereIn('idempotency_key', $refundKeys)
            ->orWhereIn('original_payment_transaction_id', $transactions->pluck('id'))
            ->orWhereIn('refund_payment_transaction_id', $transactions->pluck('id'))
            ->orWhereIn('payment_resolution_case_id', $cases->pluck('id')))->orderBy('id')->get();
        if ($refunds->isNotEmpty()) {
            $related = PaymentTransaction::query()->where(fn ($query) => $query
                ->whereIn('id', $refunds->pluck('refund_payment_transaction_id')->filter())
                ->orWhereIn('idempotency_key', $refunds->map(fn ($refund) => $this->completionKey((int) $refund->id))))->orderBy('id')->get();
            $transactions = $transactions->merge($related)->unique('id')->sortBy('id')->values();
        }
        $histories = PaymentSubmissionHistory::query()->whereIn('payment_submission_id', $submissions->pluck('id'))->orderBy('id')->get();
        $resolutionHistories = PaymentResolutionHistory::query()->whereIn('payment_resolution_case_id', $cases->pluck('id'))->orderBy('id')->get();
        $delivery = OrderDelivery::query()->where('order_id', $orderId)->orderBy('id')->first();
        $handling = OrderHandlingProcess::query()->where('order_id', $orderId)->orderBy('id')->first();

        return compact('order', 'submissions', 'submission', 'transactions', 'cases', 'refunds', 'histories', 'resolutionHistories', 'delivery', 'handling');
    }

    private function readCompensatedInventory(Order $order): array
    {
        // Locate products, then reload evidence in deterministic order without locks.
        $locatedProducts = OrderItem::query()->where('order_id', $order->id)->pluck('product_id');
        $globalItems = OrderItem::query()->where(fn ($query) => $query
            ->where('order_id', $order->id)->orWhereIn('product_id', $locatedProducts))
            ->orderBy('id')->get();
        $items = $globalItems->where('order_id', $order->id)->values();
        $this->assertInventoryItems($items);

        $requiredPairs = $items->map(fn (OrderItem $item) => [
            'warehouse_id' => (int) $item->warehouse_id,
            'product_id' => (int) $item->product_id,
        ]);
        $productIds = $items->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $this->assertLocatedProducts($productIds, $locatedProducts);
        $allPairs = WarehouseInventory::query()->whereIn('product_id', $productIds)->get(['warehouse_id', 'product_id'])
            ->map(fn (WarehouseInventory $inventory) => ['warehouse_id' => (int) $inventory->warehouse_id, 'product_id' => (int) $inventory->product_id]);
        $balancePairs = $this->balanceLocks->canonicalPairs($allPairs->concat($requiredPairs));
        $reservations = InventoryReservation::query()
            ->where(function ($query) use ($order, $items, $balancePairs) {
                $query->where('order_id', $order->id)->orWhereIn('order_item_id', $items->pluck('id'));
                foreach ($balancePairs as $pair) {
                    $query->orWhere(fn ($candidate) => $candidate
                        ->where('status', InventoryReservation::ACTIVE)
                        ->where('warehouse_id', $pair['warehouse_id'])
                        ->where('product_id', $pair['product_id']));
                }
            })
            ->orderBy('id')->get();
        $globalActive = $reservations->where('status', InventoryReservation::ACTIVE)->values();
        $this->assertGlobalActiveReservations($globalActive, $globalItems);

        $warehouseIds = collect($balancePairs)->pluck('warehouse_id')->unique()->sort()->values();
        if (Warehouse::query()->whereIn('id', $warehouseIds)->orderBy('id')->get()->count() !== $warehouseIds->count()) {
            $this->conflict();
        }

        $inventories = WarehouseInventory::query()->where(function ($query) use ($balancePairs) {
            foreach ($balancePairs as $pair) {
                $query->orWhere(fn ($candidate) => $candidate->where('warehouse_id', $pair['warehouse_id'])->where('product_id', $pair['product_id']));
            }
        })->orderBy('warehouse_id')->orderBy('product_id')->orderBy('id')->get();

        $this->assertPhysicalBalances($inventories, $balancePairs);
        $products = Product::query()->whereIn('id', $productIds)->orderBy('id')->get();
        if ($products->count() !== $productIds->count()) {
            $this->conflict();
        }
        $histories = OrderFulfillmentHistory::query()->where('order_id', $order->id)->orderBy('id')->get();
        $movements = InventoryMovement::query()->where('reference_type', 'order')->where('reference_id', (string) $order->id)->orderBy('id')->get();

        $this->assertReservedBalances($inventories, $globalActive);
        $consumedReservations = $this->assertCompensatedEvidence($order, $items, $reservations, $movements, $inventories, $products, $histories);

        return [
            'consumedReservations' => $consumedReservations,
            'fulfillmentHistories' => $histories,
        ];
    }
}
