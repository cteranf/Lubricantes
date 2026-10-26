<?php

namespace App\Services\PaymentGateway;

use App\Exceptions\PaymentLinkEvidenceException as LinkFailure;
use App\Services\MoneyService;
use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Client\MerchantOrder\MerchantOrderClient;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;

class MercadoPagoGateway implements PaymentGatewayInterface
{
    private const SDK_ID_OVERFLOW_MESSAGE = 'Mercado Pago identity exceeds the SDK integer range.';

    private const SDK_ID_CONVERSION_MESSAGE = 'Mercado Pago identity conversion failed.';

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
        $requestedPaymentId = $this->requireIdentity($paymentId);
        $sdkPaymentId = $this->sdkIntegerIdentity($requestedPaymentId);
        $payment = (new PaymentClient)->get($sdkPaymentId);
        $raw = $payment->getResponse()->getContent();
        if (! is_array($raw) || ! is_array($raw['order'] ?? null)) {
            throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
        }
        $paymentIdentity = $this->requireIdentity($raw['id'] ?? null);
        $paymentOrderIdentity = $this->requireIdentity($raw['order']['id'] ?? null);
        $sdkPaymentOrderId = $this->sdkIntegerIdentity($paymentOrderIdentity);
        if (! isset($payment->id, $payment->status, $payment->external_reference, $payment->transaction_amount, $payment->currency_id, $payment->order)
            || ! is_object($payment->order) || ! isset($payment->order->id)) {
            throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
        }
        $dtoPaymentIdentity = $this->requireIdentity($payment->id);
        $dtoPaymentOrderIdentity = $this->requireIdentity($payment->order->id);
        if ($paymentIdentity !== $dtoPaymentIdentity || $paymentOrderIdentity !== $dtoPaymentOrderIdentity) {
            throw new LinkFailure(LinkFailure::CONTRADICTORY_EVIDENCE);
        }
        $this->requireText($payment->status);
        $this->requireText($payment->external_reference);
        $this->requireText($raw['status'] ?? null);
        $this->requireText($raw['external_reference'] ?? null);
        if ($paymentIdentity !== $requestedPaymentId) {
            throw new LinkFailure(LinkFailure::CONTRADICTORY_EVIDENCE);
        }
        $merchantOrder = $this->retrieveEvidence(fn () => (new MerchantOrderClient)->get($sdkPaymentOrderId));
        $orderRaw = $merchantOrder->getResponse()->getContent();
        if (! is_array($orderRaw)) {
            throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
        }
        $merchantOrderIdentity = $this->requireIdentity($orderRaw['id'] ?? null);
        if (! is_array($orderRaw['payments'] ?? null) || ! array_is_list($orderRaw['payments'])) {
            throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
        }
        $memberIdentities = [];
        foreach ($orderRaw['payments'] as $member) {
            if (! is_array($member)) {
                throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
            }
            $memberIdentity = $this->requireIdentity($member['id'] ?? null);
            $memberIdentities[] = $memberIdentity;
        }
        if (! isset($merchantOrder->id, $merchantOrder->preference_id, $merchantOrder->external_reference, $merchantOrder->payments, $merchantOrder->total_amount)
            || ! is_array($merchantOrder->payments)) {
            throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
        }
        $dtoMerchantOrderIdentity = $this->requireIdentity($merchantOrder->id);
        if ($merchantOrderIdentity !== $dtoMerchantOrderIdentity) {
            throw new LinkFailure(LinkFailure::CONTRADICTORY_EVIDENCE);
        }
        $this->requireText($merchantOrder->preference_id);
        $this->requireText($merchantOrder->external_reference);
        $this->requireText($orderRaw['preference_id'] ?? null);
        $this->requireText($orderRaw['external_reference'] ?? null);
        if ($merchantOrderIdentity !== $paymentOrderIdentity
            || $merchantOrder->external_reference !== $payment->external_reference) {
            throw new LinkFailure(LinkFailure::CONTRADICTORY_EVIDENCE);
        }
        if (count($merchantOrder->payments) !== count($memberIdentities)) {
            throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
        }
        foreach ($memberIdentities as $index => $memberIdentity) {
            $memberDto = $merchantOrder->payments[$index] ?? null;
            if (! is_object($memberDto) || ! isset($memberDto->id)) {
                throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
            }
            $dtoMemberIdentity = $this->requireIdentity($memberDto->id);
            if ($memberIdentity !== $dtoMemberIdentity) {
                throw new LinkFailure(LinkFailure::CONTRADICTORY_EVIDENCE);
            }
        }
        $matchingMember = null;
        $matchingMembers = 0;
        foreach ($orderRaw['payments'] as $index => $member) {
            $memberIdentity = $memberIdentities[$index];
            if ($memberIdentity === $paymentIdentity) {
                $matchingMember = $member;
                $matchingMembers++;
            }
        }
        if ($matchingMembers !== 1 || ! is_array($matchingMember)) {
            throw new LinkFailure(LinkFailure::CONTRADICTORY_EVIDENCE);
        }
        foreach ([$raw['transaction_amount'] ?? null, $matchingMember['transaction_amount'] ?? null, $orderRaw['total_amount'] ?? null] as $value) {
            if (! is_int($value) && ! is_float($value)) {
                throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
            }
        }
        foreach ([$raw['currency_id'] ?? null, $matchingMember['currency_id'] ?? null] as $currency) {
            if (! is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
            }
        }
        $money = new MoneyService;
        try {
            $amount = $money->toMinor((string) $raw['transaction_amount']);
            $memberAmount = $money->toMinor((string) $matchingMember['transaction_amount']);
            $remoteOrderAmount = $money->toMinor((string) $orderRaw['total_amount']);
        } catch (\InvalidArgumentException $exception) {
            throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE, $exception);
        }
        if ($amount <= 0 || $amount !== $memberAmount || $amount !== $remoteOrderAmount
            || $matchingMember['currency_id'] !== $payment->currency_id) {
            throw new LinkFailure(LinkFailure::CONTRADICTORY_EVIDENCE);
        }

        return [
            'gateway' => 'mercadopago',
            'id' => $paymentIdentity,
            'status' => $payment->status ?? null,
            'external_reference' => $payment->external_reference ?? null,
            'transaction_amount' => $payment->transaction_amount,
            'currency_id' => $payment->currency_id ?? null,
            'preference_id' => $merchantOrder->preference_id,
            'merchant_order_id' => $merchantOrderIdentity,
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

    private function requireIdentity(mixed $id): string
    {
        if (is_int($id) && $id > 0) {
            return (string) $id;
        }

        if (is_string($id) && preg_match('/\A[1-9][0-9]*\z/D', $id) === 1) {
            return $id;
        }

        throw new LinkFailure(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
    }

    private function sdkIntegerIdentity(string $canonicalId): int
    {
        if (preg_match('/\A[1-9][0-9]*\z/D', $canonicalId) !== 1) {
            throw new \LogicException(self::SDK_ID_CONVERSION_MESSAGE);
        }

        $maximum = (string) PHP_INT_MAX;
        $length = strlen($canonicalId);
        $maximumLength = strlen($maximum);
        if ($length > $maximumLength || ($length === $maximumLength && strcmp($canonicalId, $maximum) > 0)) {
            throw new \OverflowException(self::SDK_ID_OVERFLOW_MESSAGE);
        }

        $sdkId = (int) $canonicalId;
        if ((string) $sdkId !== $canonicalId) {
            throw new \UnexpectedValueException(self::SDK_ID_CONVERSION_MESSAGE);
        }

        return $sdkId;
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
