<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentSetting;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use App\Services\PaymentSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesShippingCoverage;
use Tests\TestCase;

class PaymentConfigurationAndSimulationTest extends TestCase
{
    use CreatesShippingCoverage, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('payment.mock.enabled', true);
        config()->set('payment.default_gateway', 'mock');
    }

    public function test_public_methods_endpoint_returns_sanitized_methods_by_delivery_type(): void
    {
        $deliveryMethods = $this->getJson('/api/v1/payment-methods?delivery_type=delivery')
            ->assertOk()
            ->json('methods');

        $deliveryValues = array_column($deliveryMethods, 'value');
        $this->assertContains('card', $deliveryValues);
        $this->assertContains('transferencia', $deliveryValues);
        $this->assertContains('contra_entrega', $deliveryValues);
        $this->assertNotContains('pago_en_sede', $deliveryValues);

        $pickupMethods = $this->getJson('/api/v1/payment-methods?delivery_type=pickup')
            ->assertOk()
            ->json('methods');

        $pickupValues = array_column($pickupMethods, 'value');
        $this->assertContains('card', $pickupValues);
        $this->assertContains('transferencia', $pickupValues);
        $this->assertContains('pago_en_sede', $pickupValues);
        $this->assertNotContains('contra_entrega', $pickupValues);
    }

    public function test_mock_gateway_is_blocked_in_production_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config()->set('payment.mock.enabled', true);

        $service = app(PaymentSettingService::class);
        $this->assertFalse($service->isMockActive());

        $this->expectException(\RuntimeException::class);
        PaymentGatewayFactory::create('mock');
    }

    public function test_secret_access_token_is_never_exposed_and_empty_update_preserves_existing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        // 1. Configure secret token
        $this->putJson('/api/v1/admin/payment-settings', [
            'card_enabled' => true,
            'card_gateway' => 'mercadopago',
            'mercadopago_public_key' => 'TEST-PUBLIC-KEY-123',
            'mercadopago_access_token' => 'TEST-SECRET-TOKEN-REAL-999',
        ])->assertOk();

        // 2. Fetch admin settings - token must be masked, not raw
        $getRes = $this->getJson('/api/v1/admin/payment-settings')->assertOk();
        $this->assertSame('TEST-PUBLIC-KEY-123', $getRes->json('mercadopago_public_key'));
        $this->assertTrue($getRes->json('has_mercadopago_access_token'));
        $this->assertStringNotContainsString('TEST-SECRET-TOKEN-REAL-999', $getRes->getContent());
        $this->assertStringContainsString('••••', $getRes->json('mercadopago_access_token_masked'));

        // 3. Update without sending token (or sending masked placeholder)
        $this->putJson('/api/v1/admin/payment-settings', [
            'card_title' => 'Nuevo Título Tarjeta',
            'mercadopago_access_token' => 'APP_USR-••••••••••••••••',
        ])->assertOk();

        // 4. Verify original encrypted token is intact in DB
        $setting = PaymentSetting::current();
        $this->assertSame('Nuevo Título Tarjeta', $setting->card_title);
        $this->assertSame('TEST-SECRET-TOKEN-REAL-999', $setting->mercadopago_access_token);
    }

    public function test_contraentrega_is_rejected_for_pickup_and_pago_en_sede_is_rejected_for_delivery(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        Sanctum::actingAs($customer);

        // Attempt contra_entrega on pickup
        $pickupPayload = [
            'delivery_type' => 'pickup',
            'pickup_branch_id' => $this->branch()->id,
            'payment_method' => 'contra_entrega',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];
        $this->postJson('/api/v1/orders', $pickupPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        // Attempt pago_en_sede on delivery
        $deliveryPayload = $this->withShippingCoverage([
            'delivery_type' => 'delivery',
            'payment_method' => 'pago_en_sede',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $this->postJson('/api/v1/orders', $deliveryPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');
    }

    public function test_failed_payment_keeps_order_and_reservation_intact_and_records_failed_transaction(): void
    {
        $customer = $this->customer();
        $product = $this->product(['stock' => 5]);
        $order = $this->orderFor($customer, $product, ['payment_method' => 'card', 'payment_id' => 'MOCK-OP-100']);

        $url = URL::temporarySignedRoute('mock.payment.process', now()->addMinutes(30), [
            'paymentId' => 'MOCK-OP-100',
            'order_id' => $order->id,
        ]);

        // Submit insufficient funds rejection
        $this->post($url, [
            'scenario' => 'insufficient_funds',
            'action' => 'reject',
        ])->assertRedirect('/orders/payment-return?payment_id=MOCK-OP-100&external_reference='.$order->id.'&result=rejected');

        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertSame('rejected', $order->payment_status);
        $this->assertNull($order->paid_at);

        // Reservation remains active
        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'status' => 'active',
        ]);

        // Failed transaction is recorded
        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $order->id,
            'status' => PaymentTransaction::FAILED,
            'payment_method' => 'card',
        ]);
    }

    public function test_subsequent_retry_can_be_approved_atomically_and_consumes_stock_reservation(): void
    {
        $customer = $this->customer();
        $product = $this->product(['stock' => 5]);
        $order = $this->orderFor($customer, $product, [
            'payment_method' => 'card',
            'payment_id' => 'MOCK-OP-200',
            'payment_status' => 'rejected',
            'status' => 'pending',
        ]);

        $url = URL::temporarySignedRoute('mock.payment.process', now()->addMinutes(30), [
            'paymentId' => 'MOCK-OP-200',
            'order_id' => $order->id,
        ]);

        // Submit approval
        $this->post($url, [
            'scenario' => 'approved',
            'action' => 'approve',
        ])->assertRedirect('/orders/payment-return?payment_id=MOCK-OP-200&external_reference='.$order->id.'&result=approved');

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertSame('confirmed', $order->tracking_status);
        $this->assertSame('approved', $order->payment_status);
        $this->assertNotNull($order->paid_at);

        // Reservation is consumed
        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'status' => 'consumed',
        ]);

        // Approved transaction recorded
        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $order->id,
            'status' => PaymentTransaction::APPROVED,
            'approved_scope_key' => PaymentTransaction::approvedScopeKeyForOrder($order->id),
        ]);

        // Fulfillment history recorded
        $this->assertDatabaseHas('order_fulfillment_histories', [
            'order_id' => $order->id,
            'to_status' => Order::FULFILLMENT_PREPARING,
        ]);
    }

    public function test_repeated_approval_is_idempotent_and_never_duplicates_stock_movements_or_transactions(): void
    {
        $customer = $this->customer();
        $product = $this->product(['stock' => 5]);
        $order = $this->orderFor($customer, $product, ['payment_method' => 'card', 'payment_id' => 'MOCK-OP-300']);

        $url = URL::temporarySignedRoute('mock.payment.process', now()->addMinutes(30), [
            'paymentId' => 'MOCK-OP-300',
            'order_id' => $order->id,
        ]);

        // 1st Approval
        $this->post($url, ['scenario' => 'approved', 'action' => 'approve'])->assertRedirect();
        $transactionCount = PaymentTransaction::where('order_id', $order->id)->count();
        $historyCount = $order->fulfillmentHistory()->count();

        // 2nd Approval (Replay)
        $this->post($url, ['scenario' => 'approved', 'action' => 'approve'])->assertRedirect();

        // Must not duplicate
        $this->assertSame($transactionCount, PaymentTransaction::where('order_id', $order->id)->count());
        $this->assertSame($historyCount, $order->fulfillmentHistory()->count());
    }

    public function test_expired_signed_route_is_rejected(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $order = $this->orderFor($customer, $product, ['payment_method' => 'card', 'payment_id' => 'MOCK-EXP']);

        // Expired 5 minutes ago
        $expiredUrl = URL::temporarySignedRoute('mock.payment.process', now()->subMinutes(5), [
            'paymentId' => 'MOCK-EXP',
            'order_id' => $order->id,
        ]);

        $this->post($expiredUrl, ['action' => 'approve'])->assertForbidden();
    }

    public function test_payment_creation_is_blocked_when_card_method_is_disabled(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $order = $this->orderFor($customer, $product, ['payment_method' => 'card']);
        Sanctum::actingAs($customer);

        // Disable card in settings
        PaymentSetting::current()->update(['card_enabled' => false]);

        $this->postJson('/api/v1/payment/create', ['order_id' => $order->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order_id');
    }

    public function test_payment_creation_is_blocked_on_already_paid_order(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $order = $this->orderFor($customer, $product, [
            'payment_method' => 'card',
            'payment_status' => 'approved',
            'paid_at' => now(),
        ]);
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/payment/create', ['order_id' => $order->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order_id');
    }

    public function test_admin_cannot_disable_all_payment_methods(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->putJson('/api/v1/admin/payment-settings', [
            'card_enabled' => false,
            'transfer_enabled' => false,
            'cash_on_delivery_enabled' => false,
            'cash_at_pickup_enabled' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_settings');
    }

    public function test_no_raw_pan_cvv_or_card_data_is_stored_in_database(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $order = $this->orderFor($customer, $product, ['payment_method' => 'card', 'payment_id' => 'MOCK-CARD-DATA']);

        $url = URL::temporarySignedRoute('mock.payment.process', now()->addMinutes(30), [
            'paymentId' => 'MOCK-CARD-DATA',
            'order_id' => $order->id,
        ]);

        $this->post($url, [
            'scenario' => 'approved',
            'action' => 'approve',
            // Even if client attempts to send card inputs, server ignores them
            'card_number' => '4557888899990000',
            'cvv' => '999',
            'expiry' => '12/28',
        ])->assertRedirect();

        $transaction = PaymentTransaction::where('order_id', $order->id)->first();
        $this->assertNotNull($transaction);
        $metadataString = json_encode($transaction->metadata);
        $this->assertStringNotContainsString('4557888899990000', $metadataString);
        $this->assertStringNotContainsString('999', $metadataString);
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => 'customer']);
    }

    private function branch(): \App\Models\Branch
    {
        return \App\Models\Branch::create([
            'code' => 'SEDE-'.Str::random(4),
            'name' => 'Sede Principal',
            'address' => 'Av. Principal 123',
            'department' => 'Lima',
            'province' => 'Lima',
            'district' => 'Lima',
            'allows_pickup' => true,
            'serves_public' => true,
            'is_active' => true,
        ]);
    }

    private function product(array $attributes = []): Product
    {
        $data = array_merge([
            'name' => 'Lubricante '.Str::random(5),
            'slug' => 'lubricante-'.Str::uuid(),
            'sku' => 'LUB-'.Str::upper(Str::random(6)),
            'price' => 50,
            'stock' => 10,
            'is_active' => true,
        ], $attributes);
        $stock = (int) $data['stock'];
        unset($data['stock']);
        $product = Product::create($data);
        $inventory = app(InventoryService::class);
        $inventory->initializeProduct($product, $stock, $inventory->defaultWarehouse());

        return $product->refresh();
    }

    private function orderFor(User $user, Product $product, array $attributes = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $user->id,
            'status' => 'pending',
            'subtotal' => $product->price,
            'shipping_amount' => 0,
            'total' => $product->price,
            'shipping_info' => ['address' => 'Av. Test 123', 'phone' => '999999999'],
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
            'reserved_until' => now()->addMinutes(30),
        ], $attributes));

        $item = $order->items()->create([
            'product_id' => $product->id,
            'warehouse_id' => app(InventoryService::class)->defaultWarehouse()->id,
            'quantity' => 1,
            'price' => $product->price,
            'subtotal' => $product->price,
        ]);
        $item->setRelation('product', $product);
        $item->setRelation('warehouse', app(InventoryService::class)->defaultWarehouse());
        app(InventoryService::class)->reserveForOrder($item, $order->reserved_until);

        return $order->refresh();
    }
}
