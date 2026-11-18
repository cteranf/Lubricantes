<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentPreference;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\ProductPromotion;
use App\Models\User;
use App\Services\PaymentGateway\MercadoPagoGateway;
use App\Services\PaymentPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;
use Tests\TestCase;
use Tests\Feature\Concerns\SyntheticMercadoPagoLink;

class MercadoPagoPreferenceHeaderTest extends TestCase
{
    use RefreshDatabase;
    use SyntheticMercadoPagoLink;

    public function test_productive_request_options_use_exact_lowercase_key_and_sdk_resolves_same_value_on_repetition(): void
    {
        $this->withIsolatedSdk(function ($transport) {
            $data = $this->orderData();
            $gateway = new MercadoPagoGateway(PaymentSetting::current()->mercadopago_access_token);
            $gateway->createPayment($data);
            $gateway->createPayment($data);
            $this->assertTrue(count($transport->checks) === 2, 'Both executions must reach only the in-memory SDK transport.');
            foreach ($transport->checks as $check) {
                $this->assertTrue($check === ['exact_custom_key' => true, 'same_value' => true, 'sdk_resolved_same_value' => true], 'Productive options and real SDK resolution must preserve the exact idempotency contract.');
            }
            $this->assertTrue($transport->matchesKey($data['idempotency_key']), 'Repetition must preserve the supplied key byte for byte.');
        });
    }

    public function test_mercado_pago_preference_uses_persisted_order_prices_and_shipping_total(): void
    {
        $this->withIsolatedSdk(function ($transport) {
            $data = $this->orderData();
            $data['items'] = [['name' => 'Producto con oferta', 'quantity' => 1, 'price' => '8.00']];
            $data['shipping_amount'] = '2.00';
            $data['total'] = '10.00';

            (new MercadoPagoGateway(PaymentSetting::current()->mercadopago_access_token))->createPayment($data);

            $this->assertCount(1, $transport->preferencePayloads);
            $this->assertEquals([
                ['title' => 'Producto con oferta', 'quantity' => 1, 'unit_price' => 8],
                ['title' => 'Envío', 'quantity' => 1, 'unit_price' => 2],
            ], $transport->preferencePayloads[0]['items']);
        });
    }

    public function test_payment_preference_uses_saved_promotion_price_after_offer_expires_during_reservation(): void
    {
        $this->withIsolatedSdk(function ($transport) {
            PaymentSetting::current()->update(['card_gateway' => 'mercadopago']);
            $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
            Sanctum::actingAs($customer);

            $product = Product::create([
                'name' => 'Producto con oferta ya vencida',
                'slug' => 'producto-oferta-vencida-'.bin2hex(random_bytes(4)),
                'sku' => 'PROMO-PAY-'.bin2hex(random_bytes(4)),
                'price' => '10.00',
                'is_active' => true,
            ]);
            $promotion = ProductPromotion::create([
                'product_id' => $product->id,
                'promotion_price' => '8.00',
                'starts_at' => now()->subHour(),
                'ends_at' => now()->subMinute(),
                'is_active' => true,
            ]);
            $order = Order::create([
                'user_id' => $customer->id,
                'status' => 'pending',
                'total' => '10.00',
                'subtotal' => '8.00',
                'shipping_amount' => '2.00',
                'shipping_info' => [],
                'payment_method' => 'card',
                'payment_status' => 'pending',
                'delivery_type' => 'delivery',
                'tracking_status' => 'pending',
                'reserved_until' => now()->addMinutes(15),
            ]);
            $item = OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => 1,
                'price' => '8.00',
                'regular_price' => '10.00',
                'promotion_id' => $promotion->id,
                'subtotal' => '8.00',
            ]);

            $this->postJson('/api/v1/payment/create', ['order_id' => $order->id])->assertOk();

            $this->assertCount(1, $transport->preferencePayloads);
            $this->assertEquals([
                ['title' => 'Producto con oferta ya vencida', 'quantity' => 1, 'unit_price' => 8],
                ['title' => 'Envío', 'quantity' => 1, 'unit_price' => 2],
            ], $transport->preferencePayloads[0]['items']);
            $this->assertEquals(8.00, $item->fresh()->price);
            $this->assertEquals(10.00, $item->fresh()->regular_price);
            $this->assertSame($promotion->id, $item->fresh()->promotion_id);
            $this->assertEquals(10.00, $order->fresh()->total);
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
        $this->assertSyntheticDatabaseIsolation();
        $clientProperty = new \ReflectionProperty(MercadoPagoConfig::class, 'http_client');
        $originalClient = $clientProperty->getValue();
        $originalToken = MercadoPagoConfig::getAccessToken();
        $transport = new class implements MPHttpClient
        {
            public array $checks = [];

            public array $preferencePayloads = [];

            private ?string $expectedKey = null;

            public function send(MPRequest $request): MPResponse
            {
                if ($request->getMethod() === 'POST' && str_contains($request->getUri(), 'preferences')) {
                    $this->preferencePayloads[] = json_decode((string) $request->getPayload(), true, 32, JSON_THROW_ON_ERROR);
                }
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

                return new MPResponse(201, ['id' => 'synthetic-'.bin2hex(random_bytes(16)), 'init_point' => 'https://example.invalid/checkout', 'sandbox_init_point' => 'https://example.invalid/sandbox']);
            }

            public function matchesKey(string $key): bool
            {
                return $this->expectedKey === $key;
            }
        };
        try {
            MercadoPagoConfig::setHttpClient($transport);
            config(['payment.mercadopago.access_token' => null]);
            PaymentSetting::current()->update(['mercadopago_access_token' => 'SYNTHETIC_'.bin2hex(random_bytes(16))]);
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
            'external_reference' => 'SYNTHETIC_'.bin2hex(random_bytes(16)),
            'idempotency_key' => 'SYNTHETIC_'.bin2hex(random_bytes(16)),
        ];
    }
}
