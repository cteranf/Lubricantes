<?php

namespace App\Services\PaymentGateway;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Mock Payment Gateway for testing without real credentials
 * Simulates card payment behavior for development and test environments
 */
class MockPaymentGateway implements PaymentGatewayInterface
{
    public function createPayment(array $orderData): array
    {
        $mockId = 'MOCK-'.Str::random(32);

        $checkoutUrl = URL::temporarySignedRoute('mock.payment', now()->addMinutes(30), [
            'paymentId' => $mockId,
            'order_id' => $orderData['order_id'],
        ]);

        return [
            'id' => $mockId,
            'init_point' => $checkoutUrl,
            'sandbox_init_point' => $checkoutUrl,
        ];
    }

    public function verifyPayment(string $paymentId): array
    {
        return [
            'id' => $paymentId,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'external_reference' => null,
            'transaction_amount' => 0,
            'payment_method_id' => 'mock_card',
        ];
    }

    public function refundPayment(string $paymentId, float $amount): array
    {
        return [
            'id' => 'REFUND-'.Str::random(32),
            'status' => 'approved',
            'amount' => $amount,
        ];
    }

    public function handleWebhook(array $payload): array
    {
        return [
            'id' => $payload['payment_id'] ?? 'MOCK-PAYMENT',
            'status' => $payload['status'] ?? 'approved',
            'status_detail' => 'accredited',
            'external_reference' => $payload['order_id'] ?? null,
            'transaction_amount' => 0,
            'payment_method_id' => 'mock_card',
        ];
    }
}
