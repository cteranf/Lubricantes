<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class MockPaymentCheckoutService
{
    public function __construct(private MockPaymentPreferenceResolver $resolver, private InventoryReservationExpirationService $expiration) {}

    public function inspect(string $paymentId, mixed $orderId): Order
    {
        return DB::transaction(function () use ($paymentId, $orderId) {
            $order = $this->resolver->resolve($paymentId, $orderId, true);
            if ($order->payment_status === 'approved' && $order->paid_at) {
                return $order;
            }
            if (! in_array($order->status, ['canceled', 'rejected'], true)
                && $order->reserved_until && $order->reserved_until->isPast()) {
                // expireOrder owns cancellation and inventory writes under this same order lock.
                // A skipped expiration must never be followed by an unconditional cancellation.
                $this->expiration->expireOrder($order->id);

                return $order->refresh();
            }

            return $order;
        }, 3);
    }
}
