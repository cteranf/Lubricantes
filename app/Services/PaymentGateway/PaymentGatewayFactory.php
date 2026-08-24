<?php

namespace App\Services\PaymentGateway;

use App\Services\PaymentSettingService;

class PaymentGatewayFactory
{
    /**
     * Create payment gateway instance based on configuration and database settings.
     *
     * @param  string|null  $provider  Override default provider
     */
    public static function create(?string $provider = null): PaymentGatewayInterface
    {
        $settingService = app(PaymentSettingService::class);
        $provider = $provider ?? $settingService->getActiveGateway();

        if ($provider === 'mock') {
            if (! $settingService->isMockActive()) {
                throw new \RuntimeException('Mock payment gateway is only available in local/testing environments with mock enabled.');
            }

            return new MockPaymentGateway;
        }

        return match ($provider) {
            'mercadopago' => new MercadoPagoGateway,
            'mock' => new MockPaymentGateway,
            default => throw new \Exception("Payment gateway '{$provider}' not supported"),
        };
    }
}
