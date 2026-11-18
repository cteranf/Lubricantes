<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\PaymentSubmission;
use App\Models\Product;
use App\Models\ProductPromotion;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderRepurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_transfer_order_returns_current_active_offer_without_creating_a_reservation(): void
    {
        [$owner, $order, $product, $inventory] = $this->expiredOrder();
        ProductPromotion::create([
            'product_id' => $product->id,
            'promotion_price' => '100.00',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);
        Sanctum::actingAs($owner);
        $originalState = [$order->status, $order->tracking_status, $order->payment_status, $order->updated_at?->toIso8601String()];

        $response = $this->getJson("/api/v1/orders/{$order->id}/repurchase-quote")
            ->assertOk()
            ->assertJsonPath('items.0.product_id', $product->id)
            ->assertJsonPath('items.0.quantity', 6)
            ->assertJsonPath('items.0.product_available', true)
            ->assertJsonPath('items.0.available_quantity', 20)
            ->assertJsonPath('items.0.regular_price', '109')
            ->assertJsonPath('items.0.price', '100.00');

        $this->assertSame(1, InventoryReservation::where('order_id', $order->id)->count());
        $this->assertSame(InventoryReservation::EXPIRED, $order->reservations()->firstOrFail()->status);
        $this->assertSame(0, (int) $inventory->fresh()->reserved_quantity);
        $this->assertSame($originalState, [$order->fresh()->status, $order->fresh()->tracking_status, $order->fresh()->payment_status, $order->fresh()->updated_at?->toIso8601String()]);
        $this->assertSame(109.0, (float) $response->json('items.0.regular_price'));
    }

    public function test_expired_offer_quotes_the_current_regular_price(): void
    {
        [$owner, $order, $product] = $this->expiredOrder();
        ProductPromotion::create([
            'product_id' => $product->id,
            'promotion_price' => '100.00',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subSecond(),
            'is_active' => true,
        ]);
        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/orders/{$order->id}/repurchase-quote")
            ->assertOk()
            ->assertJsonPath('items.0.regular_price', '109')
            ->assertJsonPath('items.0.price', '109');
    }

    public function test_insufficient_stock_is_reported_without_reserving_again(): void
    {
        [$owner, $order, $product] = $this->expiredOrder(6, 3);
        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/orders/{$order->id}/repurchase-quote")
            ->assertOk()
            ->assertJsonPath('items.0.quantity', 6)
            ->assertJsonPath('items.0.available_quantity', 3)
            ->assertJsonPath('items.0.product_available', true);

        $this->assertSame(1, InventoryReservation::where('order_id', $order->id)->count());
        $this->assertSame(InventoryReservation::EXPIRED, $order->reservations()->firstOrFail()->status);
    }

    public function test_inactive_product_is_reported_as_unavailable(): void
    {
        [$owner, $order, $product] = $this->expiredOrder();
        $product->update(['is_active' => false]);
        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/orders/{$order->id}/repurchase-quote")
            ->assertOk()
            ->assertJsonPath('items.0.product_id', null)
            ->assertJsonPath('items.0.product_available', false)
            ->assertJsonPath('items.0.name', $product->name);
    }

    public function test_expired_order_with_observed_proof_cannot_use_repurchase_flow(): void
    {
        [$owner, $order] = $this->expiredOrder();
        PaymentSubmission::create([
            'order_id' => $order->id,
            'channel' => 'bank_transfer',
            'status' => PaymentSubmission::OBSERVED,
            'expected_amount' => $order->total,
            'currency' => 'PEN',
            'operation_number' => '001234567',
            'normalized_operation_number' => '001234567',
            'duplicate_fingerprint' => hash('sha256', 'observed-'.$order->id),
            'declared_paid_at' => now()->subHour(),
            'receiving_account_snapshot' => ['id' => 1],
            'submitted_by' => $owner->id,
            'submitted_at' => now()->subHour(),
            'reviewed_by' => $owner->id,
            'reviewed_at' => now(),
            'decision_reason' => 'Revisar comprobante',
            'idempotency_key' => 'observed-'.$order->id,
        ]);
        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/orders/{$order->id}/repurchase-quote")
            ->assertConflict()
            ->assertJsonPath('code', 'repurchase_not_available');
    }

    public function test_another_customer_cannot_quote_an_expired_order(): void
    {
        [, $order] = $this->expiredOrder();
        Sanctum::actingAs(User::factory()->create(['role' => 'customer', 'is_active' => true]));

        $this->getJson("/api/v1/orders/{$order->id}/repurchase-quote")->assertNotFound();
    }

    private function expiredOrder(int $requested = 6, int $stock = 20): array
    {
        $owner = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $suffix = (string) (Order::count() + 1);
        $warehouse = Warehouse::where('is_active', true)->where('is_default', true)->first();
        if (! $warehouse) {
            $branch = Branch::create(['code' => 'RP-B'.$suffix, 'name' => 'Sede de prueba', 'address' => 'Dirección de prueba', 'is_active' => true]);
            $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'ALM-PRINCIPAL', 'name' => 'Almacén principal', 'is_active' => true, 'is_default' => true]);
        }
        $product = Product::create([
            'name' => 'Producto de recompra '.$suffix,
            'slug' => 'producto-recompra-'.$suffix,
            'sku' => 'RP-'.$suffix,
            'price' => '109.00',
            'stock' => $stock,
            'is_active' => true,
        ]);
        $inventory = WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => $stock, 'reserved_quantity' => 0]);
        $order = Order::create([
            'user_id' => $owner->id,
            'status' => 'pending',
            'fulfillment_status' => 'reserved',
            'total' => (string) (109 * $requested),
            'shipping_info' => ['recipient_name' => 'Cliente de prueba'],
            'payment_method' => 'transferencia',
            'payment_status' => 'pending',
            'reserved_until' => now()->subMinute(),
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
        ]);
        $item = $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'warehouse_id' => $warehouse->id,
            'quantity' => $requested,
            'price' => '109.00',
            'regular_price' => '109.00',
            'subtotal' => (string) (109 * $requested),
        ]);
        InventoryReservation::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $requested,
            'status' => InventoryReservation::EXPIRED,
            'expires_at' => now()->subMinute(),
            'released_at' => now()->subMinute(),
            'idempotency_key' => 'repurchase-'.$order->id,
        ]);

        return [$owner, $order, $product, $inventory];
    }
}
