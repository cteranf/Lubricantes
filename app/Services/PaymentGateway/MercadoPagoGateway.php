<?php

namespace App\Services\PaymentGateway;

use App\Exceptions\PaymentLinkEvidenceException as LinkFailure;
use App\Services\MoneyService;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\Client\MerchantOrder\MerchantOrderClient;
use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\MercadoPagoConfig;

class MercadoPagoGateway implements PaymentGatewayInterface
{
    public function __construct(?string $accessToken = null)
    {
        $token = $accessToken === null || $accessToken === '' ? config('payment.mercadopago.access_token') : $accessToken;

        if (! is_string($token) || $token === '' || trim($token) !== $token || preg_match('/\s/', $token)) {
            throw new \RuntimeException('La credencial de Mercado Pago no es una configuracion valida.');
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
        $options->setCustomHeaders(['x-idempotency-key' => $orderData['idempotency_key']]);
        $preference = $client->create($preferenceData, $options);
        if (! isset($preference->id, $preference->init_point, $preference->sandbox_init_point)) {
            throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
        }
        $this->requireText($preference->id);
        $this->requireText($preference->init_point);
        $this->requireText($preference->sandbox_init_point);

        return [
            'id' => $preference->id,
            'init_point' => $preference->init_point,
            'sandbox_init_point' => $preference->sandbox_init_point,
            'external_reference' => $orderData['external_reference'],
        ];
    }

    public function verifyPayment(string $paymentId): array
    {
        if (! preg_match('/^[1-9][0-9]*$/D', $paymentId)) {
            throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
        }
        $payment = (new PaymentClient)->get($paymentId);
        if (! isset($payment->id, $payment->status, $payment->external_reference, $payment->transaction_amount, $payment->currency_id, $payment->order)
            || ! is_object($payment->order) || ! isset($payment->order->id)) {
            throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
        }
        $this->requireIdentity($payment->id);
        $this->requireIdentity($payment->order->id);
        $this->requireText($payment->status);
        $this->requireText($payment->external_reference);
        $raw = $payment->getResponse()->getContent();
        $this->requireIdentity($raw['id'] ?? null);
        $this->requireText($raw['status'] ?? null);
        $this->requireText($raw['external_reference'] ?? null);
        if (! is_array($raw['order'] ?? null)) {
            throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
        }
        $this->requireIdentity($raw['order']['id'] ?? null);
        if ((string) $payment->id !== $paymentId) {
            throw new LinkFailure(LinkFailure::CONTRADICTORY_EVIDENCE);
        }
        $merchantOrder = $this->retrieveEvidence(fn () => (new MerchantOrderClient)->get($payment->order->id));
        if (! isset($merchantOrder->id, $merchantOrder->preference_id, $merchantOrder->external_reference, $merchantOrder->payments, $merchantOrder->total_amount)
            || ! is_array($merchantOrder->payments)) {
            throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
        }
        $this->requireIdentity($merchantOrder->id);
        $this->requireText($merchantOrder->preference_id);
        $this->requireText($merchantOrder->external_reference);
        $orderRaw = $merchantOrder->getResponse()->getContent();
        $this->requireIdentity($orderRaw['id'] ?? null);
        $this->requireText($orderRaw['preference_id'] ?? null);
        $this->requireText($orderRaw['external_reference'] ?? null);
        if (! is_array($orderRaw['payments'] ?? null) || ! array_is_list($orderRaw['payments'])) {
            throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
        }
        if ($merchantOrder->id !== $payment->order->id || $merchantOrder->external_reference !== $payment->external_reference) {
            throw new LinkFailure(LinkFailure::CONTRADICTORY_EVIDENCE);
        }
        foreach ($orderRaw['payments'] as $member) {
            if (! is_array($member)) {
                throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
            }
            $this->requireIdentity($member['id'] ?? null);
        }
        $members = array_values(array_filter($orderRaw['payments'], static fn ($member) => $member['id'] === $payment->id));
        if (count($members) !== 1) {
            throw new LinkFailure(LinkFailure::CONTRADICTORY_EVIDENCE);
        }
        foreach ([$raw['transaction_amount'] ?? null, $members[0]['transaction_amount'] ?? null, $orderRaw['total_amount'] ?? null] as $value) {
            if (! is_int($value) && ! is_float($value)) {
                throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
            }
        }
        foreach ([$raw['currency_id'] ?? null, $members[0]['currency_id'] ?? null] as $currency) {
            if (! is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
            }
        }
        $money = new MoneyService;
        try {
            $amount = $money->toMinor((string) $raw['transaction_amount']);
            $memberAmount = $money->toMinor((string) $members[0]['transaction_amount']);
            $remoteOrderAmount = $money->toMinor((string) $orderRaw['total_amount']);
        } catch (\InvalidArgumentException $exception) {
            throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE, $exception);
        }
        if ($amount <= 0 || $amount !== $memberAmount || $amount !== $remoteOrderAmount
            || $members[0]['currency_id'] !== $payment->currency_id) {
            throw new LinkFailure(LinkFailure::CONTRADICTORY_EVIDENCE);
        }

        return [
            'gateway' => 'mercadopago',
            'id' => $payment->id,
            'status' => $payment->status ?? null,
            'external_reference' => $payment->external_reference ?? null,
            'transaction_amount' => $payment->transaction_amount,
            'currency_id' => $payment->currency_id ?? null,
            'preference_id' => $merchantOrder->preference_id,
            'merchant_order_id' => $merchantOrder->id,
        ];
    }

    private function retrieveEvidence(\Closure $retrieve): object
    {
        try {
            return $retrieve();
        } catch (MPApiException $exception) {
            if ($exception->getStatusCode() === 404) {
                throw new LinkFailure(LinkFailure::REMOTE_RESOURCE_ABSENT, $exception);
            }
            throw $exception;
        }
    }

    private function requireIdentity(mixed $id): void
    {
        if (! is_int($id) || $id <= 0) {
            throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
        }
    }

    private function requireText(mixed $value): void
    {
        if (! is_string($value) || trim($value) === '') {
            throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
        }
    }

    public function findPreference(string $preferenceId): array
    {
        $preference = (new PreferenceClient)->get($preferenceId);
        if (! isset($preference->id)) {
            throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
        }
        $this->requireText($preference->id);

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
