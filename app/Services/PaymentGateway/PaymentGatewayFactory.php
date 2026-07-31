<?php

namespace App\Services\PaymentGateway;

class PaymentGatewayFactory
{
    /**
     * Create payment gateway instance based on configuration
     *
     * @param  string|null  $provider  Override default provider
     */
    public static function create(?string $provider = null): PaymentGatewayInterface
    {
        $provider = $provider ?? config('payment.default_gateway');

        if ($provider === 'mock' && ! config('payment.mock.enabled')) {
            throw new \RuntimeException('Mock payment gateway is disabled.');
        }

        return match ($provider) {
            'mercadopago' => new MercadoPagoGateway,
            'mock' => new MockPaymentGateway, // For testing without credentials
            // Future providers can be added here:
            // 'niubiz' => new NiubizGateway(),
            // 'culqi' => new CulqiGateway(),
            default => throw new \Exception("Payment gateway '{$provider}' not supported"),
        };
    }
}
