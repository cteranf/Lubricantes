<?php

namespace Tests\Feature;

use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentSubmission;
use App\Models\Product;
use App\Models\User;
use App\Models\WarehouseInventory;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TreasuryPaymentReservationStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_reports_an_expired_legacy_reservation(): void
    {
        $fixture = $this->fixture();
        $this->reservation($fixture, 1, InventoryReservation::EXPIRED);

        $this->detail($fixture)->assertJsonPath('data.reservation_status', InventoryReservation::EXPIRED);
    }

    public function test_detail_reports_the_active_second_reservation_instead_of_the_expired_history(): void
    {
        $fixture = $this->fixture();
        $this->reservation($fixture, 1, InventoryReservation::EXPIRED);
        $this->reservation($fixture, 2, InventoryReservation::ACTIVE);

        $this->detail($fixture)->assertJsonPath('data.reservation_status', InventoryReservation::ACTIVE);
    }

    public function test_detail_reports_the_consumed_second_reservation_after_reassignment(): void
    {
        $fixture = $this->fixture();
        $this->reservation($fixture, 1, InventoryReservation::EXPIRED);
        $this->reservation($fixture, 2, InventoryReservation::CONSUMED);

        $this->detail($fixture)->assertJsonPath('data.reservation_status', InventoryReservation::CONSUMED);
    }

    public function test_current_reservation_relation_keeps_the_defensive_id_tiebreaker(): void
    {
        $source = file_get_contents(app_path('Models/OrderItem.php'));

        $this->assertMatchesRegularExpression(
            "/'reservation_sequence'\s*=>\s*'max',\s*'id'\s*=>\s*'max'/s",
            $source,
        );
    }

    public function test_detail_does_not_expose_reservation_history_or_internal_fields(): void
    {
        $fixture = $this->fixture();
        $this->reservation($fixture, 1, InventoryReservation::EXPIRED);
        $this->reservation($fixture, 2, InventoryReservation::CONSUMED);

        $response = $this->detail($fixture);
        $payload = $response->getContent();

        foreach (['reservation_sequence', 'resolution_case_id', 'idempotency_key', 'metadata'] as $private) {
            $this->assertStringNotContainsString($private, $payload);
        }
        $response->assertJsonMissingPath('data.reservations')
            ->assertJsonMissingPath('data.order.items');
    }

    public function test_treasury_list_and_detail_load_current_reservations_for_multiple_versioned_items(): void
    {
        $fixture = $this->fixture();
        $second = $this->secondItem($fixture);
        $this->reservation($fixture, 1, InventoryReservation::EXPIRED);
        $this->reservation($fixture, 2, InventoryReservation::CONSUMED);
        $this->reservation($second, 1, InventoryReservation::EXPIRED);
        $this->reservation($second, 2, InventoryReservation::CONSUMED);

        $list = $this->actingAs($fixture['treasury'], 'sanctum')
            ->getJson('/api/v1/treasury/payment-submissions')
            ->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $fixture['submission']->id);

        $this->assertSame(InventoryReservation::CONSUMED, $row['reservation_status']);
        $this->detail($fixture)
            ->assertJsonPath('data.reservation_status', InventoryReservation::CONSUMED)
            ->assertJsonMissingPath('data.order.items');
    }

    /** @return array{treasury: User, order: Order, item: OrderItem, product: Product, inventory: WarehouseInventory, submission: PaymentSubmission} */
    private function fixture(): array
    {
        $suffix = Str::lower(Str::random(10));
        $treasury = User::factory()->create(['role' => 'treasury', 'is_active' => true]);
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $warehouse = app(InventoryService::class)->defaultWarehouse();
        $product = Product::create([
            'name' => 'Reserva Treasury '.$suffix,
            'slug' => 'reserva-treasury-'.$suffix,
            'sku' => 'TR-'.Str::upper($suffix),
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
            'shipping_info' => ['address' => 'Lectura Treasury'],
            'payment_method' => 'transferencia',
            'payment_status' => 'pending',
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
            'reserved_until' => now()->subMinute(),
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 2,
            'price' => '25.00',
            'subtotal' => '50.00',
        ]);
        $submission = PaymentSubmission::create([
            'order_id' => $order->id,
            'channel' => 'yape',
            'status' => PaymentSubmission::PENDING_REVIEW,
            'expected_amount' => '50.00',
            'currency' => 'PEN',
            'operation_number' => '001234',
            'normalized_operation_number' => '001234',
            'duplicate_fingerprint' => hash('sha256', 'treasury-status-'.$suffix),
            'declared_paid_at' => now()->subMinute(),
            'origin_phone_last4' => '1234',
            'receiving_account_snapshot' => [],
            'submitted_by' => $customer->id,
            'submitted_at' => now()->subMinute(),
            'idempotency_key' => 'treasury-status-'.$suffix,
        ]);

        return compact('treasury', 'order', 'item', 'product', 'inventory', 'submission');
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
            'expires_at' => $status === InventoryReservation::ACTIVE ? now()->addHour() : now()->subHour(),
            'consumed_at' => $status === InventoryReservation::CONSUMED ? now() : null,
            'released_at' => null,
            'idempotency_key' => 'treasury-status-'.$fixture['item']->id.'-'.$sequence.'-'.Str::uuid(),
            'metadata' => ['private' => true],
        ]));
    }

    /** @return array{order: Order, item: OrderItem, product: Product, inventory: WarehouseInventory} */
    private function secondItem(array $fixture): array
    {
        $suffix = Str::lower(Str::random(10));
        $warehouse = $fixture['inventory']->warehouse;
        $product = Product::create([
            'name' => 'Segunda reserva Treasury '.$suffix,
            'slug' => 'segunda-reserva-treasury-'.$suffix,
            'sku' => 'TR2-'.Str::upper($suffix),
            'price' => '25.00',
            'is_active' => true,
        ]);
        app(InventoryService::class)->initializeProduct($product, 10, $warehouse);
        $inventory = WarehouseInventory::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
        $order = $fixture['order'];
        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 2,
            'price' => '25.00',
            'subtotal' => '50.00',
        ]);

        return compact('order', 'item', 'product', 'inventory');
    }

    private function detail(array $fixture)
    {
        return $this->actingAs($fixture['treasury'], 'sanctum')
            ->getJson('/api/v1/treasury/payment-submissions/'.$fixture['submission']->id)
            ->assertOk();
    }
}
