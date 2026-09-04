<?php

namespace App\Services\PaymentGateway;

use App\Models\PaymentSetting;
use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\MercadoPagoConfig;

class MercadoPagoGateway implements PaymentGatewayInterface
{
    public function __construct()
    {
        $setting = PaymentSetting::current();
        $token = $setting->mercadopago_access_token ?: config('payment.mercadopago.access_token');

        if (empty($token)) {
            throw new \RuntimeException('El Access Token de Mercado Pago no ha sido configurado en el sistema.');
        }

        MercadoPagoConfig::setAccessToken($token);
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
            'external_reference' => $orderData['external_reference'],
            'notification_url' => config('payment.mercadopago.webhook_url'),
        ];

        $options = new RequestOptions;
        $options->setCustomHeaders(['X-Idempotency-Key' => $orderData['idempotency_key']]);
        $preference = $client->create($preferenceData, $options);

        return [
            'id' => $preference->id,
            'init_point' => $preference->init_point,
            'sandbox_init_point' => $preference->sandbox_init_point,
            'external_reference' => $orderData['external_reference'],
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
            'preference_id' => $payment->preference_id ?? null,
        ];
    }

    public function findPreference(string $preferenceId): array
    {
        $preference = (new PreferenceClient)->get($preferenceId);

        return ['id' => $preference->id, 'external_reference' => $preference->external_reference ?? null, 'init_point' => $preference->init_point ?? null, 'sandbox_init_point' => $preference->sandbox_init_point ?? null];
    }

    public function refundPayment(string $paymentId, float $amount): array
    {
        return [
            'id' => null,
            'status' => 'pending',
            'amount' => $amount,
        ];
    }

    public function handleWebhook(array $payload): array
    {
        if (isset($payload['data']['id'])) {
            $paymentId = $payload['data']['id'];

            return $this->verifyPayment($paymentId);
        }

        throw new \Exception('Invalid webhook payload');
    }
}
