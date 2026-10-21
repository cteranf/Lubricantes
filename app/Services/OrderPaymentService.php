<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderPaymentService
{
    public function confirmCashOnDeliveryCollection(Order $order, User $collector, string $collectionMethod = 'cash', ?string $manualReference = null, $collectedAt = null, ?User $confirmer = null): PaymentTransaction
    {
        return DB::transaction(function () use ($order, $collector, $collectionMethod, $manualReference, $collectedAt, $confirmer) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->payment_method, ['contra_entrega', 'pago_en_sede'], true)) {
                $this->invalid('Solo los pedidos contraentrega o con pago en sede admiten cobro manual al entregar.');
            }
            if (! in_array($collectionMethod, ['cash', 'card_terminal', 'bank_transfer', 'other'], true)) {
                $this->invalid('El medio de cobro no es válido.');
            }

            $key = 'cod-collection-order-'.$order->id;
            $scope = PaymentTransaction::approvedScopeKeyForOrder($order->id);
            $existing = PaymentTransaction::where('idempotency_key', $key)->first();
            if ($existing) {
                if ((int) $existing->order_id !== (int) $order->id || ! in_array($existing->payment_method, ['contra_entrega', 'pago_en_sede'], true) || $existing->transaction_type !== PaymentTransaction::PAYMENT) {
                    $this->invalid('La clave de cobro ya pertenece a otra operación.');
                }
                $reference = $manualReference ? trim($manualReference) : null;
                if ($existing->collection_method !== $collectionMethod || $existing->manual_reference !== $reference) {
                    $this->invalid('El cobro ya fue confirmado con datos diferentes; no puede sobrescribirse.');
                }
                $this->syncProjection($order, $existing);

                return $existing;
            }
            if ($approved = PaymentTransaction::where('approved_scope_key', $scope)->first()) {
                $this->syncProjection($order, $approved);

                return $approved;
            }
            if ($approved = $this->approvedTransactionForOrder($order)) {
                $this->syncProjection($order, $approved);

                return $approved;
            }

            $at = $collectedAt ? Carbon::parse($collectedAt) : now();
            if ($at->isFuture()) {
                $this->invalid('La fecha efectiva del cobro no puede estar en el futuro.');
            }
            try {
                $transaction = PaymentTransaction::create([
                    'order_id' => $order->id, 'payment_method' => $order->payment_method, 'transaction_type' => PaymentTransaction::PAYMENT,
                    'status' => PaymentTransaction::APPROVED, 'amount' => $order->total, 'currency' => 'PEN', 'idempotency_key' => $key,
                    'approved_scope_key' => $scope, 'manual_reference' => $manualReference ? trim($manualReference) : null,
                    'collection_method' => $collectionMethod, 'collected_by' => $collector->id, 'collected_at' => $at,
                    'confirmed_by' => ($confirmer ?: $collector)->id, 'confirmed_at' => now(),
                    'metadata' => ['source' => 'delivery_confirmation', 'order_total_snapshot' => (string) $order->total],
                ]);
            } catch (QueryException $e) {
                $transaction = PaymentTransaction::where('idempotency_key', $key)->orWhere('approved_scope_key', $scope)->first();
                if (! $transaction) {
                    throw $e;
                }
            }
            $this->syncProjection($order, $transaction);

            return $transaction->fresh(['collector:id,name', 'confirmer:id,name']);
        });
    }

    public function recordMockCardAttempt(Order $order, string $paymentId, string $status, array $gatewayData = [], ?string $failureReason = null): PaymentTransaction
    {
        $currency = config('payment.currency');
        if (! is_string($currency) || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw new \InvalidArgumentException('La moneda configurada no es válida.');
        }

        return DB::transaction(function () use ($order, $paymentId, $status, $gatewayData, $failureReason, $currency) {
            Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $order = app(MockPaymentPreferenceResolver::class)->resolve($paymentId, $order->id);
            if (! in_array($status, [PaymentTransaction::FAILED, PaymentTransaction::APPROVED], true)
                || ($gatewayData['gateway'] ?? null) !== 'mock') {
                $this->invalid('El intento mock no es coherente.');
            }
            $preferenceId = (int) $order->current_payment_preference_id;
            $key = hash('sha256', "mock-card-attempt:v1\0".$order->id."\0".$preferenceId."\0".$status);
            $scope = $status === PaymentTransaction::APPROVED ? PaymentTransaction::approvedScopeKeyForOrder($order->id) : null;
            $existing = PaymentTransaction::where('idempotency_key', $key)->lockForUpdate()->get();
            if ($existing->isNotEmpty()) {
                $transaction = $existing->sole();
                if ((int) $transaction->order_id !== (int) $order->id
                    || (int) $transaction->payment_preference_id !== $preferenceId
                    || $transaction->payment_submission_id !== null
                    || $transaction->transaction_type !== PaymentTransaction::PAYMENT
                    || $transaction->payment_method !== 'card'
                    || $transaction->gateway !== 'mock'
                    || $transaction->status !== $status
                    || $transaction->amount !== $order->total
                    || $transaction->currency !== $currency
                    || $transaction->approved_scope_key !== $scope
                    || $transaction->external_reference !== $paymentId
                    || $transaction->provider_payment_id !== null
                    || $transaction->confirmed_by !== null
                    || $transaction->collected_by !== null
                    || ($transaction->metadata['source'] ?? null) !== 'card_gateway') {
                    $this->invalid('El intento mock no es coherente.');
                }

                return $transaction;
            }
            if (PaymentTransaction::where('order_id', $order->id)->where('transaction_type', PaymentTransaction::PAYMENT)->where('status', PaymentTransaction::APPROVED)->exists()) {
                $this->invalid('El intento mock no es coherente.');
            }

            // The order lock serializes mock writers; unexpected UNIQUE errors propagate.
            // Order/config remain the financial authority, not a preference snapshot.
            return PaymentTransaction::create([
                'order_id' => $order->id,
                'payment_preference_id' => $preferenceId,
                'payment_method' => 'card',
                'transaction_type' => PaymentTransaction::PAYMENT,
                'status' => $status,
                'amount' => $order->total,
                'currency' => $currency,
                'idempotency_key' => $key,
                'approved_scope_key' => $scope,
                'external_reference' => $paymentId,
                'gateway' => 'mock',
                'confirmed_at' => $status === PaymentTransaction::APPROVED ? now() : null,
                'failed_at' => $status === PaymentTransaction::FAILED ? now() : null,
                'failure_reason' => $failureReason,
                'metadata' => ['source' => 'card_gateway', 'order_total_snapshot' => (string) $order->total],
            ]);
        });
    }

    public function recordCardAttempt(
        Order $order,
        string $paymentId,
        string $status,
        array $gatewayData = [],
        ?string $failureReason = null,
        ?string $currency = null
    ): PaymentTransaction {
        if ($currency !== null && (! is_string($currency) || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1)) {
            throw new \InvalidArgumentException('La moneda configurada no es válida.');
        }
        $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
        $isApproved = $status === PaymentTransaction::APPROVED;
        $scope = $isApproved ? PaymentTransaction::approvedScopeKeyForOrder($order->id) : null;
        $gateway = $gatewayData['gateway'] ?? null;
        $idempotencyKey = $gateway
            ? 'gateway-'.$gateway.'-payment-'.$paymentId
            : ($isApproved ? 'card-approved-order-'.$order->id.'-'.$paymentId : 'card-attempt-order-'.$order->id.'-'.$paymentId.'-'.Str::random(8));

        if ($isApproved) {
            $existing = PaymentTransaction::where('approved_scope_key', $scope)->first();
            if ($existing) {
                return $existing;
            }
            if ($existing = $this->approvedTransactionForOrder($order)) {
                return $existing;
            }
        }
        if ($gateway && ($existing = PaymentTransaction::where('gateway', $gateway)->where('provider_payment_id', $paymentId)->first())) {
            if ((int) $existing->order_id !== (int) $order->id) {
                $this->invalid('El pago remoto ya fue asociado a otro pedido.');
            }

            return $existing;
        }

        try {
            $transaction = PaymentTransaction::create([
                'order_id' => $order->id,
                'payment_method' => 'card',
                'transaction_type' => PaymentTransaction::PAYMENT,
                'status' => $status,
                'amount' => $order->total,
                'currency' => $currency ?? 'PEN',
                'idempotency_key' => $idempotencyKey,
                'approved_scope_key' => $scope,
                'external_reference' => $paymentId,
                'gateway' => $gateway,
                'provider_payment_id' => $gatewayData['provider_payment_id'] ?? null,
                'payment_preference_id' => $gatewayData['payment_preference_id'] ?? null,
                'provider_status' => $gatewayData['provider_status'] ?? null,
                'provider_status_detail' => $gatewayData['provider_status_detail'] ?? null,
                'verified_at' => $gatewayData['verified_at'] ?? null,
                'confirmed_at' => $isApproved ? now() : null,
                'failed_at' => $isApproved ? null : now(),
                'failure_reason' => $failureReason,
                'metadata' => array_merge([
                    'source' => 'card_gateway',
                    'order_total_snapshot' => (string) $order->total,
                ], array_filter($gatewayData, fn ($key) => ! in_array($key, ['gateway', 'provider_payment_id', 'payment_preference_id', 'provider_status', 'provider_status_detail', 'verified_at'], true), ARRAY_FILTER_USE_KEY)),
            ]);
        } catch (QueryException $e) {
            if ($isApproved && $scope) {
                $transaction = PaymentTransaction::where('approved_scope_key', $scope)->first();
                if ($transaction) {
                    return $transaction;
                }
            }
            throw $e;
        }

        return $transaction;
    }

    public function approvedCashOnDeliveryFor(Order $order): ?PaymentTransaction
    {
        return PaymentTransaction::where('order_id', $order->id)
            ->whereIn('payment_method', ['contra_entrega', 'pago_en_sede'])
            ->where('transaction_type', PaymentTransaction::PAYMENT)
            ->where('status', PaymentTransaction::APPROVED)
            ->first();
    }

    public function approvedPaymentFor(Order $order): ?PaymentTransaction
    {
        return PaymentTransaction::where('order_id', $order->id)
            ->where('transaction_type', PaymentTransaction::PAYMENT)
            ->where('status', PaymentTransaction::APPROVED)
            ->first();
    }

    private function approvedTransactionForOrder(Order $order): ?PaymentTransaction
    {
        return PaymentTransaction::where('order_id', $order->id)
            ->where('transaction_type', PaymentTransaction::PAYMENT)
            ->where('status', PaymentTransaction::APPROVED)
            ->first();
    }

    private function syncProjection(Order $order, PaymentTransaction $transaction): void
    {
        if ($transaction->status === PaymentTransaction::APPROVED && ($order->payment_status !== 'approved' || ! $order->paid_at)) {
            $order->update(['payment_status' => 'approved', 'paid_at' => $order->paid_at ?: $transaction->collected_at]);
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['payment' => [$message]]);
    }
}
