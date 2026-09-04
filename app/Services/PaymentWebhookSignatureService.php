<?php

namespace App\Services;

use MercadoPago\Webhook\WebhookSignatureValidator;

class PaymentWebhookSignatureService
{
    public function validate(?string $signature, ?string $requestId, ?string $dataId): void
    {
        $secret = (string) config('payment.mercadopago.webhook_secret');
        if ($secret === '') {
            throw new \RuntimeException('Webhook secret is not configured.');
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
