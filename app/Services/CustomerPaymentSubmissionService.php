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

class CustomerPaymentSubmissionService
{
    public function __construct(
        private CustomerTransferPaymentOptionService $options,
        private TreasuryPaymentService $treasury,
    ) {}

    public function submit(Order $order, int $userId, array $input, string $idempotencyKey): array
    {
        $normalized = $this->normalizedInput($input);

        try {
            return DB::transaction(function () use ($order, $userId, $normalized, $idempotencyKey): array {
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
                $this->options->eligibleOrder($lockedOrder, $userId);
                $reservations = InventoryReservation::query()
                    ->where('order_id', $lockedOrder->id)
                    ->where('status', InventoryReservation::ACTIVE)
                    ->lockForUpdate()
                    ->get();
                if ($reservations->isEmpty()) {
                    $this->invalid('La reserva de este pedido no está vigente.');
                }

                $existing = PaymentSubmission::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    return [$this->resolveIdempotent($existing, $lockedOrder, $userId, $normalized), false];
                }
                if (PaymentSubmission::query()->where('order_id', $lockedOrder->id)->lockForUpdate()->exists()) {
                    $this->conflict('Este pedido ya tiene una presentación de pago.');
                }

                $account = PaymentReceivingAccount::query()->lockForUpdate()->findOrFail($normalized['receiving_account_id']);
                $this->assertEligibleAccount($lockedOrder, $account, $normalized['channel']);
                $fingerprint = $this->treasury->duplicateFingerprint($normalized['channel'], $account->code, $normalized['operation_number']);
                if (PaymentSubmission::query()->where('duplicate_fingerprint', $fingerprint)->lockForUpdate()->exists()) {
                    $this->conflict('La operación ya fue presentada para otro pedido.');
                }

                $now = now();
                $newExpiry = $this->extendedExpiry($lockedOrder, $reservations, $now);
                $snapshot = [
                    'id' => $account->id,
                    'code' => $account->code,
                    'channel' => $account->channel,
                    'display_name' => $account->display_name,
                    'holder_name' => $account->holder_name,
                    'currency' => 'PEN',
                    'bank_name' => $account->channel === 'bank_transfer' ? $account->bank_name : null,
                    'reservation_expires_at' => $newExpiry->toIso8601String(),
                ];
                $submission = PaymentSubmission::create([
                    'order_id' => $lockedOrder->id,
                    'channel' => $normalized['channel'],
                    'status' => PaymentSubmission::PENDING_REVIEW,
                    'expected_amount' => $lockedOrder->total,
                    'currency' => 'PEN',
                    'operation_number' => $normalized['operation_number'],
                    'normalized_operation_number' => $normalized['operation_number'],
                    'duplicate_fingerprint' => $fingerprint,
                    'declared_paid_at' => $normalized['paid_at'],
                    'origin_phone_last4' => $normalized['last4'],
                    'origin_bank' => $normalized['bank'],
                    'receiving_account_snapshot' => $snapshot,
                    'submitted_by' => $userId,
                    'submitted_at' => $now,
                    'idempotency_key' => $idempotencyKey,
                ]);
                PaymentSubmissionHistory::create([
                    'payment_submission_id' => $submission->id,
                    'event' => 'submitted',
                    'to_status' => PaymentSubmission::PENDING_REVIEW,
                    'actor_id' => $userId,
                    'safe_metadata' => [
                        'previous_expires_at' => $lockedOrder->reserved_until?->toIso8601String(),
                        'new_expires_at' => $newExpiry->toIso8601String(),
                        'configured_minutes' => config('treasury.reservation_extension_minutes'),
                        'submitted_by' => $userId,
                    ],
                    'occurred_at' => $now,
                ]);
                $lockedOrder->update(['reserved_until' => $newExpiry]);
                InventoryReservation::query()
                    ->whereIn('id', $reservations->filter(fn (InventoryReservation $reservation) => $reservation->expires_at->isFuture())->pluck('id'))
                    ->update(['expires_at' => $newExpiry]);

                return [$submission, true];
            });
        } catch (QueryException $exception) {
            if (! $this->treasury->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = PaymentSubmission::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing && $existing->order_id === $order->id && $existing->submitted_by === $userId) {
                return [$this->resolveIdempotent($existing, $order, $userId, $normalized), false];
            }

            $this->conflict('No fue posible registrar la presentación de pago.');
        }
    }

    private function normalizedInput(array $input): array
    {
        $id = $input['receiving_account_id'];
        $operation = $this->treasury->normalizeOperationNumber($input['operation_number']);
        $rules = $this->treasury->validateSubmission($input['channel'], $input['origin_phone_last_four'] ?? null, $input['origin_bank'] ?? null);
        $paidAt = Carbon::parse($input['paid_at'])->setTimezone(config('app.timezone'))->startOfSecond();
        if ($paidAt->greaterThan(now()->addMinutes(config('treasury.payment_submission_future_tolerance_minutes')))) {
            $this->invalid('La fecha declarada no puede estar en el futuro.');
        }

        return ['receiving_account_id' => $id, 'operation_number' => $operation, 'channel' => $rules['channel'], 'last4' => $rules['last4'], 'bank' => $rules['bank'], 'paid_at' => $paidAt];
    }

    private function assertEligibleAccount(Order $order, PaymentReceivingAccount $account, string $channel): void
    {
        if ($account->channel !== $channel || ! $this->options->options($order)->contains('id', $account->id)) {
            $this->invalid('La cuenta receptora no está disponible para este pedido.');
        }
    }

    private function resolveIdempotent(PaymentSubmission $existing, Order $order, int $userId, array $input): PaymentSubmission
    {
        $same = $existing->order_id === $order->id
            && $existing->submitted_by === $userId
            && $existing->channel === $input['channel']
            && $existing->normalized_operation_number === $input['operation_number']
            && $existing->declared_paid_at?->format('Y-m-d H:i:s') === $input['paid_at']->format('Y-m-d H:i:s')
            && $existing->origin_phone_last4 === $input['last4']
            && $existing->origin_bank === $input['bank']
            && (int) data_get($existing->receiving_account_snapshot, 'id') === $input['receiving_account_id'];
        if (! $same) {
            $this->conflict('La clave de idempotencia ya fue utilizada con otros datos.');
        }

        return $existing;
    }

    private function extendedExpiry(Order $order, $reservations, Carbon $now): Carbon
    {
        $base = $now->copy()->addMinutes(config('treasury.reservation_extension_minutes'));
        foreach ($reservations as $reservation) {
            if ($reservation->expires_at->greaterThan($base)) {
                $base = $reservation->expires_at->copy();
            }
        }

        return $order->reserved_until->greaterThan($base) ? $order->reserved_until->copy() : $base;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['payment_submission' => [$message]]);
    }

    private function conflict(string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], 409));
    }
}
