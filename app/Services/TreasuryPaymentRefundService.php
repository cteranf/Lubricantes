<?php

namespace App\Services;

use App\Exceptions\InventoryException;
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
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class TreasuryPaymentRefundService
{
    use TreasuryPaymentRefundPredicates;

    public function __construct(
        private InventoryBalanceLockService $balanceLocks,
        private TreasuryPaymentService $treasury,
    ) {}

    public function markPending(Order $order, User $actor, string $reason): PaymentRefund
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > self::MAX_REASON_LENGTH || ! $order->exists || ! $actor->exists) {
            $this->conflict();
        }

        $orderId = (int) $order->id;
        $actorId = (int) $actor->id;

        try {
            return DB::transaction(
                fn (): PaymentRefund => $this->markPendingLocked($orderId, $actorId, $reason),
                3
            );
        } catch (QueryException $exception) {
            if (! $this->treasury->isUniqueViolation($exception)) {
                throw $exception;
            }

            return DB::transaction(function () use ($orderId, $actorId, $reason): PaymentRefund {
                $state = $this->lockFinancialState($orderId, $actorId);
                $inventoryEvidence = $this->lockAndValidateCompensatedInventory($state['order']);

                return $this->coherentExistingRefund($state, $inventoryEvidence, $actorId, $reason);
            }, 3);
        }
    }

    public function complete(int $refundId, int $actorId, string $externalReference): PaymentRefund
    {
        if ($refundId < 1 || $actorId < 1) {
            $this->conflict();
        }
        $reference = $this->normalizeCompletionReference($externalReference);

        try {
            return DB::transaction(fn () => $this->completeWithinTransaction($refundId, $actorId, $reference), 3);
        } catch (QueryException $exception) {
            if (! $this->treasury->isUniqueViolation($exception)) {
                throw $exception;
            }

            // Recovery is read-only and starts only after the complete rollback.
            return DB::transaction(function () use ($refundId, $actorId, $reference): PaymentRefund {
                [$state, $evidence] = $this->lockCompletionState($refundId, $actorId);

                return $this->coherentCompletedRefund($state, $evidence, $refundId, $reference);
            }, 3);
        }
    }

    private function lockCompletionState(int $refundId, int $actorId): array
    {
        // These unlocked reads locate identifiers only; every identity is checked
        // again against freshly locked rows, including occupied foreign keys.
        $locator = DB::table('payment_refunds as refunds')
            ->join('payment_transactions as payments', 'payments.id', '=', 'refunds.original_payment_transaction_id')
            ->where('refunds.id', $refundId)->first(['payments.order_id', 'refunds.original_payment_transaction_id', 'refunds.refund_payment_transaction_id']);
        if (! $locator) {
            $this->conflict();
        }
        $state = $this->lockFinancialState((int) $locator->order_id, $actorId, $refundId, $locator->refund_payment_transaction_id === null ? null : (int) $locator->refund_payment_transaction_id);
        $refund = $state['refunds']->firstWhere('id', $refundId);
        if ($state['refunds']->count() !== 1 || ! $refund
            || (int) $refund->original_payment_transaction_id !== (int) $locator->original_payment_transaction_id
            || (int) $refund->original_payment_transaction_id !== (int) $state['originalTransaction']->id) {
            $this->conflict();
        }
        $this->afterCompletionFinancialStateLocked($state);

        return [$state, $this->lockAndValidateCompensatedInventory($state['order'])];
    }

    private function completeWithinTransaction(int $refundId, int $actorId, string $reference): PaymentRefund
    {
        [$state, $evidence] = $this->lockCompletionState($refundId, $actorId);
        $refund = $state['refunds']->first();
        if ($refund->status === PaymentRefund::COMPLETED) {
            return $this->coherentCompletedRefund($state, $evidence, $refundId, $reference);
        }
        $this->assertPendingCompletionFields($state);
        $this->coherentExistingRefund($state, $evidence, (int) $refund->requested_by, $refund->reason);
        $original = $state['originalTransaction'];
        $now = now()->startOfSecond();
        $this->assertRequestedAtNotFuture($refund, $now);
        $refund->external_reference = $reference;
        if (strlen($refund->getAttributes()['external_reference']) > 65535) {
            $this->conflict();
        }

        $accounting = PaymentTransaction::forceCreate([
            'order_id' => $state['order']->id,
            'payment_method' => $original->payment_method,
            'gateway' => 'manual',
            'transaction_type' => PaymentTransaction::REFUND,
            'status' => PaymentTransaction::APPROVED,
            'amount' => (string) $original->amount,
            'currency' => $original->currency,
            'confirmed_by' => $actorId,
            'confirmed_at' => $now,
            'idempotency_key' => $this->completionKey($refundId),
            'approved_scope_key' => null,
            'payment_submission_id' => null,
        ]);
        $this->afterRefundAccountingCreated($accounting);
        $refund->forceFill([
            'refund_payment_transaction_id' => $accounting->id,
            'status' => PaymentRefund::COMPLETED,
            'completed_by' => $actorId,
            'completed_at' => $now,
        ])->save();
        $this->afterRefundCompleted($refund);
        $state['order']->update(['payment_status' => 'refunded']);
        $this->afterOrderRefunded($state['order']);
        if ($state['submission']) {
            $state['submission']->update(['status' => PaymentSubmission::REFUNDED]);
            $this->afterSubmissionRefunded($state['submission']);
            PaymentSubmissionHistory::create([
                'payment_submission_id' => $state['submission']->id,
                'event' => PaymentSubmissionHistory::REFUND_COMPLETED,
                'from_status' => PaymentSubmission::REFUND_PENDING,
                'to_status' => PaymentSubmission::REFUNDED,
                'actor_id' => $actorId,
                'occurred_at' => $now,
                'reason' => self::COMPLETION_REASON,
                'safe_metadata' => ['amount' => (string) $original->amount, 'currency' => $original->currency],
            ]);
            $this->afterRefundCompletionHistoryCreated($state['submission']);
        }

        return $refund->unsetRelations();
    }

    private function coherentCompletedRefund(array $state, array $evidence, int $refundId, string $reference): PaymentRefund
    {
        return $this->validatedCompletedRefund($state, $evidence, $refundId, $reference)->unsetRelations();
    }

    private function markPendingLocked(int $orderId, int $actorId, string $reason): PaymentRefund
    {
        $state = $this->lockFinancialState($orderId, $actorId);
        $this->afterFinancialStateLocked($state);
        $inventoryEvidence = $this->lockAndValidateCompensatedInventory($state['order']);

        if ($state['order']->payment_status === 'refund_pending') {
            return $this->coherentExistingRefund($state, $inventoryEvidence, $actorId, $reason);
        }

        $this->assertInitialFinancialState($state, $inventoryEvidence);
        if ($state['refunds']->isNotEmpty()) {
            $this->conflict();
        }

        $transaction = $state['originalTransaction'];
        $now = now();
        $refund = PaymentRefund::forceCreate([
            'payment_resolution_case_id' => null,
            'original_payment_transaction_id' => $transaction->id,
            'refund_payment_transaction_id' => null,
            'amount' => (string) $transaction->amount,
            'currency' => $transaction->currency,
            'status' => PaymentRefund::PENDING,
            'reason' => $reason,
            'external_reference' => null,
            'requested_by' => $actorId,
            'completed_by' => null,
            'requested_at' => $now,
            'completed_at' => null,
            'failed_at' => null,
            'idempotency_key' => $this->refundKey((int) $transaction->id),
        ]);
        $this->afterRefundCreated($refund);

        $state['order']->update(['payment_status' => 'refund_pending']);
        $this->afterOrderMarkedRefundPending($state['order']);

        if ($state['submission']) {
            $state['submission']->update(['status' => PaymentSubmission::REFUND_PENDING]);
            PaymentSubmissionHistory::create([
                'payment_submission_id' => $state['submission']->id,
                'event' => PaymentSubmissionHistory::REFUND_MARKED_PENDING,
                'from_status' => PaymentSubmission::APPROVED,
                'to_status' => PaymentSubmission::REFUND_PENDING,
                'actor_id' => $actorId,
                'reason' => $reason,
                'safe_metadata' => [
                    'amount' => (string) $transaction->amount,
                    'currency' => $transaction->currency,
                ],
                'occurred_at' => $now,
            ]);
            $this->afterSubmissionHistoryCreated($state['submission']);
        }

        return $refund->fresh(['originalPaymentTransaction', 'resolutionCase', 'refundPaymentTransaction']);
    }

    /**
     * Canonical financial locks precede the inventory hierarchy.
     *
     * @return array<string, mixed>
     */
    private function lockFinancialState(int $orderId, int $actorId, ?int $completionRefundId = null, ?int $accountingId = null): array
    {
        $submissions = PaymentSubmission::query()->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get();
        $this->assertSubmissions($submissions);
        $submission = $submissions->first();
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
        $this->assertLocatedOrder($orderId, $order, $submission);
        if ($completionRefundId !== null) {
            $this->afterCompletionOrderLocked($order);
        }

        $transactions = PaymentTransaction::query()->where(function ($query) use ($orderId, $completionRefundId, $accountingId) {
            $query->where('order_id', $orderId);
            if ($completionRefundId !== null) {
                $query->orWhere('idempotency_key', $this->completionKey($completionRefundId));
            }
            if ($accountingId !== null) {
                $query->orWhere('id', $accountingId);
            }
        })->orderBy('id')->lockForUpdate()->get();
        $originalTransaction = $this->originalApprovedPayment($transactions);

        $cases = PaymentResolutionCase::query()
            ->where(function ($query) use ($submissions, $transactions) {
                if ($submissions->isNotEmpty()) {
                    $query->whereIn('payment_submission_id', $submissions->pluck('id'));
                }
                if ($transactions->isNotEmpty()) {
                    $method = $submissions->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('received_payment_transaction_id', $transactions->pluck('id'));
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $refundKey = $this->refundKey((int) $originalTransaction->id);
        $refunds = PaymentRefund::query()
            ->where(function ($query) use ($transactions, $cases, $refundKey, $completionRefundId) {
                $query->where('idempotency_key', $refundKey);
                if ($completionRefundId !== null) {
                    $query->orWhere('id', $completionRefundId);
                }
                if ($transactions->isNotEmpty()) {
                    $query->orWhereIn('original_payment_transaction_id', $transactions->pluck('id'))
                        ->orWhereIn('refund_payment_transaction_id', $transactions->pluck('id'));
                }
                if ($cases->isNotEmpty()) {
                    $query->orWhereIn('payment_resolution_case_id', $cases->pluck('id'));
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $histories = $submission
            ? PaymentSubmissionHistory::query()->where('payment_submission_id', $submission->id)->orderBy('id')->lockForUpdate()->get()
            : collect();
        $resolutionHistories = $cases->isNotEmpty()
            ? PaymentResolutionHistory::query()->whereIn('payment_resolution_case_id', $cases->pluck('id'))->orderBy('id')->lockForUpdate()->get()
            : collect();
        $actor = User::query()->whereKey($actorId)->lockForUpdate()->first();
        if (! $actor || ! $actor->is_active || ! $actor->isTreasury()) {
            $this->conflict();
        }
        $delivery = OrderDelivery::query()->where('order_id', $order->id)->lockForUpdate()->first();
        $handling = OrderHandlingProcess::query()->where('order_id', $order->id)->lockForUpdate()->first();

        return compact('order', 'actor', 'transactions', 'originalTransaction', 'submission', 'submissions', 'cases', 'refunds', 'histories', 'resolutionHistories', 'delivery', 'handling');
    }

    /** Global inventory locks: item, reservation, warehouse, balance, product, history. */
    private function lockAndValidateCompensatedInventory(Order $order): array
    {
        // Locate products only; all item evidence is then read under ordered locks.
        $locatedProducts = OrderItem::query()->where('order_id', $order->id)->pluck('product_id');
        $globalItems = OrderItem::query()->where(fn ($query) => $query
            ->where('order_id', $order->id)->orWhereIn('product_id', $locatedProducts))
            ->orderBy('id')->lockForUpdate()->get();
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
            ->orderBy('id')->lockForUpdate()->get();
        $globalActive = $reservations->where('status', InventoryReservation::ACTIVE)->values();
        $this->assertGlobalActiveReservations($globalActive, $globalItems);

        $warehouseIds = collect($balancePairs)->pluck('warehouse_id')->unique()->sort()->values();
        if (Warehouse::query()->whereIn('id', $warehouseIds)->orderBy('id')->lockForUpdate()->get()->count() !== $warehouseIds->count()) {
            $this->conflict();
        }

        try {
            $inventories = $this->balanceLocks->lock($balancePairs);
        } catch (InventoryException $exception) {
            $this->conflict($exception);
        }

        $this->assertPhysicalBalances($inventories, $balancePairs);
        $products = Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
        if ($products->count() !== $productIds->count()) {
            $this->conflict();
        }
        $histories = OrderFulfillmentHistory::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        $movements = InventoryMovement::query()->where('reference_type', 'order')->where('reference_id', (string) $order->id)->orderBy('id')->lockForUpdate()->get();

        $this->assertReservedBalances($inventories, $globalActive);
        $consumedReservations = $this->assertCompensatedEvidence($order, $items, $reservations, $movements, $inventories, $products, $histories);

        return [
            'consumedReservations' => $consumedReservations,
            'fulfillmentHistories' => $histories,
        ];
    }

    /** @param array<string, mixed> $state */
    private function coherentExistingRefund(array $state, array $inventoryEvidence, int $actorId, string $reason): PaymentRefund
    {
        return $this->validatedPendingRefund($state, $inventoryEvidence, $actorId, $reason)
            ->fresh(['originalPaymentTransaction', 'resolutionCase', 'refundPaymentTransaction']);
    }

    /** @param array<string, mixed> $state */
    protected function afterFinancialStateLocked(array $state): void {}

    protected function afterRefundCreated(PaymentRefund $refund): void {}

    protected function afterOrderMarkedRefundPending(Order $order): void {}

    protected function afterSubmissionHistoryCreated(PaymentSubmission $submission): void {}

    protected function afterCompletionOrderLocked(Order $order): void {}

    protected function afterCompletionFinancialStateLocked(array $state): void {}

    protected function afterRefundAccountingCreated(PaymentTransaction $transaction): void {}

    protected function afterRefundCompleted(PaymentRefund $refund): void {}

    protected function afterOrderRefunded(Order $order): void {}

    protected function afterSubmissionRefunded(PaymentSubmission $submission): void {}

    protected function afterRefundCompletionHistoryCreated(PaymentSubmission $submission): void {}
}
