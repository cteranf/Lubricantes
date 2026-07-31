<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Payment Gateway
    |--------------------------------------------------------------------------
    |
    | This option controls the default payment gateway that will be used.
    | You can change this to switch between providers without code changes.
    |
    */
    'default_gateway' => env('PAYMENT_GATEWAY', 'mercadopago'),

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    */
    'currency' => 'PEN',

    'mock' => [
        'enabled' => env('PAYMENT_MOCK_ENABLED', env('APP_ENV', 'production') !== 'production'),
    ],

    /*
    |--------------------------------------------------------------------------
    | MercadoPago Configuration
    |--------------------------------------------------------------------------
    */
    'mercadopago' => [
        'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'sandbox' => env('MERCADOPAGO_SANDBOX', true),
        'success_url' => env('APP_URL').'/orders/payment-return?result=approved',
        'failure_url' => env('APP_URL').'/orders/payment-return?result=rejected',
        'pending_url' => env('APP_URL').'/orders/payment-return?result=pending',
        'webhook_url' => env('APP_URL').'/api/v1/payment/webhook',
    ],

    /*
    |--------------------------------------------------------------------------
    | Niubiz Configuration (Future)
    |--------------------------------------------------------------------------
    */
    'niubiz' => [
        'merchant_id' => env('NIUBIZ_MERCHANT_ID'),
        'access_key' => env('NIUBIZ_ACCESS_KEY'),
        'secret_key' => env('NIUBIZ_SECRET_KEY'),
        'sandbox' => env('NIUBIZ_SANDBOX', true),
    ],
];
