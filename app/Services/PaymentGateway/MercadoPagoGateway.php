<?php

namespace App\Services\PaymentGateway;

use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\MercadoPagoConfig;

class MercadoPagoGateway implements PaymentGatewayInterface
{
    public function __construct()
    {
        MercadoPagoConfig::setAccessToken(config('payment.mercadopago.access_token'));
    }

    public function createPayment(array $orderData): array
    {
        $client = new PreferenceClient;

        // Prepare items
        $items = [];
        foreach ($orderData['items'] as $itemData) {
            $items[] = [
                'title' => $itemData['name'],
                'quantity' => (int) $itemData['quantity'],
                'unit_price' => (float) $itemData['price'],
            ];
        }

        // Prepare preference data
        $preferenceData = [
            'items' => $items,
            'payer' => [
                'name' => $orderData['customer']['name'],
                'email' => $orderData['customer']['email'],
            ],
            'back_urls' => [
                'success' => config('payment.mercadopago.success_url'),
                'failure' => config('payment.mercadopago.failure_url'),
                'pending' => config('payment.mercadopago.pending_url'),
            ],
            'auto_return' => 'approved',
            'external_reference' => (string) $orderData['order_id'],
            'notification_url' => config('payment.mercadopago.webhook_url'),
        ];

        $preference = $client->create($preferenceData);

        return [
            'id' => $preference->id,
            'init_point' => $preference->init_point,
            'sandbox_init_point' => $preference->sandbox_init_point,
        ];
    }

    public function verifyPayment(string $paymentId): array
    {
        $client = new PaymentClient;
        $payment = $client->get($paymentId);

        return [
            'id' => $payment->id,
            'status' => $payment->status,
            'status_detail' => $payment->status_detail,
            'external_reference' => $payment->external_reference ?? null,
            'transaction_amount' => $payment->transaction_amount,
            'currency_id' => $payment->currency_id ?? null,
            'payment_method_id' => $payment->payment_method_id,
        ];
    }

    public function refundPayment(string $paymentId, float $amount): array
    {
        // Note: Refunds in MercadoPago SDK v3 require RefundClient
        // For now, we'll return a placeholder since refunds are not critical for initial implementation
        return [
            'id' => null,
            'status' => 'pending',
            'amount' => $amount,
        ];
    }

    public function handleWebhook(array $payload): array
    {
        // MercadoPago sends payment ID in the notification
        if (isset($payload['data']['id'])) {
            $paymentId = $payload['data']['id'];

            return $this->verifyPayment($paymentId);
        }

        throw new \Exception('Invalid webhook payload');
    }
}
