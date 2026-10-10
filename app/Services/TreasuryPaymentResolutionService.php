<?php

namespace App\Services;

use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\PaymentResolutionCase;
use App\Models\PaymentResolutionHistory;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TreasuryPaymentResolutionService
{
    private const SUBMISSION_EVENT = 'resolution_opened';

    private const RESOLUTION_REASON = 'payment_received_reservation_unavailable';

    public function __construct(private TreasuryPaymentService $treasury) {}

    public function open(int $submissionId, int $actorId): PaymentResolutionCase
    {
        try {
            return DB::transaction(fn () => $this->openWithinTransaction($submissionId, $actorId));
        } catch (QueryException $exception) {
            if ($this->treasury->isUniqueViolation($exception)) {
                $retry = $this->coherentExisting($submissionId);
                if ($retry) {
                    return $retry;
                }

                $this->conflict('La apertura de resolución quedó en un estado incompatible.');
            }

            throw $exception;
        }
    }

    public function canOpen(PaymentSubmission $submission): bool
    {
        $submission = PaymentSubmission::query()->find($submission->id);
        if (! $submission || $submission->status !== PaymentSubmission::PENDING_REVIEW) {
            return false;
        }

        $order = Order::query()->find($submission->order_id);
        if (! $order) {
            return false;
        }

        if (PaymentTransaction::query()->where('payment_submission_id', $submission->id)->exists()
            || PaymentTransaction::query()->where('idempotency_key', $this->transactionKey($submission->id))->exists()
            || PaymentTransaction::query()
                ->where('order_id', $order->id)
                ->where('transaction_type', PaymentTransaction::PAYMENT)
                ->where('status', PaymentTransaction::APPROVED)
                ->exists()
            || PaymentResolutionCase::query()->where('payment_submission_id', $submission->id)->exists()
            || PaymentResolutionCase::query()->where('idempotency_key', $this->caseKey($submission->id))->exists()) {
            return false;
        }

        $reservations = InventoryReservation::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();

        return $this->eligibilityConflict($submission, $order, $reservations, [PaymentSubmission::PENDING_REVIEW]) === null;
    }

    private function openWithinTransaction(int $submissionId, int $actorId): PaymentResolutionCase
    {
        // Keep the same financial lock direction as approval: submission, order,
        // transaction keys, case, then reservations ordered by their primary key.
        $submission = PaymentSubmission::query()->lockForUpdate()->find($submissionId);
        if (! $submission) {
            $this->notFound();
        }

        $order = Order::query()->lockForUpdate()->find($submission->order_id);
        if (! $order) {
            $this->conflict('El pedido asociado no está disponible.');
        }

        $transactionKey = $this->transactionKey($submission->id);
        $caseKey = $this->caseKey($submission->id);
        $linkedTransaction = PaymentTransaction::query()->where('payment_submission_id', $submission->id)->lockForUpdate()->first();
        $transactionByKey = PaymentTransaction::query()->where('idempotency_key', $transactionKey)->lockForUpdate()->first();
        $approved = PaymentTransaction::query()
            ->where('order_id', $order->id)
            ->where('transaction_type', PaymentTransaction::PAYMENT)
            ->where('status', PaymentTransaction::APPROVED)
            ->lockForUpdate()
            ->first();
        $case = PaymentResolutionCase::query()->where('payment_submission_id', $submission->id)->lockForUpdate()->first();
        $caseByKey = PaymentResolutionCase::query()->where('idempotency_key', $caseKey)->lockForUpdate()->first();
        $reservations = InventoryReservation::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();

        if ($submission->status === PaymentSubmission::MANUAL_RESOLUTION_REQUIRED) {
            $existing = $this->coherent($submission, $order, $linkedTransaction, $transactionByKey, $case, $caseByKey, $approved, $reservations);
            if ($existing) {
                return $existing;
            }
            $this->conflict('La resolución existente es inconsistente.');
        }

        if ($linkedTransaction || $transactionByKey || $case || $caseByKey || $approved) {
            $this->conflict('Ya existe información financiera incompatible para la resolución.');
        }

        $this->assertEligible($submission, $order, $reservations);
        $now = now();

        $transaction = new PaymentTransaction([
            'order_id' => $order->id,
            'payment_method' => 'transferencia',
            'transaction_type' => PaymentTransaction::PAYMENT,
            'status' => PaymentTransaction::RECEIVED,
            'amount' => (string) $order->total,
            'currency' => 'PEN',
            'collection_method' => $submission->channel,
            'confirmed_by' => $actorId,
            'confirmed_at' => $now,
            'idempotency_key' => $transactionKey,
            'approved_scope_key' => null,
            'metadata' => ['source' => 'treasury_resolution_opening'],
        ]);
        $transaction->payment_submission_id = $submission->id;
        $transaction->save();

        $case = PaymentResolutionCase::forceCreate([
            'payment_submission_id' => $submission->id,
            'received_payment_transaction_id' => $transaction->id,
            'type' => null,
            'status' => PaymentResolutionCase::OPEN,
            'amount' => (string) $order->total,
            'currency' => 'PEN',
            'reason' => self::RESOLUTION_REASON,
            'funds_received_at' => $now,
            'requested_by' => $actorId,
            'requested_at' => $now,
            'idempotency_key' => $caseKey,
        ]);

        $from = $submission->status;
        $submission->update([
            'status' => PaymentSubmission::MANUAL_RESOLUTION_REQUIRED,
            'reviewed_by' => $actorId,
            'reviewed_at' => $now,
            'decision_reason' => self::RESOLUTION_REASON,
        ]);
        PaymentSubmissionHistory::create([
            'payment_submission_id' => $submission->id,
            'event' => self::SUBMISSION_EVENT,
            'from_status' => $from,
            'to_status' => PaymentSubmission::MANUAL_RESOLUTION_REQUIRED,
            'actor_id' => $actorId,
            'reason' => self::RESOLUTION_REASON,
            'safe_metadata' => $this->treasury->safeHistoryMetadata([
                'channel' => $submission->channel,
                'expected_amount' => (string) $submission->expected_amount,
                'currency' => 'PEN',
            ]),
            'occurred_at' => $now,
        ]);
        PaymentResolutionHistory::create([
            'payment_resolution_case_id' => $case->id,
            'event' => 'opened',
            'to_status' => PaymentResolutionCase::OPEN,
            'actor_id' => $actorId,
            'reason' => self::RESOLUTION_REASON,
            'safe_metadata' => [
                'currency' => 'PEN',
                'amount' => (string) $order->total,
            ],
            'occurred_at' => $now,
        ]);

        return $case->fresh(['submission', 'receivedPaymentTransaction']);
    }

    private function assertEligible(PaymentSubmission $submission, Order $order, Collection $reservations): void
    {
        $message = $this->eligibilityConflict(
            $submission,
            $order,
            $reservations,
            [PaymentSubmission::PENDING_REVIEW, PaymentSubmission::OBSERVED],
        );

        if ($message !== null) {
            $this->conflict($message);
        }
    }

    private function eligibilityConflict(PaymentSubmission $submission, Order $order, Collection $reservations, array $allowedStatuses): ?string
    {
        if (! in_array($submission->status, $allowedStatuses, true)
            || $order->payment_method !== 'transferencia'
            || $order->payment_status !== 'pending'
            || $order->paid_at !== null
            || $submission->currency !== 'PEN'
            || $this->cents($submission->expected_amount) !== $this->cents($order->total)) {
            return 'La presentación no permite abrir una resolución.';
        }

        if (in_array($order->status, ['rejected', 'delivered', 'picked_up'], true)
            || in_array($order->tracking_status, ['delivered', 'picked_up'], true)) {
            return 'El pedido no permite abrir una resolución.';
        }

        if ($this->hasUsableReservation($order, $reservations)) {
            return 'La reserva sigue vigente y debe usarse el flujo de aprobación normal.';
        }

        if ($reservations->contains(fn (InventoryReservation $reservation) => $reservation->status === InventoryReservation::CONSUMED)) {
            return 'La reserva ya fue consumida y no permite abrir una resolución.';
        }

        $persistentUnavailableEvidence = $reservations->isEmpty()
            || $reservations->contains(fn (InventoryReservation $reservation) => in_array($reservation->status, [InventoryReservation::EXPIRED, InventoryReservation::RELEASED], true) || ! $reservation->expires_at || $reservation->expires_at->isPast());
        if (! $persistentUnavailableEvidence) {
            return 'No existe evidencia persistida de una reserva no disponible.';
        }

        // A released reservation has no persisted provenance that distinguishes a
        // manual cancellation from expiry. A canceled order therefore needs the
        // unambiguous terminal status recorded by the expiration flow.
        if ($order->status === 'canceled' && ! $reservations->contains(fn (InventoryReservation $reservation) => $reservation->status === InventoryReservation::EXPIRED)) {
            return 'La cancelación no corresponde a una reserva vencida.';
        }

        return null;
    }

    private function hasUsableReservation(Order $order, Collection $reservations): bool
    {
        return $order->reserved_until?->isFuture()
            && $reservations->isNotEmpty()
            && $reservations->every(fn (InventoryReservation $reservation) => $reservation->status === InventoryReservation::ACTIVE && $reservation->expires_at?->isFuture());
    }

    private function coherentExisting(int $submissionId): ?PaymentResolutionCase
    {
        $submission = PaymentSubmission::query()->find($submissionId);
        if (! $submission) {
            return null;
        }
        $order = Order::query()->find($submission->order_id);
        $transaction = PaymentTransaction::query()->where('payment_submission_id', $submission->id)->first();
        $case = PaymentResolutionCase::query()->where('payment_submission_id', $submission->id)->first();
        $reservations = $order ? InventoryReservation::query()->where('order_id', $order->id)->orderBy('id')->get() : collect();

        return $order && $this->coherent($submission, $order, $transaction, $transaction, $case, $case, null, $reservations) ? $case->fresh(['submission', 'receivedPaymentTransaction']) : null;
    }

    private function coherent(PaymentSubmission $submission, Order $order, ?PaymentTransaction $linked, ?PaymentTransaction $byKey, ?PaymentResolutionCase $case, ?PaymentResolutionCase $caseByKey, ?PaymentTransaction $approved, Collection $reservations): ?PaymentResolutionCase
    {
        if (! $linked || ! $case || ! $byKey || ! $caseByKey || $approved || $linked->id !== $byKey->id || $case->id !== $caseByKey->id) {
            return null;
        }

        $submissionHistory = PaymentSubmissionHistory::query()->where('payment_submission_id', $submission->id)->where('event', self::SUBMISSION_EVENT)->get();
        $resolutionHistory = PaymentResolutionHistory::query()->where('payment_resolution_case_id', $case->id)->where('event', 'opened')->get();

        if ($submissionHistory->count() !== 1 || $resolutionHistory->count() !== 1) {
            return null;
        }

        $submissionEvent = $submissionHistory->first();
        $resolutionEvent = $resolutionHistory->first();
        $validUnavailableState = ! $this->hasUsableReservation($order, $reservations);

        return $validUnavailableState
            && $submission->status === PaymentSubmission::MANUAL_RESOLUTION_REQUIRED
            && $submission->currency === 'PEN'
            && $this->cents($submission->expected_amount) === $this->cents($order->total)
            && $order->payment_method === 'transferencia'
            && $order->payment_status === 'pending'
            && $order->paid_at === null
            && ! in_array($order->status, ['rejected', 'delivered', 'picked_up'], true)
            && ! in_array($order->tracking_status, ['delivered', 'picked_up'], true)
            && (int) $linked->order_id === (int) $order->id
            && (int) $linked->payment_submission_id === (int) $submission->id
            && $linked->payment_method === 'transferencia'
            && $linked->transaction_type === PaymentTransaction::PAYMENT
            && $linked->status === PaymentTransaction::RECEIVED
            && $linked->collection_method === $submission->channel
            && $linked->currency === 'PEN'
            && $this->cents($linked->amount) === $this->cents($order->total)
            && $linked->idempotency_key === $this->transactionKey($submission->id)
            && $linked->approved_scope_key === null
            && $linked->confirmed_at !== null
            && (int) $case->payment_submission_id === (int) $submission->id
            && (int) $case->received_payment_transaction_id === (int) $linked->id
            && $case->type === null
            && $case->status === PaymentResolutionCase::OPEN
            && $case->currency === 'PEN'
            && $this->cents($case->amount) === $this->cents($order->total)
            && $case->idempotency_key === $this->caseKey($submission->id)
            && $case->funds_received_at !== null
            && $case->requested_at !== null
            && $submission->reviewed_at !== null
            && $submission->decision_reason === self::RESOLUTION_REASON
            && $submissionEvent->from_status !== null
            && in_array($submissionEvent->from_status, [PaymentSubmission::PENDING_REVIEW, PaymentSubmission::OBSERVED], true)
            && $submissionEvent->to_status === PaymentSubmission::MANUAL_RESOLUTION_REQUIRED
            && $submissionEvent->reason === self::RESOLUTION_REASON
            && $resolutionEvent->from_status === null
            && $resolutionEvent->to_status === PaymentResolutionCase::OPEN
            && $resolutionEvent->reason === self::RESOLUTION_REASON
            && $this->sameMoment($linked->confirmed_at, $case->funds_received_at)
            && $this->sameMoment($case->funds_received_at, $case->requested_at)
            && $this->sameMoment($case->requested_at, $submission->reviewed_at)
            && $this->sameMoment($submission->reviewed_at, $submissionEvent->occurred_at)
            && $this->sameMoment($submissionEvent->occurred_at, $resolutionEvent->occurred_at)
            && $submissionEvent->safe_metadata == $this->treasury->safeHistoryMetadata([
                'channel' => $submission->channel,
                'expected_amount' => (string) $submission->expected_amount,
                'currency' => 'PEN',
            ])
            && $resolutionEvent->safe_metadata == [
                'currency' => 'PEN',
                'amount' => (string) $order->total,
            ]
            && $this->actorsAreCoherent([
                $linked->confirmed_by,
                $case->requested_by,
                $submission->reviewed_by,
                $submissionEvent->actor_id,
                $resolutionEvent->actor_id,
            ])
            ? $case->fresh(['submission', 'receivedPaymentTransaction'])
            : null;
    }

    private function transactionKey(int $submissionId): string
    {
        return 'treasury-received-submission-'.$submissionId;
    }

    private function caseKey(int $submissionId): string
    {
        return 'treasury-resolution-submission-'.$submissionId;
    }

    private function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function sameMoment(mixed $left, mixed $right): bool
    {
        return $left !== null && $right !== null && $left->getTimestamp() === $right->getTimestamp();
    }

    /**
     * nullOnDelete intentionally leaves historic events valid. If actors still
     * exist, every recorded actor must nevertheless identify the same treasury
     * user.
     */
    private function actorsAreCoherent(array $actors): bool
    {
        $actors = array_values(array_unique(array_filter($actors, fn ($actor) => $actor !== null)));

        return count($actors) <= 1;
    }

    private function conflict(string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => 'payment_resolution_conflict'], 409)
            ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache'));
    }

    private function notFound(): never
    {
        throw new HttpResponseException(response()->json(['message' => 'La presentación de pago no existe.', 'code' => 'payment_submission_not_found'], 404)
            ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache'));
    }
}
