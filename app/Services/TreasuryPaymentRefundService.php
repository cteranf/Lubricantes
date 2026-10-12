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
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TreasuryPaymentRefundService
{
    private const MAX_REASON_LENGTH = 1000;

    private const HISTORY_METADATA_KEYS = ['amount', 'currency'];

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
            });
        }
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
    private function lockFinancialState(int $orderId, int $actorId): array
    {
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
        $actor = User::query()->whereKey($actorId)->first();
        if (! $order || ! $actor || ! $actor->is_active || ! $actor->isTreasury()) {
            $this->conflict();
        }

        $transactions = PaymentTransaction::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        $approvedPayments = $transactions
            ->where('transaction_type', PaymentTransaction::PAYMENT)
            ->where('status', PaymentTransaction::APPROVED)
            ->values();
        if ($approvedPayments->count() !== 1) {
            $this->conflict();
        }
        $originalTransaction = $approvedPayments->first();

        $submissions = PaymentSubmission::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        if ($submissions->count() > 1) {
            $this->conflict();
        }
        $submission = $submissions->first();

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
            ->where(function ($query) use ($transactions, $cases, $refundKey) {
                $query->where('idempotency_key', $refundKey);
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
        $delivery = OrderDelivery::query()->where('order_id', $order->id)->lockForUpdate()->first();
        $handling = OrderHandlingProcess::query()->where('order_id', $order->id)->lockForUpdate()->first();

        return compact('order', 'actor', 'transactions', 'originalTransaction', 'submission', 'submissions', 'cases', 'refunds', 'histories', 'resolutionHistories', 'delivery', 'handling');
    }

    /** @param array<string, mixed> $state */
    private function assertInitialFinancialState(array $state, array $inventoryEvidence): void
    {
        $this->assertOrderAndPayment($state, 'approved');
        $this->assertSubmissionAndCase($state, $inventoryEvidence, PaymentSubmission::APPROVED);

        if ($state['histories']->contains('event', PaymentSubmissionHistory::REFUND_MARKED_PENDING)) {
            $this->conflict();
        }
    }

    /** @param array<string, mixed> $state */
    private function assertOrderAndPayment(array $state, string $paymentStatus): void
    {
        $order = $state['order'];
        $transaction = $state['originalTransaction'];
        if ($order->status !== 'canceled'
            || $order->payment_status !== $paymentStatus
            || ! $order->paid_at
            || $order->tracking_status !== 'canceled'
            || $order->effectiveFulfillmentStatus() !== Order::FULFILLMENT_CANCELED
            || $order->delivered_at
            || $order->picked_up_at
            || $state['delivery']?->status === OrderDelivery::DELIVERED
            || ($state['delivery'] && $state['delivery']->status !== OrderDelivery::CANCELED)
            || ($state['handling'] && ! $state['handling']->canceled_at)
            || $transaction->transaction_type !== PaymentTransaction::PAYMENT
            || $transaction->status !== PaymentTransaction::APPROVED
            || (int) $transaction->order_id !== (int) $order->id
            || $transaction->payment_method !== $order->payment_method
            || $transaction->currency !== 'PEN'
            || $this->minor($transaction->amount) !== $this->minor($order->total)
            || ! $transaction->confirmed_at
            || $transaction->approved_scope_key !== PaymentTransaction::approvedScopeKeyForOrder($order->id)
            || $state['transactions']->contains(fn (PaymentTransaction $candidate) => $candidate->transaction_type === PaymentTransaction::REFUND)) {
            $this->conflict();
        }
    }

    /** @param array<string, mixed> $state */
    private function assertSubmissionAndCase(array $state, array $inventoryEvidence, string $submissionStatus): void
    {
        $transaction = $state['originalTransaction'];
        $submission = $state['submission'];
        if ($transaction->payment_submission_id === null) {
            if ($submission || $state['cases']->isNotEmpty()) {
                $this->conflict();
            }

            return;
        }

        if (! $submission
            || (int) $transaction->payment_submission_id !== (int) $submission->id
            || (int) $submission->order_id !== (int) $state['order']->id
            || $submission->status !== $submissionStatus
            || $submission->currency !== $transaction->currency
            || $this->minor($submission->expected_amount) !== $this->minor($transaction->amount)
            || $state['cases']->count() > 1) {
            $this->conflict();
        }

        if ($state['cases']->isNotEmpty()) {
            $case = $state['cases']->first();
            if ((int) $case->payment_submission_id !== (int) $submission->id
                || (int) $case->received_payment_transaction_id !== (int) $transaction->id
                || $case->type !== PaymentResolutionCase::STOCK_REASSIGNMENT
                || $case->status !== PaymentResolutionCase::REASSIGNED
                || $this->minor($case->amount) !== $this->minor($transaction->amount)
                || $case->currency !== $transaction->currency) {
                $this->conflict();
            }

            if (! PaymentReassignmentFinalState::isCoherent(
                $state['order'],
                $submission,
                $transaction,
                $case,
                $state['histories'],
                $state['resolutionHistories'],
                $inventoryEvidence['fulfillmentHistories'],
                $inventoryEvidence['consumedReservations'],
            )) {
                $this->conflict();
            }
        }
    }

    /** Global inventory locks: item, reservation, warehouse, balance, product, history. */
    private function lockAndValidateCompensatedInventory(Order $order): array
    {
        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        if ($items->isEmpty() || $items->contains(fn (OrderItem $item) => ! $item->warehouse_id || ! $item->product_id || (int) $item->quantity < 1)) {
            $this->conflict();
        }

        $requiredPairs = $items->map(fn (OrderItem $item) => [
            'warehouse_id' => (int) $item->warehouse_id,
            'product_id' => (int) $item->product_id,
        ]);
        $reservations = InventoryReservation::query()
            ->where(function ($query) use ($order, $items, $requiredPairs) {
                $query->where('order_id', $order->id)->orWhereIn('order_item_id', $items->pluck('id'));
                foreach ($requiredPairs as $pair) {
                    $query->orWhere(fn ($candidate) => $candidate
                        ->where('warehouse_id', $pair['warehouse_id'])
                        ->where('product_id', $pair['product_id']));
                }
            })
            ->orderBy('warehouse_id')->orderBy('product_id')->orderBy('id')->lockForUpdate()->get();

        $warehouseIds = $items->pluck('warehouse_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
        if (Warehouse::query()->whereIn('id', $warehouseIds)->orderBy('id')->lockForUpdate()->get()->count() !== $warehouseIds->count()) {
            $this->conflict();
        }

        $productIds = $items->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $allPairs = WarehouseInventory::query()->whereIn('product_id', $productIds)->get(['warehouse_id', 'product_id'])
            ->map(fn (WarehouseInventory $inventory) => ['warehouse_id' => (int) $inventory->warehouse_id, 'product_id' => (int) $inventory->product_id]);
        try {
            $inventories = $this->balanceLocks->lock($allPairs->concat($requiredPairs));
        } catch (InventoryException $exception) {
            $this->conflict($exception);
        }

        $products = Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
        if ($products->count() !== $productIds->count()) {
            $this->conflict();
        }
        $histories = OrderFulfillmentHistory::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        $movements = InventoryMovement::query()->where('reference_type', 'order')->where('reference_id', (string) $order->id)->orderBy('id')->lockForUpdate()->get();

        $consumedReservations = $this->assertCompensatedEvidence($order, $items, $reservations, $movements, $inventories, $products, $histories);

        return [
            'consumedReservations' => $consumedReservations,
            'fulfillmentHistories' => $histories,
        ];
    }

    private function assertCompensatedEvidence(Order $order, Collection $items, Collection $reservations, Collection $movements, Collection $inventories, Collection $products, Collection $histories): int
    {
        $itemIds = $items->pluck('id')->map(fn ($id) => (int) $id);
        $linkedReservations = $reservations->filter(fn (InventoryReservation $reservation) => (int) $reservation->order_id === (int) $order->id
            || $itemIds->contains((int) $reservation->order_item_id)
        )->values();
        $orderReservations = $linkedReservations->where('order_id', $order->id)->values();
        if ($linkedReservations->count() !== $orderReservations->count()) {
            $this->conflict();
        }
        if ($orderReservations->contains('status', InventoryReservation::ACTIVE)) {
            $this->conflict();
        }

        $itemsById = $items->keyBy('id');
        foreach ($orderReservations as $reservation) {
            $item = $itemsById->get($reservation->order_item_id);
            if (! $item
                || (int) $reservation->order_id !== (int) $order->id
                || (int) $item->order_id !== (int) $order->id
                || (int) $reservation->product_id !== (int) $item->product_id
                || (int) $reservation->warehouse_id !== (int) $item->warehouse_id
                || (int) $reservation->quantity !== (int) $item->quantity
                || (int) $reservation->reservation_sequence < 1
                || ! in_array($reservation->status, [InventoryReservation::CONSUMED, InventoryReservation::RELEASED, InventoryReservation::EXPIRED], true)
                || ($reservation->status === InventoryReservation::CONSUMED && (! $reservation->consumed_at || $reservation->released_at))
                || (in_array($reservation->status, [InventoryReservation::RELEASED, InventoryReservation::EXPIRED], true) && ! $reservation->released_at)) {
                $this->conflict();
            }
        }
        if ($orderReservations->isNotEmpty() && $items->contains(function (OrderItem $item) use ($orderReservations): bool {
            $versions = $orderReservations->where('order_item_id', $item->id)->values();
            $sequences = $versions->pluck('reservation_sequence')->map(fn ($sequence) => (int) $sequence)->sort()->values();

            return $versions->isEmpty()
                || $sequences->all() !== range(1, $sequences->count())
                || $versions->where('status', InventoryReservation::CONSUMED)->count() > 1;
        })) {
            $this->conflict();
        }
        $currentStatuses = $items->map(fn (OrderItem $item) => $orderReservations
            ->where('order_item_id', $item->id)
            ->sortByDesc(fn (InventoryReservation $reservation) => [(int) $reservation->reservation_sequence, (int) $reservation->id])
            ->first()?->status)->filter()->unique()->values();
        if ($orderReservations->isNotEmpty() && $currentStatuses->count() !== 1) {
            $this->conflict();
        }

        $canceled = $histories->where('to_status', Order::FULFILLMENT_CANCELED);
        if ($canceled->count() !== 1 || $canceled->first()->idempotency_key !== 'fulfillment-order-'.$order->id.'-canceled') {
            $this->conflict();
        }

        $expectedKeys = collect();
        foreach ($items as $item) {
            $itemReservations = $orderReservations->where('order_item_id', $item->id)->values();
            $wasConsumed = $orderReservations->isEmpty()
                || $itemReservations->where('status', InventoryReservation::CONSUMED)->count() === 1;
            $returns = $movements->where('idempotency_key', 'cancellation-order-item-'.$item->id);
            $sales = $movements->where('idempotency_key', 'sale-order-item-'.$item->id);
            if ($wasConsumed) {
                if ($returns->count() !== 1 || $sales->count() !== 1
                    || ! $this->coherentMovement($returns->first(), $item, InventoryMovement::CANCELLATION_RETURN)
                    || ! $this->coherentMovement($sales->first(), $item, InventoryMovement::SALE)) {
                    $this->conflict();
                }
                $expectedKeys->push('sale-order-item-'.$item->id, 'cancellation-order-item-'.$item->id);
            } elseif ($returns->isNotEmpty()) {
                $this->conflict();
            }
        }

        $actualKeys = $movements->whereIn('type', [InventoryMovement::SALE, InventoryMovement::CANCELLATION_RETURN])->pluck('idempotency_key')->sort()->values();
        if ($actualKeys->all() !== $expectedKeys->sort()->values()->all()) {
            $this->conflict();
        }

        foreach ($inventories as $inventory) {
            $reserved = (int) $reservations->where('warehouse_id', $inventory->warehouse_id)
                ->where('product_id', $inventory->product_id)->where('status', InventoryReservation::ACTIVE)->sum('quantity');
            if ((int) $inventory->reserved_quantity !== $reserved) {
                $this->conflict();
            }
        }
        foreach ($products as $product) {
            if ((int) $product->stock !== (int) $inventories->where('product_id', $product->id)->sum('quantity')) {
                $this->conflict();
            }
        }

        return $orderReservations->where('status', InventoryReservation::CONSUMED)->count();
    }

    private function coherentMovement(InventoryMovement $movement, OrderItem $item, string $type): bool
    {
        $expectedAfter = $type === InventoryMovement::SALE
            ? (int) $movement->quantity_before - (int) $movement->quantity
            : (int) $movement->quantity_before + (int) $movement->quantity;

        return $movement->type === $type
            && (int) $movement->warehouse_id === (int) $item->warehouse_id
            && (int) $movement->product_id === (int) $item->product_id
            && (int) $movement->quantity === (int) $item->quantity
            && $movement->reference_type === 'order'
            && (int) $movement->reference_id === (int) $item->order_id
            && $expectedAfter === (int) $movement->quantity_after;
    }

    /** @param array<string, mixed> $state */
    private function coherentExistingRefund(array $state, array $inventoryEvidence, int $actorId, string $reason): PaymentRefund
    {
        $this->assertOrderAndPayment($state, 'refund_pending');
        $this->assertSubmissionAndCase($state, $inventoryEvidence, $state['submission'] ? PaymentSubmission::REFUND_PENDING : PaymentSubmission::APPROVED);
        if ($state['refunds']->count() !== 1) {
            $this->conflict();
        }

        $refund = $state['refunds']->first();
        $transaction = $state['originalTransaction'];
        if ((int) $refund->original_payment_transaction_id !== (int) $transaction->id
            || $refund->payment_resolution_case_id !== null
            || $refund->refund_payment_transaction_id !== null
            || $refund->status !== PaymentRefund::PENDING
            || $this->minor($refund->amount) !== $this->minor($transaction->amount)
            || $refund->currency !== $transaction->currency
            || $refund->reason !== $reason
            || (int) $refund->requested_by !== $actorId
            || ! $refund->requested_at
            || $refund->completed_by !== null
            || $refund->completed_at !== null
            || $refund->failed_at !== null
            || $refund->external_reference !== null
            || $refund->idempotency_key !== $this->refundKey((int) $transaction->id)) {
            $this->conflict();
        }

        if ($state['submission']) {
            $events = $state['histories']->where('event', PaymentSubmissionHistory::REFUND_MARKED_PENDING)->values();
            $history = $events->first();
            if ($events->count() !== 1
                || $history->from_status !== PaymentSubmission::APPROVED
                || $history->to_status !== PaymentSubmission::REFUND_PENDING
                || (int) $history->actor_id !== $actorId
                || $history->reason !== $reason
                || ! $history->occurred_at
                || ! $history->occurred_at->equalTo($refund->requested_at)
                || ! $this->hasExpectedHistoryMetadata($history->safe_metadata, $transaction)) {
                $this->conflict();
            }
        } elseif ($state['histories']->isNotEmpty()) {
            $this->conflict();
        }

        return $refund->fresh(['originalPaymentTransaction', 'resolutionCase', 'refundPaymentTransaction']);
    }

    private function refundKey(int $transactionId): string
    {
        return 'treasury-total-refund-payment-'.$transactionId;
    }

    /** @param array<string, mixed>|null $metadata */
    private function hasExpectedHistoryMetadata(?array $metadata, PaymentTransaction $transaction): bool
    {
        if ($metadata === null) {
            return false;
        }

        $keys = array_keys($metadata);
        sort($keys);
        $expectedKeys = self::HISTORY_METADATA_KEYS;
        sort($expectedKeys);

        return $keys === $expectedKeys
            && is_string($metadata['amount'] ?? null)
            && $this->minor($metadata['amount']) === $this->minor($transaction->amount)
            && ($metadata['currency'] ?? null) === $transaction->currency;
    }

    private function minor(mixed $amount): int
    {
        $amount = (string) $amount;
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $amount)) {
            $this->conflict();
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    /** @param array<string, mixed> $state */
    protected function afterFinancialStateLocked(array $state): void {}

    protected function afterRefundCreated(PaymentRefund $refund): void {}

    protected function afterOrderMarkedRefundPending(Order $order): void {}

    protected function afterSubmissionHistoryCreated(PaymentSubmission $submission): void {}

    private function conflict(?\Throwable $previous = null): never
    {
        throw new PaymentRefundConflictException($previous);
    }
}
