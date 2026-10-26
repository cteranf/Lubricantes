<?php

namespace App\Services;

use App\Exceptions\PaymentFinancialReviewException;
use App\Exceptions\PaymentLinkEvidenceException as LinkFailure;
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
        // The caller's model is a locator, never authorization to apply a payment.
        $located = $preference->only(['id', 'order_id', 'gateway', 'provider_preference_id', 'external_reference', 'state']);

        return DB::transaction(function () use ($located, $payment) {
            $order = Order::whereKey($located['order_id'])->lockForUpdate()->first();
            if ($order) {
                $this->afterRemoteOrderLock($order);
            }
            $preference = PaymentPreference::whereKey($located['id'])->lockForUpdate()->first();
            if (! $order || ! $preference || $preference->only(array_keys($located)) !== $located
                || (int) $preference->order_id !== (int) $order->id
                || $preference->state !== PaymentPreference::ACTIVE
                || (int) $order->current_payment_preference_id !== (int) $preference->id) {
                if ($order && $order->payment_status === 'approved') {
                    $this->lockRemoteTransaction($payment, (string) ($payment['gateway'] ?? $located['gateway']));
                    throw new PaymentFinancialReviewException;
                }
                throw ValidationException::withMessages(['payment' => [LinkFailure::STALE_LOCAL_LINK]]);
            }
            try {
                $this->assertMatches($order, $preference, $payment);
            } catch (ValidationException|LinkFailure $exception) {
                if ($order->payment_status === 'approved') {
                    $this->lockRemoteTransaction($payment, (string) ($payment['gateway'] ?? $preference->gateway));
                    throw new PaymentFinancialReviewException;
                }
                throw $exception;
            }
            $status = $payment['status'] ?? null;
            $paymentId = (string) ($payment['id'] ?? '');
            $existing = $this->lockRemoteTransaction($payment, $preference->gateway);
            if (in_array($status, ['refunded', 'charged_back', 'cancelled'], true)) {
                // Reversals are never applied automatically. An approved order
                // is routed only after its existing remote financial identity
                // has been revalidated under the transaction lock.
                if ($order->payment_status === 'approved' && ! $this->hasCoherentApprovedTransaction($order, $preference, $payment, $existing)) {
                    throw new PaymentFinancialReviewException;
                }
                throw new PaymentFinancialReviewException(PaymentFinancialReviewException::MANUAL_RECONCILIATION_REQUIRED);
            }
            if (! in_array($status, ['approved', 'rejected', 'pending', 'in_process'], true)) {
                if ($order->payment_status === 'approved' && ! $this->hasCoherentApprovedTransaction($order, $preference, $payment, $existing)) {
                    throw new PaymentFinancialReviewException;
                }
                throw new PaymentFinancialReviewException(PaymentFinancialReviewException::UNSUPPORTED_REMOTE_STATUS);
            }
            if ($status === 'approved' && (in_array($order->status, ['canceled', 'rejected'], true)
                || ($order->reserved_until && $order->reserved_until->isPast()))) {
                throw ValidationException::withMessages(['payment' => ['late_payment_requires_review']]);
            }

            $normalized = $status === 'in_process' ? 'pending' : $status;
            if ($order->payment_status === 'approved') {
                if ($this->isCoherentApprovedReplay($order, $preference, $payment, $existing)) {
                    return $order;
                }
                throw new PaymentFinancialReviewException;
            }
            if ($existing && ((int) $existing->order_id !== (int) $order->id
                || (int) $existing->payment_preference_id !== (int) $preference->id
                || $existing->payment_method !== 'card' || $existing->transaction_type !== PaymentTransaction::PAYMENT
                || $existing->external_reference !== $paymentId
                || $existing->currency !== $payment['currency_id']
                || $this->money->toMinor($existing->amount) !== $this->money->toMinor((string) $order->total))) {
                throw ValidationException::withMessages(['payment' => ['El pago remoto ya está asociado a otro pedido.']]);
            }

            if ($status === 'approved') {
                $approved = PaymentTransaction::where('order_id', $order->id)
                    ->where('transaction_type', PaymentTransaction::PAYMENT)
                    ->where('status', PaymentTransaction::APPROVED)->lockForUpdate()->first();
                if ($approved && (! $existing || (int) $approved->id !== (int) $existing->id)) {
                    throw ValidationException::withMessages(['payment' => [LinkFailure::CONTRADICTORY_EVIDENCE]]);
                }
            }

            if ($existing && $preference->gateway === 'mercadopago') {
                if (in_array($existing->provider_status, ['pending', 'in_process'], true)) {
                    $existing->applyVerifiedRemoteStatus($status, $payment['status_detail'] ?? null);
                } elseif ($existing->provider_status !== $status) {
                    throw ValidationException::withMessages(['payment' => [LinkFailure::CONTRADICTORY_EVIDENCE]]);
                }
            }
            if (! $existing) {
                $financialStatus = $normalized === 'approved' ? PaymentTransaction::APPROVED
                    : ($normalized === 'pending' && $preference->gateway === 'mercadopago' ? PaymentTransaction::PENDING : PaymentTransaction::FAILED);
                $this->payments->recordCardAttempt($order, $paymentId, $financialStatus, [
                    'gateway' => $preference->gateway,
                    'payment_preference_id' => $preference->id,
                    'provider_payment_id' => $paymentId,
                    'provider_status' => $status,
                    'provider_status_detail' => $payment['status_detail'] ?? null,
                    'verified_at' => now(),
                ], $payment['status_detail'] ?? null, $payment['currency_id'] ?? null);
            }

            $updated = $this->states->applyPaymentStatus($order, $this->safePaymentSnapshot($payment));
            if ($updated->payment_status === 'approved') {
                $this->inventory->consumeOrderReservation($updated);
                if (! $updated->paid_at) {
                    $updated->update(['paid_at' => now()]);
                }
            }

            return $updated->refresh();
        }, 3);
    }

    private function isCoherentApprovedReplay(Order $order, PaymentPreference $preference, array $payment, ?PaymentTransaction $transaction): bool
    {
        if (! in_array($payment['status'] ?? null, ['approved', 'pending', 'in_process'], true)) {
            return false;
        }

        return $this->hasCoherentApprovedTransaction($order, $preference, $payment, $transaction);
    }

    private function hasCoherentApprovedTransaction(Order $order, PaymentPreference $preference, array $payment, ?PaymentTransaction $transaction): bool
    {
        if (! $transaction || $transaction->gateway !== $preference->gateway
            || (int) $transaction->order_id !== (int) $order->id
            || (int) $transaction->payment_preference_id !== (int) $preference->id
            || $transaction->transaction_type !== PaymentTransaction::PAYMENT
            || $transaction->payment_method !== 'card'
            || $transaction->status !== PaymentTransaction::APPROVED
            || $transaction->provider_status !== 'approved'
            || $transaction->provider_payment_id !== (string) ($payment['id'] ?? '')
            || $transaction->external_reference !== (string) ($payment['id'] ?? '')
            || $transaction->currency !== ($payment['currency_id'] ?? null)
            || $transaction->approved_scope_key !== PaymentTransaction::approvedScopeKeyForOrder((int) $order->id)) {
            return false;
        }
        try {
            $recordedAmount = $this->money->toMinor($transaction->amount);
            $orderAmount = $this->money->toMinor((string) $order->total);
            $remoteAmount = $this->money->toMinor((string) ($payment['transaction_amount'] ?? ''));
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $recordedAmount === $orderAmount && $remoteAmount === $orderAmount
            && $preference->gateway === 'mercadopago'
            && ($payment['gateway'] ?? null) === 'mercadopago'
            && ($payment['preference_id'] ?? null) === $preference->provider_preference_id
            && hash_equals($preference->external_reference, (string) ($payment['external_reference'] ?? ''));
    }

    private function lockRemoteTransaction(array $payment, string $gateway): ?PaymentTransaction
    {
        $paymentId = $payment['id'] ?? null;
        if (! is_string($paymentId) && ! is_int($paymentId)) {
            return null;
        }
        $paymentId = (string) $paymentId;
        if ($paymentId === '' || ($gateway === 'mercadopago' && preg_match('/^[1-9][0-9]*$/D', $paymentId) !== 1)) {
            return null;
        }

        return PaymentTransaction::where('gateway', $gateway)
            ->where('provider_payment_id', $paymentId)->lockForUpdate()->first();
    }

    /** Test seam for a physical lock observer; production keeps it empty and stateless. */
    protected function afterRemoteOrderLock(Order $order): void {}

    private function assertMatches(Order $order, PaymentPreference $preference, array $payment): void
    {
        if (($preference->gateway !== 'mock' || ($payment['gateway'] ?? null) === 'mercadopago')
            && ($preference->gateway !== 'mercadopago'
                || ($payment['gateway'] ?? null) !== 'mercadopago'
                || ! preg_match('/^[1-9][0-9]*$/D', (string) ($payment['id'] ?? ''))
                || ! preg_match('/^[1-9][0-9]*$/D', (string) ($payment['merchant_order_id'] ?? '')))) {
            throw ValidationException::withMessages(['payment' => [LinkFailure::CONTRADICTORY_EVIDENCE]]);
        }
        if (($payment['preference_id'] ?? null) !== $preference->provider_preference_id
            || ! hash_equals($preference->external_reference, (string) ($payment['external_reference'] ?? ''))) {
            throw ValidationException::withMessages(['payment' => [LinkFailure::CONTRADICTORY_EVIDENCE]]);
        }
        if ($preference->gateway !== 'mock') {
            try {
                $amount = $this->money->toMinor((string) ($payment['transaction_amount'] ?? ''));
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['payment' => [LinkFailure::REMOTE_EVIDENCE_INCOMPLETE]]);
            }
            if ($amount <= 0 || $amount !== $this->money->toMinor((string) $order->total)) {
                throw ValidationException::withMessages(['payment' => [LinkFailure::CONTRADICTORY_EVIDENCE]]);
            }
            $currency = config('payment.currency');
            if (! is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                throw new \RuntimeException('Invalid configured payment currency.');
            }
            if (! is_string($payment['currency_id'] ?? null) || preg_match('/^[A-Z]{3}$/D', $payment['currency_id']) !== 1) {
                throw ValidationException::withMessages(['payment' => [LinkFailure::REMOTE_EVIDENCE_INCOMPLETE]]);
            }
            if ($payment['currency_id'] !== $currency) {
                throw ValidationException::withMessages(['payment' => [LinkFailure::CONTRADICTORY_EVIDENCE]]);
            }
        }
    }

    private function safePaymentSnapshot(array $payment): array
    {
        return array_intersect_key($payment, array_flip(['id', 'status', 'status_detail', 'external_reference', 'preference_id', 'transaction_amount', 'currency_id', 'payment_method_id']));
    }
}
