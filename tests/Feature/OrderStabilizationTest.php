<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderStabilizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_view_their_own_order_tracking(): void
    {
        $customer = $this->customer();
        $order = $this->orderFor($customer);

        Sanctum::actingAs($customer);

        $this->getJson("/api/v1/orders/{$order->id}/tracking")
            ->assertOk()
            ->assertJsonPath('order.id', $order->id);
    }

    public function test_customer_cannot_view_another_customers_tracking(): void
    {
        $owner = $this->customer();
        $otherCustomer = $this->customer();
        $order = $this->orderFor($owner);

        Sanctum::actingAs($otherCustomer);

        $this->getJson("/api/v1/orders/{$order->id}/tracking")->assertNotFound();
    }

    public function test_admin_can_view_customer_tracking(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->orderFor($this->customer());

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/orders/{$order->id}/tracking")->assertOk();
    }

    public function test_unauthenticated_user_receives_unauthorized_for_tracking(): void
    {
        $order = $this->orderFor($this->customer());

        $this->getJson("/api/v1/orders/{$order->id}/tracking")->assertUnauthorized();
    }

    public function test_tracking_must_follow_the_delivery_sequence(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->orderFor($this->customer(), ['delivery_type' => 'delivery']);

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/admin/orders/{$order->id}/tracking", [
            'tracking_status' => 'shipped',
        ])->assertUnprocessable();
    }

    public function test_delivery_rejects_pickup_only_statuses(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->orderFor($this->customer(), ['delivery_type' => 'delivery']);

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/admin/orders/{$order->id}/tracking", [
            'tracking_status' => 'ready_for_pickup',
        ])->assertUnprocessable();
    }

    public function test_pickup_rejects_delivery_only_statuses(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->orderFor($this->customer(), ['delivery_type' => 'pickup']);

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/admin/orders/{$order->id}/tracking", [
            'tracking_status' => 'shipped',
        ])->assertUnprocessable();
    }

    public function test_canceled_order_cannot_return_to_operational_flow(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->orderFor($this->customer(), [
            'status' => 'canceled',
            'tracking_status' => 'canceled',
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/admin/orders/{$order->id}/tracking", [
            'tracking_status' => 'confirmed',
        ])->assertUnprocessable();
    }

    public function test_completed_order_cannot_go_backwards(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->orderFor($this->customer(), [
            'status' => 'delivered',
            'tracking_status' => 'delivered',
        ]);

        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/admin/orders/{$order->id}/tracking", [
            'tracking_status' => 'processing',
        ])->assertUnprocessable();
    }

    public function test_canceled_order_cannot_be_marked_as_shipped_from_commercial_endpoint(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->orderFor($this->customer(), [
            'status' => 'canceled',
            'tracking_status' => 'canceled',
        ]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/admin/orders/{$order->id}", [
            'status' => 'shipped',
        ])->assertUnprocessable();
    }

    public function test_pickup_order_cannot_be_marked_as_shipped(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->orderFor($this->customer(), [
            'delivery_type' => 'pickup',
            'status' => 'confirmed',
            'tracking_status' => 'confirmed',
        ]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/admin/orders/{$order->id}", [
            'status' => 'shipped',
        ])->assertUnprocessable();
    }

    public function test_approved_payment_cannot_be_marked_as_rejected_by_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->orderFor($this->customer(), [
            'status' => 'confirmed',
            'tracking_status' => 'confirmed',
            'payment_status' => 'approved',
        ]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/admin/orders/{$order->id}", [
            'status' => 'rejected',
        ])->assertUnprocessable();
    }

    public function test_valid_tracking_transitions_keep_commercial_status_synchronized(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->orderFor($this->customer(), [
            'delivery_type' => 'delivery',
            'payment_method' => 'transferencia',
        ]);

        Sanctum::actingAs($admin);

        foreach ([
            'confirmed' => 'confirmed',
            'processing' => 'confirmed',
            'shipped' => 'shipped',
            'delivered' => 'delivered',
        ] as $trackingStatus => $commercialStatus) {
            $this->putJson("/api/v1/admin/orders/{$order->id}/tracking", [
                'tracking_status' => $trackingStatus,
            ])->assertOk();

            $order->refresh();
            $this->assertSame($trackingStatus, $order->tracking_status);
            $this->assertSame($commercialStatus, $order->status);
            if ($trackingStatus === 'processing') $this->completeHandling($order);
        }

        $this->assertNotNull($order->delivered_at);
    }

    public function test_order_rejects_quantity_greater_than_stock(): void
    {
        $customer = $this->customer();
        $product = $this->product(['stock' => 1]);
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/orders', $this->orderPayload($product, 2))
            ->assertUnprocessable();

        $this->assertSame(1, $product->refresh()->stock);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_rejects_zero_and_negative_quantities(): void
    {
        $customer = $this->customer();
        $product = $this->product(['stock' => 5]);
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/orders', $this->orderPayload($product, 0))
            ->assertUnprocessable();
        $this->postJson('/api/v1/orders', $this->orderPayload($product, -1))
            ->assertUnprocessable();

        $this->assertSame(5, $product->refresh()->stock);
    }

    public function test_backend_price_and_total_override_frontend_values(): void
    {
        $customer = $this->customer();
        $product = $this->product(['price' => 25.50, 'stock' => 5]);
        Sanctum::actingAs($customer);

        $payload = $this->orderPayload($product, 2);
        $payload['items'][0]['price'] = 0.01;
        $payload['total'] = 0.01;

        $response = $this->postJson('/api/v1/orders', $payload)->assertCreated();

        $orderId = $response->json('id');
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'total' => 51.00]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'price' => 25.50,
            'subtotal' => 51.00,
        ]);
    }

    public function test_failed_multi_item_order_rolls_back_items_and_stock(): void
    {
        $customer = $this->customer();
        $available = $this->product(['stock' => 5]);
        $insufficient = $this->product(['stock' => 1]);
        Sanctum::actingAs($customer);

        $payload = $this->orderPayload($available, 2);
        $payload['items'][] = ['product_id' => $insufficient->id, 'quantity' => 2];

        $this->postJson('/api/v1/orders', $payload)->assertUnprocessable();

        $this->assertSame(5, $available->refresh()->stock);
        $this->assertSame(1, $insufficient->refresh()->stock);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_sequential_last_unit_purchases_do_not_make_stock_negative(): void
    {
        $customer = $this->customer();
        $product = $this->product(['stock' => 1]);
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/orders', $this->orderPayload($product, 1))->assertCreated();
        $this->postJson('/api/v1/orders', $this->orderPayload($product, 1))->assertUnprocessable();

        $this->assertSame(1, $product->refresh()->stock);
        $this->assertSame(1, (int) \App\Models\WarehouseInventory::where('product_id',$product->id)->value('reserved_quantity'));
    }

    public function test_mock_payment_routes_are_unavailable_when_disabled(): void
    {
        config()->set('payment.mock.enabled', false);

        $this->get('/mock-payment/TEST?order_id=1')->assertNotFound();
    }

    public function test_payment_return_resolves_only_the_authenticated_users_order(): void
    {
        $customer = $this->customer();
        $order = $this->orderFor($customer, [
            'payment_id' => 'PREFERENCE-123',
            'payment_status' => 'approved',
            'status' => 'confirmed',
            'tracking_status' => 'confirmed',
        ]);
        Sanctum::actingAs($customer);

        $this->getJson("/api/v1/payment/return?external_reference={$order->id}&preference_id=PREFERENCE-123&result=approved")
            ->assertOk()
            ->assertJsonPath('order_id', $order->id)
            ->assertJsonPath('display_status', 'approved');
    }

    public function test_payment_return_does_not_expose_another_users_order(): void
    {
        $owner = $this->customer();
        $otherCustomer = $this->customer();
        $order = $this->orderFor($owner, ['payment_id' => 'PREFERENCE-PRIVATE']);
        Sanctum::actingAs($otherCustomer);

        $this->getJson("/api/v1/payment/return?external_reference={$order->id}&preference_id=PREFERENCE-PRIVATE")
            ->assertNotFound();
    }

    public function test_approved_payment_notification_confirms_order_and_tracking(): void
    {
        config()->set('payment.default_gateway', 'mock');
        config()->set('payment.mock.enabled', true);

        $order = $this->orderFor($this->customer(), [
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'status' => 'pending',
            'tracking_status' => 'pending',
        ]);

        $this->postJson('/api/v1/payment/webhook', [
            'payment_id' => 'MOCK-PAYMENT-1',
            'order_id' => $order->id,
        ])->assertOk();

        $order->refresh();
        $this->assertSame('approved', $order->payment_status);
        $this->assertSame('confirmed', $order->status);
        $this->assertSame('confirmed', $order->tracking_status);
    }

    public function test_registered_controller_routes_reference_existing_methods(): void
    {
        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_starts_with($action, 'App\\Http\\Controllers\\') || ! str_contains($action, '@')) {
                continue;
            }

            [$controller, $method] = explode('@', $action, 2);
            $this->assertTrue(
                method_exists($controller, $method),
                "Route {$route->uri()} references missing method {$controller}@{$method}."
            );
        }
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => 'customer']);
    }

    private function product(array $attributes = []): Product
    {
        $data = array_merge([
            'name' => 'Producto '.Str::random(8),
            'slug' => 'producto-'.Str::uuid(),
            'sku' => 'SKU-'.Str::random(8),
            'price' => 10,
            'stock' => 10,
            'is_active' => true,
        ], $attributes);
        $stock = (int) $data['stock'];
        unset($data['stock']);
        $product = Product::create($data);
        $inventory = app(InventoryService::class);
        $inventory->initializeProduct($product, $stock, $stock > 0 ? $inventory->defaultWarehouse() : null);
        return $product->refresh();
    }

    private function orderFor(User $user, array $attributes = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $user->id,
            'status' => 'pending',
            'total' => 10,
            'shipping_info' => [
                'address' => 'Av. Prueba 123',
                'city' => 'Lima',
                'phone' => '999999999',
            ],
            'payment_method' => 'transferencia',
            'payment_status' => 'pending',
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
            'reserved_until' => now()->addMinutes(30),
        ], $attributes));

        $product = $this->product();
        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'warehouse_id' => app(InventoryService::class)->defaultWarehouse()->id,
            'quantity' => 1,
            'price' => 10,
            'subtotal' => 10,
        ]);

        $item->setRelation('product',$product);
        app(InventoryService::class)->reserveForOrder($item,$order->reserved_until);

        return $order;
    }

    private function orderPayload(Product $product, int $quantity): array
    {
        return [
            'shipping_info' => [
                'address' => 'Av. Prueba 123',
                'city' => 'Lima',
                'phone' => '999999999',
            ],
            'payment_method' => 'transferencia',
            'delivery_type' => 'delivery',
            'items' => [
                ['product_id' => $product->id, 'quantity' => $quantity],
            ],
        ];
    }

    private function completeHandling(Order $order): void
    {
        $base='/api/v1/admin/orders/'.$order->id;
        $this->postJson($base.'/picking/start')->assertOk();
        foreach ($order->items as $item) $this->patchJson($base.'/picking/items/'.$item->id,['picked_quantity'=>$item->quantity])->assertOk();
        $this->postJson($base.'/picking/complete')->assertOk();
        $this->postJson($base.'/packing/start')->assertOk();
        foreach ($order->items as $item) $this->patchJson($base.'/packing/items/'.$item->id,['packed_quantity'=>$item->quantity])->assertOk();
        $this->postJson($base.'/packing/complete')->assertOk();
    }
}
