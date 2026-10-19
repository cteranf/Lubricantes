<?php

namespace App\Services;

use App\Exceptions\PaymentRefundConflictException;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderItem;
use App\Models\PaymentRefund;
use App\Models\PaymentResolutionCase;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use Illuminate\Support\Collection;

/** Pure semantic checks over already acquired evidence. No queries, locks or writes. */
trait TreasuryPaymentRefundPredicates
{
    private const MAX_REASON_LENGTH = 1000;

    private const HISTORY_METADATA_KEYS = ['amount', 'currency'];

    private const COMPLETION_REASON = 'total_refund_completed';

    private function normalizeCompletionReference(string $reference): string
    {
        if (! mb_check_encoding($reference, 'UTF-8') || preg_match('/[\p{C}\p{Zl}\p{Zp}]/u', $reference)) {
            $this->conflict();
        }
        $reference = preg_replace('/^\p{Zs}+|\p{Zs}+$/u', '', $reference);
        if ($reference === '' || strlen($reference) > 255) {
            $this->conflict();
        }

        return $reference;
    }

    private function completionKey(int $refundId): string
    {
        return hash('sha256', "treasury-total-refund-completion:v1\0".$refundId);
    }

    private function assertInitialFinancialState(array $state, array $inventoryEvidence): void
    {
        $this->assertOrderAndPayment($state, 'approved');
        $this->assertSubmissionAndCase($state, $inventoryEvidence, PaymentSubmission::APPROVED);

        if ($state['histories']->contains('event', PaymentSubmissionHistory::REFUND_MARKED_PENDING)) {
            $this->conflict();
        }
    }

    private function assertOrderAndPayment(array $state, string $paymentStatus, bool $allowCompletedRefund = false, bool $requireOperationalEligibility = true): void
    {
        $this->assertPaymentMatchesOrder($state);
        if (($requireOperationalEligibility && ! $this->isCanceledUndeliveredOrder($state))
            || $state['order']->payment_status !== $paymentStatus
            || (! $allowCompletedRefund && $state['transactions']->contains(fn (PaymentTransaction $candidate) => $candidate->transaction_type === PaymentTransaction::REFUND))) {
            $this->conflict();
        }
    }

    private function assertSubmissionAndCase(array $state, array $inventoryEvidence, string $submissionStatus, bool $forRefundCompletion = false): void
    {
        $this->assertSubmissionStructure($state, $submissionStatus);
        $transaction = $state['originalTransaction'];
        $submission = $state['submission'];
        if ($state['cases']->isNotEmpty()) {
            $case = $state['cases']->first();
            if (! PaymentReassignmentFinalState::isCoherent(
                $state['order'],
                $submission,
                $transaction,
                $case,
                $state['histories'],
                $state['resolutionHistories'],
                $inventoryEvidence['fulfillmentHistories'],
                $inventoryEvidence['consumedReservations'],
                $forRefundCompletion,
            )) {
                $this->conflict();
            }
        }
    }

