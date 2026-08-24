<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryReservationExpirationService;
use App\Services\InventoryService;
use App\Services\OrderFulfillmentService;
use App\Services\OrderPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesShippingCoverage;
use Tests\TestCase;

/**
 * Tests for reservation expiration, idempotency, token rotation, and timezone safety.
 */
class ReservationExpirationTest extends TestCase
{
    use CreatesShippingCoverage, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('payment.mock.enabled', true);
        config()->set('payment.default_gateway', 'mock');
        config()->set('inventory.reservation_minutes', 30);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Freshly created order has an active, future reservation
    // ─────────────────────────────────────────────────────────────────────────
    public function test_freshly_created_order_has_active_future_reservation(): void
    {
        $customer = $this->customer();
        $product = $this->product(10);
        Sanctum::actingAs($customer);

        $payload = $this->withShippingCoverage([
            'delivery_type' => 'delivery',
            'payment_method' => 'card',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $customer);

        $response = $this->postJson('/api/v1/orders', $payload)->assertCreated();
        $orderId = $response->json('id');

        $order = Order::findOrFail($orderId);
        $this->assertNotNull($order->reserved_until);
        $this->assertTrue($order->reserved_until->isFuture(), 'reserved_until must be in the future');

        $reservation = InventoryReservation::where('order_id', $orderId)->first();
        $this->assertNotNull($reservation);
        $this->assertSame(InventoryReservation::ACTIVE, $reservation->status);
        $this->assertTrue($reservation->expires_at->isFuture(), 'expires_at must be in the future');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Timezone safety: fresh reservation never expires immediately
    // ─────────────────────────────────────────────────────────────────────────
    public function test_timezone_difference_does_not_expire_new_reservation_immediately(): void
    {
        $customer = $this->customer();
        $product = $this->product(10);
        Sanctum::actingAs($customer);

        $payload = $this->withShippingCoverage([
            'delivery_type' => 'delivery',
            'payment_method' => 'card',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $customer);

        $response = $this->postJson('/api/v1/orders', $payload)->assertCreated();
        $order = Order::findOrFail($response->json('id'));

        // Regardless of timezone config, reserved_until must be at least 25 minutes in the future
        $this->assertGreaterThan(25, now()->diffInMinutes($order->reserved_until, false),
            'reservation must have at least 25 minutes remaining immediately after creation'
        );

        // Payment gateway should open without expiration error
        $payResponse = $this->postJson('/api/v1/payment/create', ['order_id' => $order->id]);
        $payResponse->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Same token with active reservation returns same order (idempotent)
    // ─────────────────────────────────────────────────────────────────────────
    public function test_same_token_with_active_reservation_returns_same_order(): void
    {
        $customer = $this->customer();
        $product = $this->product(10);
        $token = (string) Str::uuid();
        Sanctum::actingAs($customer);

        $payload = $this->withShippingCoverage([
            'checkout_token' => $token,
            'delivery_type' => 'delivery',
            'payment_method' => 'card',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $customer);

        $firstId = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('id');
        $secondId = $this->postJson('/api/v1/orders', $payload)->assertOk()->json('id');

        $this->assertSame($firstId, $secondId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Same token does not duplicate reservation
    // ─────────────────────────────────────────────────────────────────────────
    public function test_same_token_does_not_duplicate_reservation(): void
    {
        $customer = $this->customer();
        $product = $this->product(10);
        $token = (string) Str::uuid();
        Sanctum::actingAs($customer);

        $payload = $this->withShippingCoverage([
            'checkout_token' => $token,
            'delivery_type' => 'delivery',
            'payment_method' => 'card',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $customer);

        $id = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('id');
        $this->postJson('/api/v1/orders', $payload)->assertOk();

        $this->assertSame(1, InventoryReservation::where('order_id', $id)->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Expired reservation via /orders returns 409 reservation_expired
    // ─────────────────────────────────────────────────────────────────────────
    public function test_expired_order_via_orders_endpoint_returns_409(): void
    {
        $customer = $this->customer();
        $product = $this->product(10);
        $token = (string) Str::uuid();
        Sanctum::actingAs($customer);

        $payload = $this->withShippingCoverage([
            'checkout_token' => $token,
            'delivery_type' => 'delivery',
            'payment_method' => 'card',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $customer);

        $id = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('id');

        // Force expiration
        Order::whereKey($id)->update(['reserved_until' => now()->subMinute()]);
        InventoryReservation::where('order_id', $id)->update(['expires_at' => now()->subMinute()]);

        $response = $this->postJson('/api/v1/orders', $payload)->assertStatus(409);
        $this->assertSame('reservation_expired', $response->json('code'));
        $this->assertTrue($response->json('can_retry'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. Expired reservation via /payment/create returns 409
    // ─────────────────────────────────────────────────────────────────────────
    public function test_expired_order_via_payment_create_returns_409(): void
    {
        $customer = $this->customer();
        $product = $this->product(10);
        Sanctum::actingAs($customer);

        $payload = $this->withShippingCoverage([
            'delivery_type' => 'delivery',
            'payment_method' => 'card',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $customer);

        $id = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('id');

        // Force expiration
        Order::whereKey($id)->update(['reserved_until' => now()->subMinute()]);
        InventoryReservation::where('order_id', $id)->update(['expires_at' => now()->subMinute()]);

        $response = $this->postJson('/api/v1/payment/create', ['order_id' => $id])->assertStatus(409);
        $this->assertSame('reservation_expired', $response->json('code'));
        $this->assertTrue($response->json('can_retry'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. Expired reservation releases stock and does NOT allow approval
    // ─────────────────────────────────────────────────────────────────────────
    public function test_expired_reservation_is_never_consumed(): void
    {
        $customer = $this->customer();
        $product = $this->product(10);
        Sanctum::actingAs($customer);

        $payload = $this->withShippingCoverage([
            'delivery_type' => 'delivery',
            'payment_method' => 'card',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $customer);

        $id = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('id');
        $order = Order::findOrFail($id);

        Order::whereKey($id)->update(['reserved_until' => now()->subMinute()]);
        InventoryReservation::where('order_id', $id)->update(['expires_at' => now()->subMinute()]);

        // Trigger 409 from payment/create so it attempts to release reservation
        $this->postJson('/api/v1/payment/create', ['order_id' => $id])->assertStatus(409);

        $order->refresh();
        $this->assertSame('canceled', $order->status);

        $reservation = InventoryReservation::where('order_id', $id)->first();
        $this->assertNotSame(InventoryReservation::CONSUMED, $reservation->status,
            'Expired reservation must never be consumed'
        );
        $this->assertSame(InventoryReservation::EXPIRED, $reservation->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. Simulator with expired reservation redirects, does not consume
    // ─────────────────────────────────────────────────────────────────────────
    public function test_simulator_with_expired_reservation_redirects_and_does_not_consume(): void
    {
        $customer = $this->customer();
        $product = $this->product(10);
        Sanctum::actingAs($customer);

        $payload = $this->withShippingCoverage([
            'delivery_type' => 'delivery',
            'payment_method' => 'card',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $customer);

        $id = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('id');
        $order = Order::findOrFail($id);
        $order->update(['payment_id' => 'MOCK-EXP-SIM']);
        Order::whereKey($id)->update(['reserved_until' => now()->subMinutes(2)]);
        InventoryReservation::where('order_id', $id)->update(['expires_at' => now()->subMinutes(2)]);
        $order->refresh();

        $url = URL::temporarySignedRoute('mock.payment.process', now()->addMinutes(30), [
            'paymentId' => 'MOCK-EXP-SIM',
            'order_id' => $id,
        ]);

        $this->post($url, ['action' => 'approve', 'scenario' => 'approved'])->assertRedirect();

        $reservation = InventoryReservation::where('order_id', $id)->first();
        $this->assertNotSame(InventoryReservation::CONSUMED, $reservation->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 9. Rejected payment with active reservation allows retry
    // ─────────────────────────────────────────────────────────────────────────
    public function test_rejected_payment_with_active_reservation_allows_payment_create(): void
    {
        $customer = $this->customer();
        $product = $this->product(10);
        Sanctum::actingAs($customer);

        $payload = $this->withShippingCoverage([
            'delivery_type' => 'delivery',
            'payment_method' => 'card',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $customer);

        $id = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('id');
        Order::whereKey($id)->update(['payment_status' => 'rejected']);

        // Should still be able to create a new payment (reservation is active)
        $this->postJson('/api/v1/payment/create', ['order_id' => $id])->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 10. Rejected payment keeps order pending and reservation active
    // ─────────────────────────────────────────────────────────────────────────
    public function test_rejected_payment_does_not_cancel_order_or_reservation(): void
    {
        $customer = $this->customer();
        $product = $this->product(10);
        Sanctum::actingAs($customer);

        $payload = $this->withShippingCoverage([
            'delivery_type' => 'delivery',
            'payment_method' => 'card',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $customer);

        $id = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('id');
        $order = Order::findOrFail($id);
        $paymentId = 'MOCK-REJECT-'.$id;
        $order->update(['payment_id' => $paymentId]);

        $url = URL::temporarySignedRoute('mock.payment.process', now()->addMinutes(30), [
            'paymentId' => $paymentId,
            'order_id' => $id,
        ]);
        $this->post($url, ['action' => 'reject', 'scenario' => 'insufficient_funds'])->assertRedirect();

        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertSame('rejected', $order->payment_status);

        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $id,
            'status' => InventoryReservation::ACTIVE,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 11. Retry with new token creates a new order and reservation
    // ─────────────────────────────────────────────────────────────────────────
    public function test_new_token_after_expiration_creates_new_order_and_reservation(): void
    {
        $customer = $this->customer();
        $product = $this->product(10);
        $expiredToken = (string) Str::uuid();
        $newToken = (string) Str::uuid();
        Sanctum::actingAs($customer);

        $payloadExpired = $this->withShippingCoverage([
            'checkout_token' => $expiredToken,
            'delivery_type' => 'delivery',
            'payment_method' => 'card',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $customer);

        $firstId = $this->postJson('/api/v1/orders', $payloadExpired)->assertCreated()->json('id');

        // Expire first order
        Order::whereKey($firstId)->update(['reserved_until' => now()->subMinute()]);
        InventoryReservation::where('order_id', $firstId)->update(['expires_at' => now()->subMinute()]);

        // Use a NEW token — must create a new order
        $payloadNew = array_merge($payloadExpired, ['checkout_token' => $newToken]);
        $secondId = $this->postJson('/api/v1/orders', $payloadNew)->assertCreated()->json('id');

        $this->assertNotSame($firstId, $secondId);
        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $secondId,
            'status' => InventoryReservation::ACTIVE,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 12. Completed order cleans up (token not re-usable for new purchase)
    // ─────────────────────────────────────────────────────────────────────────
    public function test_completed_order_token_returns_same_order_not_new_one(): void
    {
        $customer = $this->customer();
        $product = $this->product(10);
        $token = (string) Str::uuid();
        Sanctum::actingAs($customer);

        $payload = $this->withShippingCoverage([
            'checkout_token' => $token,
            'delivery_type' => 'delivery',
            'payment_method' => 'card',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $customer);

        $firstId = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('id');
        Order::whereKey($firstId)->update(['status' => 'delivered', 'payment_status' => 'approved', 'tracking_status' => 'delivered']);

        // Same token should return same order (idempotent), not create new one
        $secondId = $this->postJson('/api/v1/orders', $payload)->assertOk()->json('id');
        $this->assertSame($firstId, $secondId);
        $this->assertDatabaseCount('orders', 1);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 13. Expired order is marked canceled after 409 response
    // ─────────────────────────────────────────────────────────────────────────
    public function test_expired_order_is_marked_canceled_after_409_from_orders(): void
    {
        $customer = $this->customer();
        $product = $this->product(10);
        $token = (string) Str::uuid();
        Sanctum::actingAs($customer);

        $payload = $this->withShippingCoverage([
            'checkout_token' => $token,
            'delivery_type' => 'delivery',
            'payment_method' => 'card',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $customer);

        $id = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('id');

        Order::whereKey($id)->update(['reserved_until' => now()->subMinute()]);
        InventoryReservation::where('order_id', $id)->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/orders', $payload)->assertStatus(409);

        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'canceled']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 14. User cannot reuse another user's token
    // ─────────────────────────────────────────────────────────────────────────
    public function test_user_cannot_reuse_another_users_checkout_token(): void
    {
        $customer1 = $this->customer();
        $customer2 = $this->customer();
        $product = $this->product(10);
        $token = (string) Str::uuid();

        Sanctum::actingAs($customer1);
        $payload1 = $this->withShippingCoverage([
            'checkout_token' => $token,
            'delivery_type' => 'delivery',
            'payment_method' => 'card',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $customer1);
        $this->postJson('/api/v1/orders', $payload1)->assertCreated();

        Sanctum::actingAs($customer2);
        $payload2 = $this->withShippingCoverage([
            'checkout_token' => $token,
            'delivery_type' => 'delivery',
            'payment_method' => 'card',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $customer2);
        $this->postJson('/api/v1/orders', $payload2)->assertUnprocessable()
            ->assertJsonValidationErrors('checkout_token');
    }

    public function test_expiration_policy_expires_card_but_excludes_offline_methods(): void
    {
        $product = $this->product(10);
        $card = $this->createOrderForPolicy($product, 'card', false);
        $offline = $this->createOrderForPolicy($product, 'contra_entrega', false);
        $card->update(['reserved_until' => now()->subMinute()]);
        InventoryReservation::where('order_id', $card->id)->update(['expires_at' => now()->subMinute()]);
        $service = app(InventoryReservationExpirationService::class);

        $this->assertSame('expired_online_payment', $service->classify($card)['reason']);
        $this->assertSame('offline_payment_excluded', $service->classify($offline)['reason']);
        $this->assertTrue($service->expireOrder($card->id)['processed']);
        $this->assertSame(InventoryReservation::ACTIVE, $offline->reservations()->first()->status);
    }

    public function test_transfer_with_pending_proof_is_manual_review(): void
    {
        $order = $this->createOrderForPolicy($this->product(5), 'transferencia');
        $order->update(['payment_data' => ['transfer_proof_status' => 'under_review']]);

        $classification = app(InventoryReservationExpirationService::class)->classify($order->refresh());
        $this->assertSame('ambiguous_legacy_order', $classification['reason']);
        $this->assertTrue($classification['manual_review']);
    }

    public function test_offline_order_payment_is_approved_on_collection(): void
    {
        $service = app(OrderPaymentService::class);
        foreach (['contra_entrega', 'pago_en_sede'] as $method) {
            $order = $this->createOrderForPolicy($this->product(5), $method);
            $order->update(['payment_method' => $method, 'reserved_until' => null, 'status' => 'confirmed', 'tracking_status' => 'confirmed']);
            $service->confirmCashOnDeliveryCollection($order, $this->customer());
            $this->assertSame('approved', $order->refresh()->payment_status);
            $this->assertNotNull($order->paid_at);
        }
    }

    public function test_offline_orders_enter_operational_flow_without_online_expiration(): void
    {
        $customer = $this->customer();
        $product = $this->product(10);
        $branch = Branch::create([
            'code' => 'TEST-'.Str::upper(Str::random(5)), 'name' => 'Sede de prueba', 'address' => 'Av. Prueba 123',
            'department' => 'Lima', 'province' => 'Lima', 'district' => 'Lima', 'allows_pickup' => true,
            'serves_public' => true, 'is_active' => true,
        ]);

        Sanctum::actingAs($customer);
        $delivery = $this->withShippingCoverage(['delivery_type' => 'delivery', 'payment_method' => 'contra_entrega', 'items' => [['product_id' => $product->id, 'quantity' => 1]]], $customer);
        $contra = Order::findOrFail($this->postJson('/api/v1/orders', $delivery)->assertCreated()->json('id'));
        $pickup = ['delivery_type' => 'pickup', 'pickup_branch_id' => $branch->id, 'payment_method' => 'pago_en_sede', 'items' => [['product_id' => $product->id, 'quantity' => 1]]];
        $sede = Order::findOrFail($this->postJson('/api/v1/orders', $pickup)->assertCreated()->json('id'));

        foreach ([$contra, $sede] as $order) {
            $this->assertSame('confirmed', $order->status);
            $this->assertSame('confirmed', $order->tracking_status);
            $this->assertNull($order->reserved_until);
            $this->assertSame('pending', $order->payment_status);
            app(OrderFulfillmentService::class)->startPreparation($order, $this->customer());
        }
    }

    private function createOrderForPolicy(Product $product, string $paymentMethod, bool $expired = true): Order
    {
        Sanctum::actingAs($this->customer());
        $payload = $this->withShippingCoverage([
            'delivery_type' => 'delivery',
            'payment_method' => 'card',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
        $id = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('id');
        $order = Order::findOrFail($id);
        $order->update(['payment_method' => $paymentMethod, 'reserved_until' => $expired ? now()->subMinute() : $order->reserved_until]);
        if ($expired) {
            InventoryReservation::where('order_id', $id)->update(['expires_at' => now()->subMinute()]);
        }

        return $order->refresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────
    private function customer(): User
    {
        return User::factory()->create(['role' => 'customer']);
    }

    private function product(int $stock): Product
    {
        $product = Product::create([
            'name' => 'Producto '.Str::random(5),
            'slug' => 'prod-'.Str::uuid(),
            'sku' => 'SKU-'.Str::upper(Str::random(6)),
            'price' => '50.00',
            'is_active' => true,
        ]);
        $inventory = app(InventoryService::class);
        $inventory->initializeProduct($product, $stock, $inventory->defaultWarehouse());

        return $product->refresh();
    }
}
