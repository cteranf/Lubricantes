<?php

namespace App\Services;

use MercadoPago\Webhook\WebhookSignatureValidator;
use MercadoPago\Exceptions\InvalidWebhookSignatureException;
use MercadoPago\Exceptions\SignatureFailureReason;

class PaymentWebhookSignatureService
{
    public function validate(?string $signature, ?string $requestId, ?string $dataId): void
    {
        $secret = config('payment.mercadopago.webhook_secret');
        if (! is_string($secret) || trim($secret) === '') {
            throw new \RuntimeException('Webhook secret is not configured.');
        }
        if (! is_string($requestId) || preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $requestId) !== 1 || ! is_string($signature)) {
            throw new InvalidWebhookSignatureException(SignatureFailureReason::MALFORMED_SIGNATURE_HEADER);
        }
        $parts = [];
        foreach (explode(',', $signature) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2 || isset($parts[$pair[0]]) || ! in_array($pair[0], ['ts', 'v1'], true)) {
                throw new InvalidWebhookSignatureException(SignatureFailureReason::MALFORMED_SIGNATURE_HEADER);
            }
            $parts[$pair[0]] = $pair[1];
        }
        if (preg_match('/^[1-9][0-9]{0,11}$/D', $parts['ts'] ?? '') !== 1 || preg_match('/^[a-f0-9]{64}$/D', $parts['v1'] ?? '') !== 1) {
            throw new InvalidWebhookSignatureException(SignatureFailureReason::MALFORMED_SIGNATURE_HEADER);
        }

        WebhookSignatureValidator::validate(
            $signature,
            $requestId,
            $dataId,
            $secret,
            max(1, (int) config('payment.mercadopago.webhook_tolerance_seconds', 300)),
        );
    }
}
