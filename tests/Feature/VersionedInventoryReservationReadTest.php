<?php

namespace Tests\Feature;

use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WarehouseInventory;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VersionedInventoryReservationReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_sequence_one_is_the_current_reservation(): void
    {
        $fixture = $this->fixture();
        $legacy = $this->reservation($fixture, 1, InventoryReservation::ACTIVE);

        $item = OrderItem::with(['reservation', 'currentReservation'])->findOrFail($fixture['item']->id);

        $this->assertSame($legacy->id, $item->reservation->id);
        $this->assertSame($legacy->id, $item->currentReservation->id);
    }

    public function test_current_reservation_prefers_active_second_version_over_expired_first_version(): void
    {
        $fixture = $this->fixture();
        $this->reservation($fixture, 1, InventoryReservation::EXPIRED);
        $current = $this->reservation($fixture, 2, InventoryReservation::ACTIVE);

        $item = OrderItem::with('currentReservation')->findOrFail($fixture['item']->id);

        $this->assertSame($current->id, $item->currentReservation->id);
        $this->assertSame(2, $item->currentReservation->reservation_sequence);
        $this->assertSame(InventoryReservation::ACTIVE, $item->currentReservation->status);
    }

    public function test_current_reservation_prefers_consumed_second_version_over_expired_first_version(): void
    {
        $fixture = $this->fixture();
        $this->reservation($fixture, 1, InventoryReservation::EXPIRED);
        $current = $this->reservation($fixture, 2, InventoryReservation::CONSUMED);

        $item = OrderItem::with('reservation')->findOrFail($fixture['item']->id);

        $this->assertSame($current->id, $item->reservation->id);
        $this->assertSame(2, $item->reservation->reservation_sequence);
        $this->assertSame(InventoryReservation::CONSUMED, $item->reservation->status);
    }

    public function test_reservations_returns_versions_in_stable_sequence_then_id_order(): void
    {
        $fixture = $this->fixture();
        $first = $this->reservation($fixture, 1, InventoryReservation::EXPIRED);
        $second = $this->reservation($fixture, 2, InventoryReservation::ACTIVE);

        $item = OrderItem::with('reservations')->findOrFail($fixture['item']->id);

        $this->assertSame([$first->id, $second->id], $item->reservations->pluck('id')->all());
        $this->assertSame([1, 2], $item->reservations->pluck('reservation_sequence')->all());
    }

    public function test_eager_loading_reservation_returns_current_version_without_follow_up_queries(): void
    {
        $first = $this->fixture();
        $this->reservation($first, 1, InventoryReservation::EXPIRED);
        $firstCurrent = $this->reservation($first, 2, InventoryReservation::ACTIVE);
        $second = $this->fixture();
        $this->reservation($second, 1, InventoryReservation::EXPIRED);
        $secondCurrent = $this->reservation($second, 2, InventoryReservation::CONSUMED);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $orders = Order::query()
            ->whereIn('id', [$first['order']->id, $second['order']->id])
            ->with('items.reservation')
            ->get();
        $queriesAfterEagerLoad = count(DB::getQueryLog());

        $currentIds = $orders->flatMap(fn (Order $order) => $order->items->map(function (OrderItem $item) {
            $this->assertTrue($item->relationLoaded('reservation'));

            return $item->reservation?->id;
        }))->all();
        $queriesAfterAccess = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame([$firstCurrent->id, $secondCurrent->id], $currentIds);
        $this->assertSame($queriesAfterEagerLoad, $queriesAfterAccess);
    }

    public function test_customer_tracking_uses_only_the_current_reservation_and_hides_reservation_internals(): void
    {
        $fixture = $this->fixture();
        $this->reservation($fixture, 1, InventoryReservation::EXPIRED);
        $this->reservation($fixture, 2, InventoryReservation::ACTIVE);

        Sanctum::actingAs($fixture['customer']);
        $response = $this->getJson('/api/v1/orders/'.$fixture['order']->id.'/tracking')->assertOk();

        $response
            ->assertJsonPath('order.items.0.reservation.status', InventoryReservation::ACTIVE)
            ->assertJsonPath('order.items.0.reservation.quantity', $fixture['item']->quantity)
            ->assertJsonMissingPath('order.items.0.reservation.resolution_case_id')
            ->assertJsonMissingPath('order.items.0.reservation.idempotency_key')
            ->assertJsonMissingPath('order.items.0.reservation.metadata')
            ->assertJsonMissingPath('order.items.0.reservations');
    }

    public function test_admin_order_list_exposes_only_the_current_reservation_summary(): void
    {
        $fixture = $this->fixture();
        $this->reservation($fixture, 1, InventoryReservation::EXPIRED);
        $this->reservation($fixture, 2, InventoryReservation::CONSUMED);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/admin/orders')->assertOk();
        $order = collect($response->json('data'))->firstWhere('id', $fixture['order']->id);

        $this->assertSame(InventoryReservation::CONSUMED, data_get($order, 'reservation_status'));
        $this->assertArrayNotHasKey('items', $order);
    }

    public function test_admin_order_list_returns_null_summary_when_an_item_has_no_reservation(): void
    {
        $fixture = $this->fixture();
        $before = InventoryReservation::query()->where('order_id', $fixture['order']->id)->count();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/orders')->assertOk();
        $rows = collect($response->json('data'))->where('id', $fixture['order']->id)->values();
        $after = InventoryReservation::query()->where('order_id', $fixture['order']->id)->count();

        $this->assertCount(1, $rows);
        $this->assertNull($rows->first()['reservation_status']);
        $this->assertArrayNotHasKey('items', $rows->first());
        $this->assertSame(0, $before);
        $this->assertSame($before, $after);
    }

    public function test_admin_order_list_keeps_each_current_reservation_attached_to_its_item(): void
    {
        $fixture = $this->fixture();
        $firstItem = $fixture['item'];
        $secondItem = OrderItem::create([
            'order_id' => $fixture['order']->id,
            'product_id' => $fixture['product']->id,
            'warehouse_id' => $fixture['inventory']->warehouse_id,
            'quantity' => 1,
            'price' => '25.00',
            'subtotal' => '25.00',
        ]);
        $firstCurrent = $this->reservation($fixture, 1, InventoryReservation::ACTIVE);
        $secondFixture = $fixture;
        $secondFixture['item'] = $secondItem;
        $this->reservation($secondFixture, 1, InventoryReservation::EXPIRED);
        $secondCurrent = $this->reservation($secondFixture, 2, InventoryReservation::CONSUMED);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/orders')->assertOk();
        $rows = collect($response->json('data'))->where('id', $fixture['order']->id)->values();
        $order = Order::query()->whereKey($fixture['order']->id)->with([
            'items' => fn ($query) => $query
                ->select(['order_items.id', 'order_items.order_id', 'order_items.warehouse_id'])
                ->orderBy('order_items.id'),
            'items.currentReservation' => fn ($query) => $query->select([
                'inventory_reservations.id',
                'inventory_reservations.order_item_id',
                'inventory_reservations.status',
            ]),
        ])->firstOrFail();

        $this->assertCount(1, $rows);
        $this->assertSame(InventoryReservation::ACTIVE, $rows->first()['reservation_status']);
        $this->assertArrayNotHasKey('items', $rows->first());
        $this->assertCount(2, $order->items);
        $this->assertSame([$firstItem->id, $secondItem->id], $order->items->pluck('id')->all());
        $this->assertTrue($order->items->every(fn (OrderItem $item) => $item->relationLoaded('currentReservation')));
        $this->assertSame([$firstCurrent->id, $secondCurrent->id], $order->items->map(fn (OrderItem $item) => $item->currentReservation?->id)->all());
        $this->assertSame([$firstItem->id, $secondItem->id], $order->items->map(fn (OrderItem $item) => $item->currentReservation?->order_item_id)->all());
        $this->assertSame([InventoryReservation::ACTIVE, InventoryReservation::CONSUMED], $order->items->map(fn (OrderItem $item) => $item->currentReservation?->status)->all());
    }

    /** @return array{customer: User, order: Order, item: OrderItem, product: Product, inventory: WarehouseInventory} */
    private function fixture(): array
    {
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $warehouse = app(InventoryService::class)->defaultWarehouse();
        $product = Product::create([
            'name' => 'Reserva versionada '.Str::random(8),
            'slug' => 'reserva-versionada-'.Str::uuid(),
            'sku' => 'VR-'.Str::upper(Str::random(10)),
            'price' => '25.00',
            'is_active' => true,
        ]);
        app(InventoryService::class)->initializeProduct($product, 10, $warehouse);
        $inventory = WarehouseInventory::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
        $order = Order::create([
            'user_id' => $customer->id,
            'status' => 'pending',
            'fulfillment_status' => Order::FULFILLMENT_RESERVED,
            'total' => '50.00',
            'shipping_info' => ['address' => 'Lectura versionada'],
            'payment_method' => 'transferencia',
            'payment_status' => 'pending',
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
            'reserved_until' => now()->addHour(),
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 2,
            'price' => '25.00',
            'subtotal' => '50.00',
        ]);

        return compact('customer', 'order', 'item', 'product', 'inventory');
    }

    /** @param array{order: Order, item: OrderItem, product: Product, inventory: WarehouseInventory} $fixture */
    private function reservation(array $fixture, int $sequence, string $status): InventoryReservation
    {
        return InventoryReservation::unguarded(fn () => InventoryReservation::create([
            'order_id' => $fixture['order']->id,
            'order_item_id' => $fixture['item']->id,
            'reservation_sequence' => $sequence,
            'product_id' => $fixture['product']->id,
            'warehouse_id' => $fixture['inventory']->warehouse_id,
            'quantity' => $fixture['item']->quantity,
            'status' => $status,
            'expires_at' => now()->addHour(),
            'consumed_at' => $status === InventoryReservation::CONSUMED ? now() : null,
            'released_at' => null,
            'idempotency_key' => 'versioned-read-'.$fixture['item']->id.'-'.$sequence.'-'.Str::uuid(),
            'metadata' => ['internal' => 'must-not-serialize'],
        ]));
    }
}
