<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderHandlingItem;
use App\Models\OrderHandlingProcess;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\PickupDeadlineService;
use App\Services\ShippingRateService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\Concerns\CreatesShippingCoverage;
use Tests\TestCase;

class CheckoutPickupBranchTest extends TestCase
{
    use CreatesShippingCoverage, RefreshDatabase;

    public function test_checkout_lists_only_public_active_pickup_branches_with_safe_fields(): void
    {
        $eligible = $this->branch('ELEGIBLE', true, true, true);
        $this->branch('INACTIVA', false, true, true);
        $this->branch('SIN-RECOJO', true, false, true);
        $this->branch('PRIVADA', true, true, false);
        Sanctum::actingAs($this->customer());
        $response = $this->getJson('/api/v1/checkout/pickup-branches')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $eligible->id);
        $this->assertNull($eligible->district_id);
        $this->assertIsArray($response->json());
        $this->assertArrayNotHasKey('data', $response->json());
        $this->assertSame(['id', 'code', 'name', 'address', 'district', 'province', 'department', 'reference', 'phone', 'business_hours', 'pickup_instructions'], array_keys($response->json('0')));
        $this->assertStringNotContainsString('main_guard', $response->getContent());
    }

    public function test_pickup_requires_an_eligible_branch(): void
    {
        $customer = $this->customer();
        $product = $this->product(4);
        Sanctum::actingAs($customer);
        $this->postJson('/api/v1/orders', $this->payload($product, null))->assertUnprocessable()->assertJsonValidationErrors('pickup_branch_id');
        foreach ([$this->branch('OFF', false, true, true), $this->branch('NO-PICK', true, false, true), $this->branch('NO-PUBLIC', true, true, false)] as $branch) {
            $this->postJson('/api/v1/orders', $this->payload($product, $branch->id))->assertUnprocessable()->assertJsonValidationErrors('pickup_branch_id');
        }
    }

    public function test_pickup_without_addresses_phone_zones_or_rates_never_resolves_shipping_coverage(): void
    {
        $customer = $this->customer();
        $branch = $this->branch('AISLADO', true, true, true);
        $product = $this->product(2);
        $this->mock(ShippingRateService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('quote'));
        Sanctum::actingAs($customer);

        $payload = $this->payload($product, $branch->id);
        unset($payload['shipping_info']);
        $this->postJson('/api/v1/orders', $payload)->assertCreated()
            ->assertJsonPath('shipping_amount', '0.00')
            ->assertJsonPath('shipping_zone_id', null)
            ->assertJsonPath('shipping_rate_id', null);

        $this->assertDatabaseCount('user_addresses', 0);
        $this->assertDatabaseCount('shipping_zones', 0);
        $this->assertDatabaseCount('shipping_rates', 0);
    }

    public function test_pickup_order_snapshots_branch_recalculates_money_and_reserves_web_warehouse(): void
    {
        $branch = $this->branch('CENTRO', true, true, true);
        $product = $this->product(5, 25);
        $warehouse = app(InventoryService::class)->defaultWarehouse();
        Sanctum::actingAs($this->customer());
        $payload = $this->payload($product, $branch->id);
        $payload['subtotal'] = 1;
        $payload['shipping_amount'] = 999;
        $payload['discount_total'] = 999;
        $payload['total'] = 1;
        $response = $this->postJson('/api/v1/orders', $payload)->assertCreated()->assertJsonPath('delivery_type', 'pickup')->assertJsonPath('pickup_branch_name_snapshot', $branch->name)->assertJsonPath('shipping_amount', '0.00')->assertJsonPath('total', '50.00');
        $order = Order::findOrFail($response->json('id'));
        $this->assertSame($branch->code, $order->pickup_branch_code_snapshot);
        $this->assertSame($branch->address, $order->pickup_address_snapshot);
        $this->assertSame($branch->business_hours, $order->pickup_business_hours_snapshot);
        $this->assertNull($order->ready_for_pickup_at);
        $this->assertNull($order->pickup_deadline_at);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'warehouse_id' => $warehouse->id]);
        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $order->id, 'warehouse_id' => $warehouse->id, 'status' => 'active']);
        $this->assertSame(0, InventoryMovement::where('reference_type', 'order')->where('reference_id', (string) $order->id)->count());
    }

    public function test_branch_changes_do_not_change_order_snapshot(): void
    {
        $branch = $this->branch('HISTORIA', true, true, true);
        $product = $this->product(2);
        Sanctum::actingAs($this->customer());
        $id = $this->postJson('/api/v1/orders', $this->payload($product, $branch->id))->assertCreated()->json('id');
        $snapshot = Order::findOrFail($id)->only(['pickup_branch_name_snapshot', 'pickup_address_snapshot', 'pickup_business_hours_snapshot', 'pickup_instructions_snapshot']);
        $branch->update(['name' => 'Nombre nuevo', 'address' => 'Dirección nueva', 'business_hours' => 'Otro horario', 'pickup_instructions' => 'Otras instrucciones']);
        $this->assertSame($snapshot, Order::findOrFail($id)->only(array_keys($snapshot)));
    }

    public function test_checkout_token_is_idempotent_and_does_not_duplicate_reservations(): void
    {
        $branch = $this->branch('IDEMP', true, true, true);
        $product = $this->product(3);
        $payload = $this->payload($product, $branch->id, 'same-token');
        Sanctum::actingAs($this->customer());
        $first = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('id');
        $second = $this->postJson('/api/v1/orders', $payload)->assertOk()->json('id');
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);
    }

    public function test_delivery_rejects_pickup_data_and_keeps_existing_address_flow(): void
    {
        $branch = $this->branch('DELIVERY', true, true, true);
        $product = $this->product(2);
        Sanctum::actingAs($this->customer());
        $payload = $this->payload($product, $branch->id);
        $payload['delivery_type'] = 'delivery';
        $payload['shipping_info'] = ['address' => 'Av. Cliente 123', 'city' => 'Lima', 'phone' => '999'];
        $this->postJson('/api/v1/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('pickup_branch_id');
        unset($payload['pickup_branch_id']);
        $payload = $this->withShippingCoverage($payload);
        $this->postJson('/api/v1/orders', $payload)->assertCreated()->assertJsonPath('delivery_type', 'delivery')->assertJsonPath('pickup_branch_id', null)->assertJsonPath('shipping_info.address', 'Av. Cliente 123');
    }

    public function test_business_deadline_counts_ten_weekdays_excluding_ready_day_and_weekends(): void
    {
        $service = app(PickupDeadlineService::class);
        $this->assertSame('2026-08-21', $service->calculate(CarbonImmutable::parse('2026-08-07 10:00:00'))->toDateString());
        $this->assertSame('2026-08-21', $service->calculate(CarbonImmutable::parse('2026-08-08 10:00:00'))->toDateString());
        $this->assertTrue($service->calculate(CarbonImmutable::parse('2026-08-08'))->isEndOfDay());
    }

    public function test_pickup_operational_actions_set_deadline_and_prevent_invalid_or_duplicate_pickup(): void
    {
        [$admin,$order] = $this->preparedPickupOrder();
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/orders/{$order->id}/fulfillment/picked-up")->assertUnprocessable();
        $ready = $this->postJson("/api/v1/admin/orders/{$order->id}/fulfillment/ready-for-pickup")->assertOk()->assertJsonPath('fulfillment.pickup_deadline_status', 'within_deadline');
        $this->assertNotNull($ready->json('order.ready_for_pickup_at'));
        $this->assertNotNull($ready->json('order.pickup_deadline_at'));
        $this->postJson("/api/v1/admin/orders/{$order->id}/fulfillment/picked-up")->assertOk()->assertJsonPath('fulfillment.pickup_deadline_status', 'picked_up');
        $this->postJson("/api/v1/admin/orders/{$order->id}/fulfillment/picked-up")->assertUnprocessable();
    }

    public function test_delivery_cannot_use_pickup_actions_and_customer_is_forbidden(): void
    {
        $admin = $this->admin();
        $delivery = Order::create(['user_id' => $this->customer()->id, 'status' => 'confirmed', 'total' => 10, 'shipping_info' => ['address' => 'Av. Uno', 'city' => 'Lima'], 'payment_method' => 'transferencia', 'payment_status' => 'approved', 'delivery_type' => 'delivery', 'tracking_status' => 'processing', 'fulfillment_status' => 'preparing']);
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/orders/{$delivery->id}/fulfillment/ready-for-pickup")->assertUnprocessable();
        Sanctum::actingAs($this->customer());
        $this->postJson("/api/v1/admin/orders/{$delivery->id}/fulfillment/picked-up")->assertForbidden();
    }

    public function test_expired_paid_pickup_is_derived_without_canceling_or_touching_inventory(): void
    {
        [$admin,$order] = $this->preparedPickupOrder();
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/orders/{$order->id}/fulfillment/ready-for-pickup")->assertOk();
        $order->refresh()->update(['pickup_deadline_at' => now()->subDay()]);
        $movements = InventoryMovement::count();
        Sanctum::actingAs($order->user);
        $this->getJson("/api/v1/orders/{$order->id}/tracking")->assertOk()->assertJsonPath('order.pickup.deadline_status', 'expired');
        $this->assertSame('approved', $order->refresh()->payment_status);
        $this->assertNotSame('canceled', $order->status);
        $this->assertSame($movements, InventoryMovement::count());
    }

    public function test_legacy_order_serializes_as_delivery_without_fabricated_pickup_snapshot(): void
    {
        $customer = $this->customer();
        $order = Order::create(['user_id' => $customer->id, 'status' => 'pending', 'total' => 12, 'shipping_info' => ['address' => 'Histórica', 'city' => 'Lima'], 'payment_method' => 'transferencia', 'delivery_type' => 'delivery', 'tracking_status' => 'pending']);
        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->assertJsonPath('delivery_type', 'delivery')->assertJsonPath('pickup_branch_id', null)->assertJsonPath('subtotal', '12.00');
    }

    private function preparedPickupOrder(): array
    {
        $admin = $this->admin();
        $branch = $this->branch('OPERATIVA'.Str::random(3), true, true, true);
        $product = $this->product(3);
        $customer = $this->customer();
        Sanctum::actingAs($customer);
        $id = $this->postJson('/api/v1/orders', $this->payload($product, $branch->id))->assertCreated()->json('id');
        $order = Order::findOrFail($id);
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/orders/{$id}/fulfillment/approve-transfer")->assertOk();
        $this->postJson("/api/v1/admin/orders/{$id}/fulfillment/start-preparation")->assertOk();
        $process = OrderHandlingProcess::where('order_id', $id)->firstOrFail();
        $process->update(['picking_status' => 'completed', 'packing_status' => 'completed']);
        OrderHandlingItem::where('order_handling_process_id', $process->id)->get()->each(fn ($item) => $item->update(['picked_quantity' => $item->ordered_quantity, 'packed_quantity' => $item->ordered_quantity]));

        return [$admin, $order->refresh()];
    }

    private function payload(Product $product, ?int $branchId, ?string $token = null): array
    {
        return ['checkout_token' => $token ?? (string) Str::uuid(), 'delivery_type' => 'pickup', 'pickup_branch_id' => $branchId, 'shipping_info' => ['phone' => '999111222'], 'payment_method' => 'transferencia', 'items' => [['product_id' => $product->id, 'quantity' => 2]]];
    }

    private function branch(string $code, bool $active, bool $pickup, bool $public): Branch
    {
        return Branch::create(['code' => $code, 'name' => 'Sede '.$code, 'address' => 'Av. Real 123', 'department' => 'Lima', 'province' => 'Lima', 'district' => 'Miraflores', 'business_hours' => 'Lunes a viernes 09:00-18:00', 'pickup_instructions' => 'Presentar número de pedido', 'allows_pickup' => $pickup, 'serves_public' => $public, 'is_active' => $active]);
    }

    private function product(int $stock, float $price = 10): Product
    {
        $product = Product::create(['name' => 'Producto '.Str::random(5), 'slug' => 'pickup-'.Str::uuid(), 'sku' => 'PK-'.Str::upper(Str::random(8)), 'price' => $price, 'is_active' => true]);
        app(InventoryService::class)->initializeProduct($product, $stock, app(InventoryService::class)->defaultWarehouse());

        return $product;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => 'customer']);
    }
}