    private function assertSubmissionStructure(array $state, string $submissionStatus): void
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

        }
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

    private function assertPendingHistory(array $state, PaymentRefund $refund, int $actorId, string $reason): void
    {
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
                || ! $this->hasExpectedHistoryMetadata($history->safe_metadata, $state['originalTransaction'])) {
                $this->conflict();
            }
        } elseif ($state['histories']->isNotEmpty()) {
            $this->conflict();
        }

    }

    private function refundKey(int $transactionId): string
    {
        return 'treasury-total-refund-payment-'.$transactionId;
    }

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

    private function conflict(?\Throwable $previous = null): never
    {
        throw new PaymentRefundConflictException($previous);
    }

    private function validatedPendingRefund(array $state, array $inventoryEvidence, int $actorId, string $reason, bool $requireOperationalEligibility = true): PaymentRefund
    {
        $this->assertOrderAndPayment($state, 'refund_pending', requireOperationalEligibility: $requireOperationalEligibility);
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

        $this->assertPendingHistory($state, $refund, $actorId, $reason);

        return $refund;
    }

    private function validatedCompletedRefund(array $state, array $evidence, int $refundId, ?string $reference = null, bool $requireOperationalEligibility = true): PaymentRefund
    {
        $this->assertOrderAndPayment($state, 'refunded', true, $requireOperationalEligibility);
        $this->assertSubmissionAndCase($state, $evidence, PaymentSubmission::REFUNDED, true);
        $refund = $state['refunds']->first();
        $original = $state['originalTransaction'];
        if ($state['refunds']->count() !== 1 || ! $refund || (int) $refund->id !== $refundId
            || $refund->status !== PaymentRefund::COMPLETED
            || (int) $refund->original_payment_transaction_id !== (int) $original->id
            || $refund->payment_resolution_case_id !== null
            || $this->minor($refund->amount) < 1 || $this->minor($refund->amount) !== $this->minor($original->amount)
            || $refund->currency !== $original->currency || ! $refund->requested_by || ! $refund->requested_at
            || trim((string) $refund->reason) === '' || mb_strlen($refund->reason) > self::MAX_REASON_LENGTH
            || $refund->idempotency_key !== $this->refundKey((int) $original->id)
            || ! $refund->completed_by || ! $refund->completed_at || $refund->failed_at !== null
            || $refund->completed_at->lessThan($refund->requested_at)
            || $refund->external_reference === null
            || ($reference !== null && ! hash_equals(hash('sha256', $reference), hash('sha256', $refund->external_reference)))) {
            $this->conflict();
        }
        $refundTransactions = $state['transactions']->where('transaction_type', PaymentTransaction::REFUND)->values();
        $accounting = $refundTransactions->first();
        if ($refundTransactions->count() !== 1 || ! $accounting
            || (int) $accounting->id !== (int) $refund->refund_payment_transaction_id
            || (int) $accounting->id === (int) $original->id
            || (int) $accounting->order_id !== (int) $state['order']->id
            || $accounting->gateway !== 'manual' || $accounting->payment_method !== $original->payment_method
            || $accounting->status !== PaymentTransaction::APPROVED
            || $this->minor($accounting->amount) !== $this->minor($original->amount)
            || $accounting->currency !== $original->currency
            || $accounting->idempotency_key !== $this->completionKey($refundId)
            || (int) $accounting->confirmed_by !== (int) $refund->completed_by
            || ! $accounting->confirmed_at || ! $accounting->confirmed_at->equalTo($refund->completed_at)) {
            $this->conflict();
        }
        foreach (['approved_scope_key', 'payment_submission_id', 'external_reference', 'manual_reference', 'provider_payment_id', 'payment_preference_id', 'provider_status', 'provider_status_detail', 'verified_at', 'collection_method', 'collected_by', 'collected_at', 'failed_at', 'failure_reason', 'metadata'] as $field) {
            if ($accounting->{$field} !== null) {
                $this->conflict();
            }
        }
        $this->assertPendingHistory($state, $refund, (int) $refund->requested_by, $refund->reason);
        $events = $state['histories']->where('event', PaymentSubmissionHistory::REFUND_COMPLETED)->values();
        if ($state['submission']) {
            $history = $events->first();
            if ($events->count() !== 1 || $history->from_status !== PaymentSubmission::REFUND_PENDING
                || $history->to_status !== PaymentSubmission::REFUNDED
                || (int) $history->actor_id !== (int) $refund->completed_by
                || $history->reason !== self::COMPLETION_REASON
                || ! $history->occurred_at || ! $history->occurred_at->equalTo($refund->completed_at)
                || ! $this->hasExpectedHistoryMetadata($history->safe_metadata, $original)) {
                $this->conflict();
            }
        } elseif ($events->isNotEmpty()) {
            $this->conflict();
        }

        return $refund;
    }

    private function assertPaymentMatchesOrder(array $state): void
    {
        $order = $state['order'];
        $transaction = $state['originalTransaction'];
        if (! $order->paid_at || $transaction->transaction_type !== PaymentTransaction::PAYMENT
            || $transaction->status !== PaymentTransaction::APPROVED
            || (int) $transaction->order_id !== (int) $order->id
            || $transaction->payment_method !== $order->payment_method
            || $transaction->currency !== 'PEN'
            || $this->minor($transaction->amount) !== $this->minor($order->total)
            || ! $transaction->confirmed_at
            || $transaction->approved_scope_key !== PaymentTransaction::approvedScopeKeyForOrder($order->id)) {
            $this->conflict();
        }
    }

    private function isCanceledUndeliveredOrder(array $state): bool
    {
        $order = $state['order'];

        return $order->status === 'canceled'
            && $order->tracking_status === 'canceled'
            && $order->effectiveFulfillmentStatus() === Order::FULFILLMENT_CANCELED
            && ! $order->delivered_at && ! $order->picked_up_at
            && (! $state['delivery'] || $state['delivery']->status === OrderDelivery::CANCELED)
            && (! $state['handling'] || $state['handling']->canceled_at !== null);
    }

    private function assertSubmissions(Collection $submissions): void
    {
        if ($submissions->count() > 1) {
            $this->conflict();
        }
    }

    private function assertLocatedOrder(int $orderId, ?Order $order, ?PaymentSubmission $submission): void
    {
        if (! $order || ($submission && (int) $submission->order_id !== $orderId)) {
            $this->conflict();
        }
    }

    private function originalApprovedPayment(Collection $transactions): PaymentTransaction
    {
        $approved = $transactions->where('transaction_type', PaymentTransaction::PAYMENT)->where('status', PaymentTransaction::APPROVED)->values();
        if ($approved->count() !== 1) {
            $this->conflict();
        }

        return $approved->first();
    }

    private function assertInventoryItems(Collection $items): void
    {
        if ($items->isEmpty() || $items->contains(fn (OrderItem $item) => ! $item->warehouse_id || ! $item->product_id || (int) $item->quantity < 1)) {
            $this->conflict();
        }
    }

    private function assertLocatedProducts(Collection $productIds, Collection $locatedProducts): void
    {
        if ($productIds->diff($locatedProducts)->isNotEmpty()) {
            $this->conflict();
        }
    }

    private function assertGlobalActiveReservations(Collection $globalActive, Collection $globalItems): void
    {
        $globalItemsById = $globalItems->keyBy('id');
        foreach ($globalActive as $reservation) {
            $item = $globalItemsById->get($reservation->order_item_id);
            if (! $item || (int) $item->order_id !== (int) $reservation->order_id
                || (int) $item->warehouse_id !== (int) $reservation->warehouse_id
                || (int) $item->product_id !== (int) $reservation->product_id
                || (int) $reservation->quantity < 1 || (int) $reservation->quantity !== (int) $item->quantity
                || (int) $reservation->reservation_sequence < 1 || ! $reservation->expires_at
                || $reservation->consumed_at !== null || $reservation->released_at !== null) {
                $this->conflict();
            }
        }
        if ($globalActive->groupBy('order_item_id')->contains(fn (Collection $versions) => $versions->count() !== 1)) {
            $this->conflict();
        }
    }

    private function assertReservedBalances(Collection $inventories, Collection $globalActive): void
    {
        foreach ($inventories as $inventory) {
            $reserved = (int) $globalActive->where('warehouse_id', $inventory->warehouse_id)
                ->where('product_id', $inventory->product_id)->sum('quantity');
            if ((int) $inventory->reserved_quantity !== $reserved) {
                $this->conflict();
            }
        }
    }

    private function assertPhysicalBalances(Collection $inventories, array $pairs): void
    {
        $keys = $inventories->map(fn ($row) => (int) $row->warehouse_id.':'.(int) $row->product_id);
        $expected = collect($pairs)->map(fn ($pair) => $pair['warehouse_id'].':'.$pair['product_id']);
        if ($keys->count() !== $expected->count() || $keys->unique()->count() !== $keys->count()
            || $keys->diff($expected)->isNotEmpty() || $expected->diff($keys)->isNotEmpty()
            || $inventories->contains(fn ($row) => (int) $row->quantity < 0 || (int) $row->reserved_quantity < 0 || (int) $row->reserved_quantity > (int) $row->quantity)) {
            $this->conflict();
        }
    }

    private function assertPendingCompletionFields(array $state): void
    {
        $refund = $state['refunds']->first();
        if (! $refund->requested_by || trim((string) $refund->reason) === '' || mb_strlen($refund->reason) > self::MAX_REASON_LENGTH
            || $this->minor($refund->amount) < 1
            || $state['histories']->contains('event', PaymentSubmissionHistory::REFUND_COMPLETED)) {
            $this->conflict();
        }
    }

    private function assertRequestedAtNotFuture(PaymentRefund $refund, \Carbon\CarbonInterface $at): void
    {
        if ($refund->requested_at->greaterThan($at)) {
            $this->conflict();
        }
    }
}
