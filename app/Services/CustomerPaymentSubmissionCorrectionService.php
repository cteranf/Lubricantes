<?php

namespace App\Services;

use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerPaymentSubmissionCorrectionService
{
    public function __construct(private CustomerTransferPaymentOptionService $options, private TreasuryPaymentService $treasury) {}

    public function correct(Order $order, int $userId, array $input, string $key): PaymentSubmission
    {
        // Approval/review lock the submission before its order. Keep correction in
        // that same order to avoid an Order <-> PaymentSubmission deadlock cycle.
        $submissionId = PaymentSubmission::query()->where('order_id', $order->id)->value('id');

        try {
            return DB::transaction(function () use ($order, $submissionId, $userId, $input, $key): PaymentSubmission {
                $submission = $submissionId ? PaymentSubmission::query()->lockForUpdate()->find($submissionId) : null;
                if (! $submission) {
                    $this->notFound();
                }
                $order = Order::query()->lockForUpdate()->find($submission->order_id);
                if (! $order || (int) $order->user_id !== $userId) {
                    $this->notFound();
                }
                $normalized = $this->normalized($input);
                if ($submission->idempotency_key === $key && $this->same($submission, $normalized)) {
                    return $submission;
                }
                $reservations = InventoryReservation::query()->where('order_id', $order->id)->where('status', InventoryReservation::ACTIVE)->lockForUpdate()->get();
                $this->eligible($order, $submission, $reservations);
                if ($submission->status !== PaymentSubmission::OBSERVED) {
                    $this->conflict('La presentación ya no permite una corrección.');
                }
                if (PaymentSubmission::query()->where('idempotency_key', $key)->whereKeyNot($submission->id)->lockForUpdate()->exists()) {
                    $this->conflict('La clave de idempotencia ya fue utilizada.');
                }
                $account = PaymentReceivingAccount::query()->lockForUpdate()->find($normalized['account_id']);
                if (! $account || $account->channel !== $normalized['channel'] || ! $this->options->options($order)->contains('id', $account->id)) {
                    $this->invalid('La cuenta receptora no está disponible para este pedido.');
                }
                $fingerprint = $this->treasury->duplicateFingerprint($normalized['channel'], $account->code, $normalized['operation']);
                if (PaymentSubmission::query()->where('duplicate_fingerprint', $fingerprint)->whereKeyNot($submission->id)->lockForUpdate()->exists()) {
                    $this->conflict('La operación ya fue presentada para otro pedido.');
                }
                $now = now();
                $expiry = $this->extend($order, $reservations, $now, config('treasury.reservation_extension_minutes'));
                $from = $submission->status;
                $submission->update([
                    'channel' => $normalized['channel'], 'status' => PaymentSubmission::PENDING_REVIEW, 'expected_amount' => $order->total, 'currency' => 'PEN',
                    'operation_number' => $normalized['operation'], 'normalized_operation_number' => $normalized['operation'], 'duplicate_fingerprint' => $fingerprint,
                    'declared_paid_at' => $normalized['paid_at'], 'origin_phone_last4' => $normalized['last4'], 'origin_bank' => $normalized['bank'],
                    'receiving_account_snapshot' => $this->snapshot($account, $expiry), 'idempotency_key' => $key, 'submitted_at' => $now,
                    'decision_reason' => null, 'reviewed_by' => null, 'reviewed_at' => null,
                ]);
                PaymentSubmissionHistory::create(['payment_submission_id' => $submission->id, 'event' => 'corrected', 'from_status' => $from, 'to_status' => PaymentSubmission::PENDING_REVIEW, 'actor_id' => $userId, 'reason' => 'El cliente corrigió los datos de la presentación.', 'safe_metadata' => ['previous_expires_at' => $order->reserved_until?->toIso8601String(), 'new_expires_at' => $expiry->toIso8601String(), 'configured_minutes' => config('treasury.reservation_extension_minutes')], 'occurred_at' => $now]);
                $order->update(['reserved_until' => $expiry]);
                InventoryReservation::query()->whereIn('id', $reservations->filter(fn ($r) => $r->expires_at->isFuture())->pluck('id'))->update(['expires_at' => $expiry]);

                return $submission->fresh();
            });
        } catch (QueryException $exception) {
            if (! $this->treasury->isUniqueViolation($exception)) {
                throw $exception;
            }

            $this->conflict('No fue posible registrar la corrección de la presentación.');
        }
    }

    private function eligible(Order $order, PaymentSubmission $submission, $reservations): void
    {
        if ($submission->status !== PaymentSubmission::OBSERVED || $order->payment_method !== 'transferencia' || $order->payment_status !== 'pending' || in_array($order->status, ['canceled', 'rejected', 'delivered'], true) || $order->tracking_status === 'picked_up') {
            $this->conflict('La presentación no puede corregirse en su estado actual.');
        }
        if (! $order->reserved_until || $order->reserved_until->isPast() || $reservations->isEmpty() || $reservations->contains(fn ($r) => $r->expires_at->isPast())) {
            $this->conflict('La reserva de este pedido no está vigente.');
        }
    }

    private function normalized(array $input): array
    {
        $operation = $this->treasury->normalizeOperationNumber($input['operation_number']);
        $rules = $this->treasury->validateSubmission($input['channel'], $input['origin_phone_last_four'] ?? null, $input['origin_bank'] ?? null);
        $paidAt = Carbon::parse($input['paid_at'])->setTimezone(config('app.timezone'))->startOfSecond();
        if ($paidAt->greaterThan(now()->addMinutes(config('treasury.payment_submission_future_tolerance_minutes')))) {
            $this->invalid('La fecha declarada no puede estar en el futuro.');
        }

        return ['account_id' => (int) $input['receiving_account_id'], 'operation' => $operation, 'channel' => $rules['channel'], 'last4' => $rules['last4'], 'bank' => $rules['bank'], 'paid_at' => $paidAt];
    }

    private function same(PaymentSubmission $s, array $i): bool
    {
        return $s->channel === $i['channel'] && $s->normalized_operation_number === $i['operation'] && $s->declared_paid_at?->format('Y-m-d H:i:s') === $i['paid_at']->format('Y-m-d H:i:s') && $s->origin_phone_last4 === $i['last4'] && $s->origin_bank === $i['bank'] && (int) data_get($s->receiving_account_snapshot, 'id') === $i['account_id'];
    }

    private function extend(Order $order, $reservations, Carbon $now, int $minutes): Carbon
    {
        $result = $now->copy()->addMinutes($minutes);
        foreach ($reservations as $r) {
            if ($r->expires_at->greaterThan($result)) {
                $result = $r->expires_at->copy();
            }
        }

        return $order->reserved_until->greaterThan($result) ? $order->reserved_until->copy() : $result;
    }

    private function snapshot(PaymentReceivingAccount $a, Carbon $expiry): array
    {
        return ['id' => $a->id, 'code' => $a->code, 'channel' => $a->channel, 'display_name' => $a->display_name, 'holder_name' => $a->holder_name, 'currency' => 'PEN', 'bank_name' => $a->channel === 'bank_transfer' ? $a->bank_name : null, 'reservation_expires_at' => $expiry->toIso8601String()];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['payment_submission' => [$message]]);
    }

    private function conflict(string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => 'payment_submission_correction_conflict'], 409));
    }

    private function notFound(): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'La presentación de pago no existe.',
            'code' => 'payment_submission_not_found',
        ], 404));
    }
}
