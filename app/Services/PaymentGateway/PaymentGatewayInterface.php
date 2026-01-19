<?php

namespace App\Services\PaymentGateway;

interface PaymentGatewayInterface
{
    /**
     * Create a payment preference/order
     * 
     * @param array $orderData Order details (items, amount, customer info)
     * @return array Payment data (id, checkout_url, etc.)
     */
    public function createPayment(array $orderData): array;

    /**
     * Verify payment status
     * 
     * @param string $paymentId External payment ID
     * @return array Payment status data
     */
    public function verifyPayment(string $paymentId): array;

    /**
     * Process refund
     * 
     * @param string $paymentId External payment ID
     * @param float $amount Amount to refund
     * @return array Refund result
     */
    public function refundPayment(string $paymentId, float $amount): array;

    /**
     * Handle webhook notification from payment provider
     * 
     * @param array $payload Webhook payload
     * @return array Processed webhook data
     */
    public function handleWebhook(array $payload): array;
}
