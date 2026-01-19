<?php

namespace App\Services\PaymentGateway;

/**
 * Mock Payment Gateway for testing without real credentials
 * Simulates MercadoPago behavior for development/testing
 */
class MockPaymentGateway implements PaymentGatewayInterface
{
    public function createPayment(array $orderData): array
    {
        // Simulate creating a payment preference
        $mockId = 'MOCK-' . uniqid();

        // Generate mock checkout URL using url() helper (includes current host and port)
        $checkoutUrl = url('/mock-payment/' . $mockId . '?order_id=' . $orderData['order_id']);

        return [
            'id' => $mockId,
            'init_point' => $checkoutUrl,
            'sandbox_init_point' => $checkoutUrl,
        ];
    }

    public function verifyPayment(string $paymentId): array
    {
        // Simulate payment verification
        return [
            'id' => $paymentId,
            'status' => 'approved', // Always approved in mock
            'status_detail' => 'accredited',
            'external_reference' => null,
            'transaction_amount' => 0,
            'payment_method_id' => 'mock',
        ];
    }

    public function refundPayment(string $paymentId, float $amount): array
    {
        return [
            'id' => 'REFUND-' . uniqid(),
            'status' => 'approved',
            'amount' => $amount,
        ];
    }

    public function handleWebhook(array $payload): array
    {
        // Mock webhook handling
        return [
            'id' => $payload['payment_id'] ?? 'MOCK-PAYMENT',
            'status' => 'approved',
            'status_detail' => 'accredited',
            'external_reference' => $payload['order_id'] ?? null,
            'transaction_amount' => 0,
            'payment_method_id' => 'mock',
        ];
    }
}
