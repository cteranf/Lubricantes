<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentPreference;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentProcessingService
{
    public function __construct(
        private MoneyService $money,
        private OrderStateService $states,
        private OrderPaymentService $payments,
        private InventoryService $inventory,
    ) {}

    /** Applies a remotely verified payment exactly once. */
    public function process(PaymentPreference $preference, array $payment): Order
    {
        return DB::transaction(function () use ($preference, $payment) {
            $preference = PaymentPreference::whereKey($preference->id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($preference->order_id)->lockForUpdate()->firstOrFail();
            $this->assertMatches($order, $preference, $payment);
            $status = $payment['status'] ?? null;
            if (in_array($status, ['refunded', 'charged_back', 'cancelled'], true)) {
                // These require an explicit financial/stock policy; never cancel or compensate automatically.
                logger()->warning('Remote payment requires manual review', ['preference_id' => $preference->id, 'status' => $status]);

                return $order;
            }
            if (! in_array($status, ['approved', 'rejected', 'pending', 'in_process'], true)) {
                return $order;
            }
            if ($order->payment_status === 'approved' && $status !== 'refunded') {
                return $order;
            }

            $normalized = $status === 'in_process' ? 'pending' : $status;
            $paymentId = (string) $payment['id'];
            $existing = PaymentTransaction::where('gateway', $preference->gateway)
                ->where('provider_payment_id', $paymentId)->lockForUpdate()->first();
            if ($existing && (int) $existing->order_id !== (int) $order->id) {
                throw ValidationException::withMessages(['payment' => ['El pago remoto ya está asociado a otro pedido.']]);
            }

            if (! $existing) {
                $this->payments->recordCardAttempt($order, $paymentId, $normalized === 'approved' ? PaymentTransaction::APPROVED : PaymentTransaction::FAILED, [
                    'gateway' => $preference->gateway,
                    'payment_preference_id' => $preference->id,
                    'provider_payment_id' => $paymentId,
                    'provider_status' => $status,
                    'provider_status_detail' => $payment['status_detail'] ?? null,
                    'verified_at' => now(),
                ], $payment['status_detail'] ?? null);
            }

            $updated = $this->states->applyPaymentStatus($order, $this->safePaymentSnapshot($payment));
            if ($updated->payment_status === 'approved') {
                $this->inventory->consumeOrderReservation($updated);
                if (! $updated->paid_at) {
                    $updated->update(['paid_at' => now()]);
                }
            }

            return $updated->refresh();
        });
    }

    private function assertMatches(Order $order, PaymentPreference $preference, array $payment): void
    {
        if (($payment['preference_id'] ?? null) !== $preference->provider_preference_id
            || ! hash_equals($preference->external_reference, (string) ($payment['external_reference'] ?? ''))) {
            throw ValidationException::withMessages(['payment' => ['La referencia del pago no corresponde a la preferencia.']]);
        }
        if ($preference->gateway !== 'mock') {
            if ($this->money->toMinor((string) ($payment['transaction_amount'] ?? '')) !== $this->money->toMinor((string) $order->total)) {
                throw ValidationException::withMessages(['payment' => ['El importe confirmado no coincide con el pedido.']]);
            }
            if (($payment['currency_id'] ?? null) !== config('payment.currency')) {
                throw ValidationException::withMessages(['payment' => ['La moneda confirmada no coincide con el pedido.']]);
            }
        }
    }

    private function safePaymentSnapshot(array $payment): array
    {
        return array_intersect_key($payment, array_flip(['id', 'status', 'status_detail', 'external_reference', 'preference_id', 'transaction_amount', 'currency_id', 'payment_method_id']));
    }
}
