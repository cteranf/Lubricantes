<?php

namespace App\Services;

use App\Exceptions\InsufficientVersionedInventoryStockException;
use App\Exceptions\InventoryReservationConflictException;
use App\Exceptions\PaymentReassignmentConflictException;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderFulfillmentHistory;
use App\Models\OrderHandlingProcess;
use App\Models\OrderItem;
use App\Models\PaymentRefund;
use App\Models\PaymentResolutionCase;
use App\Models\PaymentResolutionHistory;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TreasuryPaymentReassignmentService
{
    private const DECISION_REASON = 'stock_reassignment_approved';

    private const RESOLUTION_EVENT = 'reassigned';

    private const OPENING_SUBMISSION_EVENT = 'resolution_opened';

    private const OPENING_RESOLUTION_EVENT = 'opened';

    private const OPENING_REASON = 'payment_received_reservation_unavailable';

    public function __construct(private InventoryService $inventory) {}

    public function canReassignAndApprove(PaymentSubmission $submission): bool
    {
        if (! $submission->exists) {
            return false;
        }

        try {
            $currentSubmission = PaymentSubmission::query()->find($submission->id);
            if (! $currentSubmission) {
                return false;
            }

            $order = Order::query()->find($currentSubmission->order_id);
            if (! $order) {
                return false;
            }

            $state = $this->initialFinancialState($currentSubmission, $order);
            if ($this->looksFinal($currentSubmission, $order, $state['transaction'], $state['case'])) {
                return false;
            }

            $this->assertInitialEligibility($currentSubmission, $order, $state);
            $this->assertOriginalReservationProvenance($order, $this->readOriginalReservationEvidence($order));

            return true;
        } catch (PaymentReassignmentConflictException) {
            return false;
        }
    }

    public function reassignAndApprove(PaymentSubmission $submission, User $actor): PaymentResolutionCase
    {
        if (! $actor->exists || ! $actor->is_active || ! $actor->isTreasury()) {
            $this->conflict('El actor no está autorizado para resolver la reasignación.');
        }

        $submissionId = (int) $submission->id;
        $actorId = (int) $actor->id;

        return DB::transaction(function () use ($submissionId, $actorId): PaymentResolutionCase {
            // Canonical financial order: submission, order, transaction, case.
            // InventoryService owns every lock from OrderItem onward.
            $lockedSubmission = PaymentSubmission::query()->lockForUpdate()->find($submissionId);
            if (! $lockedSubmission) {
                $this->conflict('La presentación de pago no existe.');
            }

            $actor = User::query()->find($actorId);
            if (! $actor || ! $actor->is_active || ! $actor->isTreasury()) {
                $this->conflict('El actor no está autorizado para resolver la reasignación.');
            }

            $order = Order::query()->lockForUpdate()->find($lockedSubmission->order_id);
            if (! $order) {
                $this->conflict('El pedido de la presentación no existe.');
            }

            $scopeKey = PaymentTransaction::approvedScopeKeyForOrder($order->id);
            $state = $this->initialFinancialState($lockedSubmission, $order, true);
            $transaction = $state['transaction'];
            $case = $state['case'];
            if ($this->looksFinal($lockedSubmission, $order, $transaction, $case)) {
                if ($transaction && $case && $state['approved_transactions']->count() === 1
                    && (int) $state['approved_transactions']->first()->id === (int) $transaction->id
                    && $this->isCoherentFinal($lockedSubmission, $order, $transaction, $case, $scopeKey)) {
                    return $case->fresh(['submission', 'receivedPaymentTransaction', 'reservations']);
                }
                $this->conflict('La reasignación existente está incompleta o es incompatible.');
            }

            $this->assertInitialEligibility($lockedSubmission, $order, $state);

            $expiresAt = now()->addMinutes((int) config('treasury.reservation_extension_minutes'));
            try {
                $reservationResult = $this->inventory->reserveVersionedForResolutionCaseWithLockedEvidence(
                    $order,
                    $case,
                    $expiresAt,
                    $actor
                );
                $reservations = collect($reservationResult['reservations']);
                $reopened = $this->assertOriginalReservationProvenance($order, [
                    'items' => $reservationResult['items'],
                    'historical_reservations' => $reservationResult['historical_reservations'],
                    'fulfillment_histories' => $reservationResult['fulfillment_histories'],
                ]);
                $this->inventory->consumeVersionedReservationsForResolutionCase($order, $case, $actor);
            } catch (InsufficientVersionedInventoryStockException $exception) {
                throw $exception;
            } catch (InventoryReservationConflictException $exception) {
                $this->conflict('La estructura de reservas o inventario es incompatible con la reasignación.', $exception);
            }

            $now = now();
            $transaction->approveReceivedForResolution($scopeKey);
            $lockedSubmission->update([
                'status' => PaymentSubmission::APPROVED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => $now,
                'decision_reason' => self::DECISION_REASON,
            ]);
            $case->update([
                'type' => PaymentResolutionCase::STOCK_REASSIGNMENT,
                'status' => PaymentResolutionCase::REASSIGNED,
                'reason' => self::DECISION_REASON,
                'resolved_by' => $actor->id,
                'resolved_at' => $now,
            ]);

            $orderChanges = [
                'payment_status' => 'approved',
                'paid_at' => $now,
                'reserved_until' => $expiresAt,
            ];
            if ($reopened) {
                $orderChanges += [
                    'status' => 'pending',
                    'tracking_status' => 'pending',
                    'fulfillment_status' => Order::FULFILLMENT_RESERVED,
                ];
            }
            $order->update($orderChanges);

            $metadata = [
                'resolution_case_id' => $case->id,
                'reservation_count' => $reservations->count(),
                'reopened_after_expiration' => $reopened,
            ];
            PaymentSubmissionHistory::create([
                'payment_submission_id' => $lockedSubmission->id,
                'event' => PaymentSubmissionHistory::REASSIGNMENT_APPROVED,
                'from_status' => PaymentSubmission::MANUAL_RESOLUTION_REQUIRED,
                'to_status' => PaymentSubmission::APPROVED,
                'actor_id' => $actor->id,
                'reason' => self::DECISION_REASON,
                'safe_metadata' => $metadata,
                'occurred_at' => $now,
            ]);
            PaymentResolutionHistory::create([
                'payment_resolution_case_id' => $case->id,
                'event' => self::RESOLUTION_EVENT,
                'from_status' => PaymentResolutionCase::OPEN,
                'to_status' => PaymentResolutionCase::REASSIGNED,
                'actor_id' => $actor->id,
                'reason' => self::DECISION_REASON,
                'safe_metadata' => $metadata,
                'occurred_at' => $now,
            ]);
            if ($reopened) {
                OrderFulfillmentHistory::firstOrCreate([
                    'idempotency_key' => $this->reopeningKey($order->id, $case->id),
                ], [
                    'order_id' => $order->id,
                    'from_status' => Order::FULFILLMENT_CANCELED,
                    'to_status' => Order::FULFILLMENT_RESERVED,
                    'user_id' => $actor->id,
                    'observation' => 'Pedido reabierto después de reasignar stock por una reserva expirada.',
                    'metadata' => [
                        'resolution_case_id' => $case->id,
                        'reason' => 'reopened_after_reservation_expiration',
                    ],
                    'created_at' => $now,
                ]);
            }

            return $case->fresh(['submission', 'receivedPaymentTransaction', 'reservations']);
        }, 3);
    }

    /**
     * @return array{
     *     transaction: ?PaymentTransaction,
     *     case: ?PaymentResolutionCase,
     *     linked_transactions: Collection,
     *     approved_transactions: Collection,
     *     scope_transaction: ?PaymentTransaction
     * }
     */
    protected function initialFinancialState(PaymentSubmission $submission, Order $order, bool $lock = false): array
    {
        $linkedTransactionsQuery = PaymentTransaction::query()
            ->where('payment_submission_id', $submission->id)
            ->orderBy('id');
        $scopeTransactionQuery = PaymentTransaction::query()
            ->where('approved_scope_key', PaymentTransaction::approvedScopeKeyForOrder($order->id));
        $approvedTransactionsQuery = PaymentTransaction::query()
            ->where('order_id', $order->id)
            ->where('transaction_type', PaymentTransaction::PAYMENT)
            ->where('status', PaymentTransaction::APPROVED)
            ->orderBy('id');
        $casesQuery = PaymentResolutionCase::query()
            ->where('payment_submission_id', $submission->id)
            ->orderBy('id');

        if ($lock) {
            $linkedTransactionsQuery->lockForUpdate();
            $scopeTransactionQuery->lockForUpdate();
            $approvedTransactionsQuery->lockForUpdate();
            $casesQuery->lockForUpdate();
        }

        // Preserve the certified financial lock/query order in both modes.
        $linkedTransactions = $linkedTransactionsQuery->get();
        $scopeTransaction = $scopeTransactionQuery->first();
        $approvedTransactions = $approvedTransactionsQuery->get();
        $cases = $casesQuery->get();

        return [
            'transaction' => $linkedTransactions->count() === 1 ? $linkedTransactions->first() : null,
            'case' => $cases->count() === 1 ? $cases->first() : null,
            'linked_transactions' => $linkedTransactions,
            'approved_transactions' => $approvedTransactions,
            'scope_transaction' => $scopeTransaction,
        ];
    }

    /**
     * @param  array{
     *     transaction: ?PaymentTransaction,
     *     case: ?PaymentResolutionCase,
     *     linked_transactions: Collection,
     *     approved_transactions: Collection,
     *     scope_transaction: ?PaymentTransaction
     * }  $state
     */
    private function assertInitialEligibility(PaymentSubmission $submission, Order $order, array $state): void
    {
        $transaction = $state['transaction'];
        $case = $state['case'];

        if ($state['linked_transactions']->count() !== 1
            || ! $transaction
            || ! $case
            || $state['scope_transaction']
            || $state['approved_transactions']->isNotEmpty()) {
            $this->conflict('La estructura financiera de la reasignación es incompatible.');
        }

        $this->assertFinancialPreconditions($submission, $order, $transaction, $case);
        $this->assertOperationalEligibility($order, $case);
    }

    private function assertFinancialPreconditions(PaymentSubmission $submission, Order $order, PaymentTransaction $transaction, PaymentResolutionCase $case): void
    {
        $valid = $submission->status === PaymentSubmission::MANUAL_RESOLUTION_REQUIRED
            && in_array($submission->channel, PaymentSubmission::CHANNELS, true)
            && $submission->currency === 'PEN'
            && $this->cents($submission->expected_amount) === $this->cents($order->total)
            && $submission->reviewed_at !== null
            && $order->payment_method === 'transferencia'
            && $order->payment_status === 'pending'
            && $order->paid_at === null
            && (int) $transaction->order_id === (int) $order->id
            && (int) $transaction->payment_submission_id === (int) $submission->id
            && $transaction->status === PaymentTransaction::RECEIVED
            && $transaction->amount === $order->total
            && $transaction->currency === 'PEN'
            && $transaction->payment_method === 'transferencia'
            && $transaction->transaction_type === PaymentTransaction::PAYMENT
            && $transaction->collection_method === $submission->channel
            && $transaction->idempotency_key === $this->transactionKey($submission->id)
            && $transaction->approved_scope_key === null
            && $transaction->confirmed_at !== null
            && (int) $case->payment_submission_id === (int) $submission->id
            && (int) $case->received_payment_transaction_id === (int) $transaction->id
            && $case->status === PaymentResolutionCase::OPEN
            && $case->type === null
            && $case->amount === $order->total
            && $case->currency === 'PEN'
            && $case->idempotency_key === $this->caseKey($submission->id)
            && ! PaymentRefund::query()->where('payment_resolution_case_id', $case->id)->exists();

        if (! $valid || ! $this->openingEvidenceIsCoherent($submission, $order, $transaction, $case, true)) {
            $this->conflict('La presentación, transacción o resolución no cumple el contrato financiero.');
        }
    }

    private function openingEvidenceIsCoherent(PaymentSubmission $submission, Order $order, PaymentTransaction $transaction, PaymentResolutionCase $case, bool $initial): bool
    {
        $submissionHistories = PaymentSubmissionHistory::query()
            ->where('payment_submission_id', $submission->id)
            ->where('event', self::OPENING_SUBMISSION_EVENT)
            ->get();
        $resolutionHistories = PaymentResolutionHistory::query()
            ->where('payment_resolution_case_id', $case->id)
            ->where('event', self::OPENING_RESOLUTION_EVENT)
            ->get();
        if ($submissionHistories->count() !== 1 || $resolutionHistories->count() !== 1) {
            return false;
        }

        $submissionHistory = $submissionHistories->first();
        $resolutionHistory = $resolutionHistories->first();
        $actors = [
            $transaction->confirmed_by,
            $case->requested_by,
            $submissionHistory->actor_id,
            $resolutionHistory->actor_id,
        ];
        if ($initial) {
            $actors[] = $submission->reviewed_by;
        }

        return $transaction->confirmed_at !== null
            && $case->funds_received_at !== null
            && $case->requested_at !== null
            && $this->sameMoment($transaction->confirmed_at, $case->funds_received_at)
            && $this->sameMoment($case->funds_received_at, $case->requested_at)
            && $this->sameMoment($case->requested_at, $submissionHistory->occurred_at)
            && $this->sameMoment($submissionHistory->occurred_at, $resolutionHistory->occurred_at)
            && (! $initial || ($submission->reviewed_at !== null
                && $this->sameMoment($submission->reviewed_at, $submissionHistory->occurred_at)
                && $submission->decision_reason === self::OPENING_REASON
                && $case->reason === self::OPENING_REASON))
            && $submissionHistory->from_status !== null
            && in_array($submissionHistory->from_status, [PaymentSubmission::PENDING_REVIEW, PaymentSubmission::OBSERVED], true)
            && $submissionHistory->to_status === PaymentSubmission::MANUAL_RESOLUTION_REQUIRED
            && $submissionHistory->reason === self::OPENING_REASON
            && $submissionHistory->safe_metadata == [
                'channel' => $submission->channel,
                'expected_amount' => (string) $submission->expected_amount,
                'currency' => 'PEN',
            ]
            && $resolutionHistory->from_status === null
            && $resolutionHistory->to_status === PaymentResolutionCase::OPEN
            && $resolutionHistory->reason === self::OPENING_REASON
            && $resolutionHistory->safe_metadata == [
                'currency' => 'PEN',
                'amount' => (string) $order->total,
            ]
            && $transaction->metadata == ['source' => 'treasury_resolution_opening']
            && $this->actorsAreCoherent($actors);
    }

    private function assertOperationalEligibility(Order $order, PaymentResolutionCase $case): void
    {
        if (! in_array($order->status, ['pending', 'canceled'], true)
            || ! in_array($order->fulfillment_status, [Order::FULFILLMENT_RESERVED, Order::FULFILLMENT_CANCELED], true)
            || ! in_array($order->tracking_status, ['pending', 'canceled'], true)
            || $order->preparing_at || $order->ready_at || $order->delivered_at || $order->picked_up_at) {
            $this->conflict('El pedido ya inició una operación incompatible con la reasignación.');
        }

        $handling = OrderHandlingProcess::query()->where('order_id', $order->id)->first();
        if ($handling && ($handling->picking_started_at || $handling->packing_started_at || $handling->canceled_at)) {
            $this->conflict('El pedido tiene picking, packing o cancelación operativa iniciada.');
        }

        if (PaymentResolutionCase::query()
            ->whereHas('submission', fn ($query) => $query->where('order_id', $order->id))
            ->where('id', '!=', $case->id)
            ->where('status', '!=', PaymentResolutionCase::OPEN)
            ->exists()) {
            $this->conflict('Existe otra resolución cerrada para el pedido.');
        }
    }

    /**
     * @return array{items: Collection, historical_reservations: Collection, fulfillment_histories: Collection}
     */
    private function readOriginalReservationEvidence(Order $order): array
    {
        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get();

        return [
            'items' => $items,
            'historical_reservations' => InventoryReservation::query()
                ->where(function ($query) use ($order, $items) {
                    $query->where('order_id', $order->id)
                        ->orWhereIn('order_item_id', $items->pluck('id'));
                })
                ->orderBy('id')
                ->get(),
            'fulfillment_histories' => OrderFulfillmentHistory::query()
                ->where('order_id', $order->id)
                ->orderBy('id')
                ->get(),
        ];
    }

    /**
     * @param  array{items: Collection, historical_reservations: Collection, fulfillment_histories: Collection}  $evidence
     */
    private function assertOriginalReservationProvenance(Order $order, array $evidence): bool
    {
        if (! $this->originalReservationProvenanceIsCoherent(
            $order,
            $evidence['items'],
            $evidence['historical_reservations'],
            $evidence['fulfillment_histories']
        )) {
            $this->conflict('La evidencia original de reservas es incompleta o incompatible.');
        }

        return $order->status === 'canceled';
    }

    private function originalReservationProvenanceIsCoherent(
        Order $order,
        Collection $items,
        Collection $historicalReservations,
        Collection $fulfillmentHistories
    ): bool {
        if ($items->isEmpty() || $items->contains(fn (OrderItem $item) => (int) $item->order_id !== (int) $order->id)) {
            return false;
        }

        $itemsById = $items->keyBy('id');
        foreach ($historicalReservations as $reservation) {
            $item = $itemsById->get($reservation->order_item_id);
            if (! $item
                || (int) $reservation->order_id !== (int) $item->order_id
                || (int) $reservation->product_id !== (int) $item->product_id
                || (int) $reservation->warehouse_id !== (int) $item->warehouse_id
                || (int) $reservation->quantity !== (int) $item->quantity
                || (int) $reservation->reservation_sequence < 1) {
                return false;
            }
        }

        $originals = $historicalReservations
            ->whereNull('resolution_case_id')
            ->groupBy('order_item_id');

        if ($originals->count() !== $items->count()
            || $originals->contains(fn (Collection $versions) => $versions->count() !== 1)) {
            return false;
        }

        $originalStatuses = collect();
        foreach ($items as $item) {
            $reservation = $originals->get($item->id)?->first();
            if (! $reservation
                || ! in_array($reservation->status, [
                    InventoryReservation::ACTIVE,
                    InventoryReservation::EXPIRED,
                    InventoryReservation::RELEASED,
                ], true)
                || ($reservation->status === InventoryReservation::ACTIVE
                    && ($reservation->released_at !== null || ! $reservation->expires_at || ! $reservation->expires_at->isPast()))
                || (in_array($reservation->status, [InventoryReservation::EXPIRED, InventoryReservation::RELEASED], true)
                    && $reservation->released_at === null)) {
                return false;
            }
            $originalStatuses->push($reservation->status);
        }

        if ($originalStatuses->unique()->count() !== 1) {
            return false;
        }

        if ($order->status !== 'canceled') {
            return true;
        }

        if ($originalStatuses->first() !== InventoryReservation::EXPIRED) {
            return false;
        }
        foreach ($originals as $versions) {
            if (($versions->first()->metadata['release_reason'] ?? null) !== 'reservation_expired') {
                return false;
            }
        }

        $expirationHistories = $fulfillmentHistories
            ->where('order_id', $order->id)
            ->where('idempotency_key', 'reservation-expired-order-'.$order->id)
            ->values();

        return $expirationHistories->count() === 1;
    }

    private function looksFinal(PaymentSubmission $submission, Order $order, ?PaymentTransaction $transaction, ?PaymentResolutionCase $case): bool
    {
        return $submission->status === PaymentSubmission::APPROVED
            || $order->payment_status === 'approved'
            || $transaction?->status === PaymentTransaction::APPROVED
            || $case?->status === PaymentResolutionCase::REASSIGNED;
    }

    private function isCoherentFinal(PaymentSubmission $submission, Order $order, PaymentTransaction $transaction, PaymentResolutionCase $case, string $scopeKey): bool
    {
        try {
            $reservations = collect($this->inventory->assertCoherentConsumedReservationsForResolutionCase($order, $case));
        } catch (InventoryReservationConflictException) {
            return false;
        }
        $submissionHistories = PaymentSubmissionHistory::query()
            ->where('payment_submission_id', $submission->id)
            ->orderBy('id')
            ->get();
        $resolutionHistories = PaymentResolutionHistory::query()
            ->where('payment_resolution_case_id', $case->id)
            ->orderBy('id')
            ->get();
        $fulfillmentHistories = OrderFulfillmentHistory::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();

        return $transaction->approved_scope_key === $scopeKey
            && $order->payment_status === 'approved'
            && $order->status === 'pending'
            && $order->tracking_status === 'pending'
            && $order->fulfillment_status === Order::FULFILLMENT_RESERVED
            && $order->reserved_until !== null
            && $reservations->every(fn (InventoryReservation $reservation) => $reservation->expires_at
                && $reservation->expires_at->getTimestamp() === $order->reserved_until->getTimestamp())
            && PaymentReassignmentFinalState::isCoherent(
                $order,
                $submission,
                $transaction,
                $case,
                $submissionHistories,
                $resolutionHistories,
                $fulfillmentHistories,
                $reservations->count(),
            )
            && ! PaymentRefund::query()->where('payment_resolution_case_id', $case->id)->exists();
    }

    private function transactionKey(int $submissionId): string
    {
        return 'treasury-received-submission-'.$submissionId;
    }

    private function caseKey(int $submissionId): string
    {
        return 'treasury-resolution-submission-'.$submissionId;
    }

    private function reopeningKey(int $orderId, int $caseId): string
    {
        return 'resolution-reopened-order-'.$orderId.'-case-'.$caseId;
    }

    private function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function sameMoment(mixed $left, mixed $right): bool
    {
        return $left !== null && $right !== null && $left->getTimestamp() === $right->getTimestamp();
    }

    private function actorsAreCoherent(array $actors): bool
    {
        $actors = array_values(array_unique(array_filter($actors, fn ($actor) => $actor !== null)));

        return count($actors) <= 1;
    }

    private function conflict(string $message, ?\Throwable $previous = null): never
    {
        throw new PaymentReassignmentConflictException($message, 0, $previous);
    }
}
