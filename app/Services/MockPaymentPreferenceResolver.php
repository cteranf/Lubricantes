<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentPreference;

/** Resolves identity only. Mock amounts belong to Order; currency belongs to payment.currency.
 * A mock preference is not an independent financial snapshot.
 */
class MockPaymentPreferenceResolver
{
    public function resolve(string $paymentId, mixed $orderId, bool $lockOrder = false): Order
    {
        abort_unless(app(PaymentSettingService::class)->isMockActive(), 404);
        abort_unless(is_scalar($orderId) && ctype_digit((string) $orderId) && (int) $orderId > 0, 404);

        $query = Order::whereKey($orderId);
        if ($lockOrder) {
            $query->lockForUpdate();
        }
        $order = $query->first();
        if ($lockOrder && $order) {
            $this->afterOrderLocked($order);
        }
        abort_unless($order && $order->current_payment_preference_id, 404);
        $preference = PaymentPreference::whereKey($order->current_payment_preference_id)->first();
        abort_unless($preference
            && $preference->provider_preference_id === $paymentId
            && (int) $preference->order_id === (int) $order->id
            && $preference->gateway === 'mock'
            && $preference->state === PaymentPreference::ACTIVE
            && $order->payment_method === 'card'
            && (in_array($order->payment_status, ['pending', 'rejected'], true)
                || ($order->payment_status === 'approved' && $order->paid_at)), 404);

        return $order;
    }

    protected function afterOrderLocked(Order $order): void {}
}
