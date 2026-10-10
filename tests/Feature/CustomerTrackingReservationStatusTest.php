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

class CustomerTrackingReservationStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_payment_with_consumed_current_reservation_confirms_stock_without_expiry(): void
    {
        $fixture = $this->fixture();
        $this->reservation($fixture, 1, InventoryReservation::CONSUMED);

        $response = $this->submission($fixture)
            ->assertJsonPath('data.status', PaymentSubmission::APPROVED)
            ->assertJsonPath('data.message', 'Pago validado por Tesorería.')
            ->assertJsonPath('data.reservation_status', InventoryReservation::CONSUMED)
            ->assertJsonPath('data.reservation_expires_at', null)
            ->assertJsonPath('data.operation_number_masked', '••••34');
        $this->assertNotNull($response->json('data.validated_at'));

        $this->tracking($fixture)
            ->assertJsonPath('order.items.0.reservation.status', InventoryReservation::CONSUMED);

        $panel = file_get_contents(resource_path('js/components/CustomerTransferPaymentPanel.vue'));
        $this->assertStringContainsString("submission.reservation_status === 'consumed'", $panel);
        $this->assertStringContainsString('Stock confirmado para tu pedido', $panel);
        $this->assertStringContainsString("submission.reservation_status === 'active' && submission.reservation_expires_at", $panel);
    }

    public function test_active_current_reservation_keeps_its_real_expiry(): void
    {
        $fixture = $this->fixture();
        $reservation = $this->reservation($fixture, 1, InventoryReservation::ACTIVE);

        $this->submission($fixture)
            ->assertJsonPath('data.reservation_status', InventoryReservation::ACTIVE)
            ->assertJsonPath('data.reservation_expires_at', $reservation->expires_at->toIso8601String());
    }

    public function test_expired_history_does_not_replace_the_consumed_current_reservation(): void
    {
        $fixture = $this->fixture();
        $this->reservation($fixture, 1, InventoryReservation::EXPIRED);
        $this->reservation($fixture, 2, InventoryReservation::CONSUMED);

        $this->submission($fixture)
            ->assertJsonPath('data.reservation_status', InventoryReservation::CONSUMED)
            ->assertJsonPath('data.reservation_expires_at', null);
        $this->tracking($fixture)
            ->assertJsonPath('order.items.0.reservation.status', InventoryReservation::CONSUMED)
            ->assertJsonMissingPath('order.items.0.reservations');
    }

    public function test_mixed_current_reservations_use_a_neutral_state_without_global_expiry(): void
    {
        $fixture = $this->fixture();
        $second = $this->secondItem($fixture);
        $this->reservation($fixture, 1, InventoryReservation::ACTIVE);
        $this->reservation($second, 1, InventoryReservation::CONSUMED);

        $this->submission($fixture)
            ->assertJsonPath('data.reservation_status', 'mixed')
            ->assertJsonPath('data.reservation_expires_at', null);

        $panel = file_get_contents(resource_path('js/components/CustomerTransferPaymentPanel.vue'));
        $this->assertStringContainsString("submission.reservation_status === 'mixed'", $panel);
        $this->assertStringContainsString('El stock del pedido se encuentra en actualización', $panel);
    }

    public function test_absent_reservation_never_claims_that_stock_is_active(): void
    {
        $fixture = $this->fixture();

        $this->submission($fixture)
            ->assertJsonPath('data.reservation_status', 'none')
            ->assertJsonPath('data.reservation_expires_at', null);
    }

    public function test_customer_payload_exposes_only_the_current_safe_reservation_summary(): void
    {
        $fixture = $this->fixture();
        $this->reservation($fixture, 1, InventoryReservation::EXPIRED);
        $this->reservation($fixture, 2, InventoryReservation::CONSUMED);

        $payload = $this->submission($fixture)->getContent();

        foreach (['reservation_sequence', 'resolution_case_id', 'metadata', 'idempotency_key', 'reservations'] as $private) {
            $this->assertStringNotContainsString($private, $payload);
        }
    }

    public function test_customer_eager_load_qualifies_every_selected_of_many_column(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/V1/CustomerPaymentSubmissionController.php'));

        foreach (['inventory_reservations.id', 'inventory_reservations.order_item_id', 'inventory_reservations.status', 'inventory_reservations.expires_at'] as $column) {
            $this->assertStringContainsString($column, $source);
        }
        foreach (['inventory_reservations.reservation_sequence', 'inventory_reservations.resolution_case_id', 'inventory_reservations.metadata', 'inventory_reservations.idempotency_key'] as $private) {
            $this->assertStringNotContainsString($private, $source);
        }
    }

    /** @return array{customer: User, order: Order, item: OrderItem, product: Product, inventory: WarehouseInventory, submission: PaymentSubmission} */
    private function fixture(): array
    {
        $suffix = Str::lower(Str::random(10));
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $warehouse = app(InventoryService::class)->defaultWarehouse();
        $product = Product::create([
            'name' => 'Tracking reserva '.$suffix,
            'slug' => 'tracking-reserva-'.$suffix,
            'sku' => 'CTR-'.Str::upper($suffix),
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
            'status' => 'confirmed',
            'fulfillment_status' => Order::FULFILLMENT_RESERVED,
            'total' => '50.00',
            'shipping_info' => ['address' => 'Tracking'],
            'payment_method' => 'transferencia',
            'payment_status' => 'approved',
            'paid_at' => now(),
            'delivery_type' => 'delivery',
            'tracking_status' => 'confirmed',
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
        $submission = PaymentSubmission::create([
            'order_id' => $order->id,
            'channel' => 'yape',
            'status' => PaymentSubmission::APPROVED,
            'expected_amount' => '50.00',
            'currency' => 'PEN',
            'operation_number' => '001234',
            'normalized_operation_number' => '001234',
            'duplicate_fingerprint' => hash('sha256', 'tracking-'.$suffix),
            'declared_paid_at' => now()->subMinute(),
            'origin_phone_last4' => '1234',
            'receiving_account_snapshot' => [],
            'submitted_by' => $customer->id,
            'submitted_at' => now()->subMinute(),
            'reviewed_at' => now(),
            'idempotency_key' => 'tracking-'.$suffix,
        ]);

        return compact('customer', 'order', 'item', 'product', 'inventory', 'submission');
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
            'expires_at' => $status === InventoryReservation::ACTIVE ? now()->addHour()->startOfSecond() : now()->subHour()->startOfSecond(),
            'consumed_at' => $status === InventoryReservation::CONSUMED ? now() : null,
            'released_at' => $status === InventoryReservation::RELEASED ? now() : null,
            'idempotency_key' => 'tracking-reservation-'.$fixture['item']->id.'-'.$sequence.'-'.Str::uuid(),
            'metadata' => ['private' => true],
        ]));
    }

    /** @return array{order: Order, item: OrderItem, product: Product, inventory: WarehouseInventory} */
    private function secondItem(array $fixture): array
    {
        $suffix = Str::lower(Str::random(10));
        $warehouse = $fixture['inventory']->warehouse;
        $product = Product::create([
            'name' => 'Tracking mixto '.$suffix,
            'slug' => 'tracking-mixto-'.$suffix,
            'sku' => 'CTM-'.Str::upper($suffix),
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

    private function submission(array $fixture)
    {
        return $this->actingAs($fixture['customer'], 'sanctum')
            ->getJson('/api/v1/orders/'.$fixture['order']->id.'/payment-submission')
            ->assertOk();
    }

    private function tracking(array $fixture)
    {
        return $this->actingAs($fixture['customer'], 'sanctum')
            ->getJson('/api/v1/orders/'.$fixture['order']->id.'/tracking')
            ->assertOk();
    }
}
