<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentPreference;
use App\Models\PaymentSetting;
use App\Models\User;
use App\Services\PaymentGateway\MercadoPagoGateway;
use App\Services\PaymentPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;
use Tests\TestCase;

class MercadoPagoPreferenceHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_productive_request_options_use_exact_lowercase_key_and_sdk_resolves_same_value_on_repetition(): void
    {
        $this->withIsolatedSdk(function ($transport) {
            $data = $this->orderData();
            $gateway = new MercadoPagoGateway;
            $gateway->createPayment($data);
            $gateway->createPayment($data);
            $this->assertTrue(count($transport->checks) === 2, 'Both executions must reach only the in-memory SDK transport.');
            foreach ($transport->checks as $check) {
                $this->assertTrue($check === ['exact_custom_key' => true, 'same_value' => true, 'sdk_resolved_same_value' => true], 'Productive options and real SDK resolution must preserve the exact idempotency contract.');
            }
            $this->assertTrue($transport->matchesKey($data['idempotency_key']), 'Repetition must preserve the supplied key byte for byte.');
        });
    }

    public function test_real_preference_creation_preserves_persisted_key_and_reuses_active_intent(): void
    {
        $this->withIsolatedSdk(function ($transport) {
            $order = Order::create(['user_id' => User::factory()->create()->id, 'status' => 'pending', 'total' => 10, 'shipping_info' => [], 'payment_method' => 'card', 'payment_status' => 'pending', 'delivery_type' => 'delivery', 'tracking_status' => 'pending']);
            $service = app(PaymentPreferenceService::class);
            $first = $service->createFor($order, $this->orderData(), 'mercadopago');
            $before = $first->getRawOriginal();
            $second = $service->createFor($order, $this->orderData(), 'mercadopago');
            $this->assertTrue($first->state === PaymentPreference::ACTIVE && $second->id === $first->id && $before === $second->getRawOriginal(), 'Successful repeated creation must reuse the same persisted active intent.');
            $this->assertTrue($transport->matchesKey($first->idempotency_key), 'SDK must receive exactly the persisted idempotency value.');
            $this->assertTrue(count($transport->checks) === 1 && $transport->checks[0] === ['exact_custom_key' => true, 'same_value' => true, 'sdk_resolved_same_value' => true], 'Active intent reuse must not issue a second SDK request.');
            $this->assertSame(1, PaymentPreference::where('order_id', $order->id)->count());
        });
    }

    private function withIsolatedSdk(\Closure $callback): void
    {
        $this->assertTrue(config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === ':memory:', 'Header regression requires isolated in-memory SQLite.');
        $clientProperty = new \ReflectionProperty(MercadoPagoConfig::class, 'http_client');
        $originalClient = $clientProperty->getValue();
        $originalToken = MercadoPagoConfig::getAccessToken();
        $transport = new class implements MPHttpClient
        {
            public array $checks = [];

            private ?string $expectedKey = null;

            public function send(MPRequest $request): MPResponse
            {
                $headers = $request->getHeaders() ?? [];
                $custom = array_filter($headers, static fn ($key) => is_string($key), ARRAY_FILTER_USE_KEY);
                $value = $custom['x-idempotency-key'] ?? null;
                $this->expectedKey ??= is_string($value) ? $value : null;
                // The real SDK appends this header by executing getIdempotencyKey().
                $resolved = array_values(array_filter($headers, static fn ($header) => is_string($header) && str_starts_with($header, 'X-Idempotency-Key: ')));
                $this->checks[] = [
                    'exact_custom_key' => array_keys($custom) === ['x-idempotency-key'],
                    'same_value' => is_string($value) && $value === $this->expectedKey,
                    'sdk_resolved_same_value' => count($resolved) === 1 && substr($resolved[0], strlen('X-Idempotency-Key: ')) === $this->expectedKey,
                ];

                return new MPResponse(201, ['id' => 'synthetic-preference', 'init_point' => 'https://invalid.example/checkout', 'sandbox_init_point' => 'https://invalid.example/sandbox']);
            }

            public function matchesKey(string $key): bool
            {
                return $this->expectedKey === $key;
            }
        };
        try {
            PaymentSetting::current()->update(['mercadopago_access_token' => 'SYNTHETIC_TEST_TOKEN']);
            MercadoPagoConfig::setHttpClient($transport);
            $callback($transport);
        } finally {
            $clientProperty->setValue(null, $originalClient);
            MercadoPagoConfig::setAccessToken($originalToken);
        }
    }

    private function orderData(): array
    {
        return [
            'items' => [['name' => 'Synthetic item', 'quantity' => 1, 'price' => 10]],
            'customer' => ['name' => 'Synthetic buyer', 'email' => 'buyer@example.invalid'],
            'external_reference' => 'SYNTHETIC_REFERENCE',
            'idempotency_key' => 'SYNTHETIC_EXISTING_KEY',
        ];
    }
}
