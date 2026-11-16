<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderFulfillmentHistory;
use App\Models\OrderHandlingItem;
use App\Models\OrderHandlingProcess;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WarehouseInventory;
use App\Services\InventoryService;
use App\Services\OrderFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesShippingCoverage;
use Tests\TestCase;

class OrderFulfillmentPhaseThreeBTest extends TestCase
{
    use CreatesShippingCoverage, RefreshDatabase;

    public function test_new_cash_on_delivery_order_stays_reserved_without_physical_sale(): void
    {
        $product = $this->product(5);
        $order = $this->createOrder($product, 2, 'contra_entrega');
        $item = $order->items()->firstOrFail();
        $this->assertSame('reserved', $order->fulfillment_status);
        $this->assertSame('active', $item->reservation->status);
        $this->assertSame(5, $this->inventory($product)->quantity);
        $this->assertSame(2, $this->inventory($product)->reserved_quantity);
        $this->assertDatabaseMissing('inventory_movements', ['idempotency_key' => 'sale-order-item-'.$item->id]);
    }

    public function test_starting_cash_on_delivery_preparation_consumes_once_and_records_audit(): void
    {
        $admin = $this->admin();
        $product = $this->product(5);
        $order = $this->createOrder($product, 2, 'contra_entrega');
        $item = $order->items()->firstOrFail();
        Sanctum::actingAs($admin);
        $this->postJson($this->url($order, 'start-preparation'), ['observation' => 'Mesa 1'])->assertOk()->assertJsonPath('fulfillment.status', 'preparing');
        $this->postJson($this->url($order, 'start-preparation'))->assertOk();
        $order->refresh();
        $inventory = $this->inventory($product);
        $this->assertSame(3, $inventory->quantity);
        $this->assertSame(0, $inventory->reserved_quantity);
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertSame('pending', $order->payment_status);
        $this->assertNotNull($order->preparing_at);
        $this->assertSame($admin->id, $order->prepared_by);
        $this->assertSame(1, InventoryMovement::where('idempotency_key', 'sale-order-item-'.$item->id)->count());
        $this->assertSame(1, OrderFulfillmentHistory::where(['order_id' => $order->id, 'to_status' => 'preparing'])->count());
    }

    public function test_online_approved_and_transfer_approved_do_not_discount_again_when_preparing(): void
    {
        config()->set('payment.default_gateway', 'mock');
        config()->set('payment.mock.enabled', true);
        $admin = $this->admin();
        $onlineProduct = $this->product(5);
        $online = $this->createOrder($onlineProduct, 2, 'card');
        $this->postJson('/api/v1/payment/webhook', ['payment_id' => 'FUL-ONLINE', 'order_id' => $online->id])->assertOk();
        $before = $this->inventory($onlineProduct)->quantity;
        Sanctum::actingAs($admin);
        $this->postJson($this->url($online, 'start-preparation'))->assertOk();
        $this->assertSame($before, $this->inventory($onlineProduct)->quantity);
        $this->assertSame(1, InventoryMovement::where('reference_id', (string) $online->id)->where('type', 'sale')->count());

        $transferProduct = $this->product(5);
        $transfer = $this->createOrder($transferProduct, 2, 'transferencia');
        Sanctum::actingAs($admin);
        $this->postJson($this->url($transfer, 'approve-transfer'))->assertOk()->assertJsonPath('fulfillment.payment_status', 'approved');
        $before = $this->inventory($transferProduct)->quantity;
        $this->postJson($this->url($transfer, 'start-preparation'))->assertOk();
        $this->assertSame($before, $this->inventory($transferProduct)->quantity);
        $this->assertSame(1, InventoryMovement::where('reference_id', (string) $transfer->id)->where('type', 'sale')->count());
    }

    public function test_ready_and_delivered_require_order_and_do_not_change_inventory_and_cod_becomes_paid(): void
    {
        $admin = $this->admin();
        $product = $this->product(4);
        $order = $this->createOrder($product, 1, 'contra_entrega');
        Sanctum::actingAs($admin);
        $this->postJson($this->url($order, 'ready'))->assertUnprocessable();
        $this->postJson($this->url($order, 'delivered'))->assertUnprocessable();
        $this->postJson($this->url($order, 'start-preparation'))->assertOk();
        $physical = $this->inventory($product)->quantity;
        $this->completeHandling($order);
        $this->postJson($this->url($order, 'ready'), ['observation' => 'Empacado'])->assertOk()->assertJsonPath('fulfillment.status', 'ready');
        $this->assertSame($physical, $this->inventory($product)->quantity);
        $this->postJson($this->url($order, 'delivered'), ['observation' => 'Recibido'])->assertUnprocessable();
        $this->completeDelivery($order);
        $this->postJson('/api/v1/admin/orders/'.$order->id.'/delivery/confirm', $this->deliveryPayload())->assertOk();
        $order->refresh();
        $this->assertSame($physical, $this->inventory($product)->quantity);
        $this->assertSame('approved', $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $this->assertTrue($order->paid_at->lessThanOrEqualTo($order->delivered_at));
        $this->assertNotNull($order->ready_at);
        $this->assertNotNull($order->delivered_at);
        $this->assertSame($admin->id, $order->ready_by);
        $this->assertSame($admin->id, $order->delivered_by);
        $this->assertSame(3, OrderFulfillmentHistory::where('order_id', $order->id)->count());

        $delivery = OrderDelivery::where('order_id', $order->id)->firstOrFail();
        $deliveredAt = $order->delivered_at->toISOString();
        $paidAt = $order->paid_at->toISOString();
        $movementCount = InventoryMovement::where('reference_id', (string) $order->id)->count();
        $paymentCount = DB::table('payment_transactions')->where('order_id', $order->id)->count();
        $deliveryHistoryCount = DB::table('order_delivery_histories')->where('order_delivery_id', $delivery->id)->count();

        app(OrderFulfillmentService::class)->finalizeDeliveredFromDelivery($order, $admin, 'Reintento idempotente', $delivery->delivered_at);
        $order->refresh();

        $this->assertSame($deliveredAt, $order->delivered_at?->toISOString());
        $this->assertSame($paidAt, $order->paid_at?->toISOString());
        $this->assertSame('delivered', $order->fulfillment_status);
        $this->assertSame('delivered', $order->status);
        $this->assertSame('delivered', $order->tracking_status);
        $this->assertSame($movementCount, InventoryMovement::where('reference_id', (string) $order->id)->count());
        $this->assertSame($paymentCount, DB::table('payment_transactions')->where('order_id', $order->id)->count());
        $this->assertSame(3, OrderFulfillmentHistory::where('order_id', $order->id)->count());
        $this->assertSame($deliveryHistoryCount, DB::table('order_delivery_histories')->where('order_delivery_id', $delivery->id)->count());
    }

    public function test_pickup_delivery_finalization_uses_pickup_instant_and_is_idempotent(): void
    {
        $admin = $this->admin();
        $order = Order::create([
            'user_id' => $this->customer()->id,
            'status' => 'confirmed',
            'total' => '10.00',
            'shipping_info' => [],
            'payment_method' => 'transferencia',
            'payment_status' => 'approved',
            'delivery_type' => 'pickup',
            'tracking_status' => 'ready_for_pickup',
            'fulfillment_status' => Order::FULFILLMENT_READY,
            'delivery_flow_version' => 1,
            'ready_at' => now()->subHour(),
        ]);
        $pickedUpAt = now()->subMinutes(5);
        $delivery = OrderDelivery::create([
            'order_id' => $order->id,
            'method' => OrderDelivery::STORE_PICKUP,
            'status' => OrderDelivery::DELIVERED,
            'delivered_at' => $pickedUpAt,
            'picked_up_at' => $pickedUpAt,
            'confirmed_at' => $pickedUpAt,
            'recipient_name' => 'Cliente de prueba',
            'recipient_document_type' => 'DNI',
            'recipient_document_number' => '12345678',
        ]);

        app(OrderFulfillmentService::class)->finalizeDeliveredFromDelivery($order, $admin, 'Recojo confirmado', $pickedUpAt);
        $order->refresh();
        $historyCount = OrderFulfillmentHistory::where('order_id', $order->id)->count();
        $storedDeliveredAt = $order->delivered_at?->copy()->startOfSecond()->toISOString();
        $storedPickedUpAt = $order->picked_up_at?->copy()->startOfSecond()->toISOString();

        $this->assertSame('delivered', $order->fulfillment_status);
        $this->assertSame('delivered', $order->status);
        $this->assertSame('picked_up', $order->tracking_status);
        $this->assertSame($pickedUpAt->copy()->startOfSecond()->toISOString(), $storedDeliveredAt);
        $this->assertSame($pickedUpAt->copy()->startOfSecond()->toISOString(), $storedPickedUpAt);
        $this->assertSame(1, $historyCount);

        app(OrderFulfillmentService::class)->finalizeDeliveredFromDelivery($order, $admin, 'No debe duplicar', $delivery->delivered_at);
        $order->refresh();

        $this->assertSame($storedDeliveredAt, $order->delivered_at?->copy()->startOfSecond()->toISOString());
        $this->assertSame($storedPickedUpAt, $order->picked_up_at?->copy()->startOfSecond()->toISOString());
        $this->assertSame($historyCount, OrderFulfillmentHistory::where('order_id', $order->id)->count());
    }

    public function test_customer_tracking_uses_public_image_url_iso_instants_and_clear_cod_payment_event(): void
    {
        Storage::fake('public');
        $legacyImagePath = 'products/aceite.jpg';
        Storage::disk('public')->put($legacyImagePath, 'public fixture image bytes');
        $admin = $this->admin();
        $product = $this->product(4);
        $product->update(['image_path' => '/storage/'.$legacyImagePath]);
        $order = $this->createOrder($product, 1, 'contra_entrega');
        Sanctum::actingAs($admin);
        $this->postJson($this->url($order, 'start-preparation'))->assertOk();
        $this->completeHandling($order);
        $this->postJson($this->url($order, 'ready'))->assertOk();
        $this->completeDelivery($order);

        // Tracking reads the current public product image; it is not an order-image snapshot.
        // A second item exercises eager loading across multiple products.
        $secondProduct = $this->product(1);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $secondProduct->id,
            'quantity' => 1,
            'price' => '10.00',
            'subtotal' => '10.00',
        ]);

        $order->refresh();
        Sanctum::actingAs($order->user);
        $imageQueries = [];
        DB::listen(static function ($query) use (&$imageQueries): void {
            if (preg_match('/\bfrom\s+[`"]?product_images\b/i', $query->sql) === 1) {
                $imageQueries[] = strtolower(preg_replace('/\s+/', ' ', trim($query->sql)));
            }
        });
        $response = $this->getJson('/api/v1/orders/'.$order->id.'/tracking')->assertOk();
        $timeline = $response->json('timeline');
        $delivered = collect($timeline)->firstWhere('status', 'delivered');
        $payment = collect($timeline)->last();

        $response->assertJsonPath('order.items.0.image_url', Storage::disk('public')->url($legacyImagePath));
        $this->assertCount(1, $imageQueries, 'Tracking must eager-load product_images once across order items.');
        foreach (['original_path', 'pending_original_path', 'generation_id'] as $privateKey) {
            $response->assertJsonMissingPath('order.items.0.'.$privateKey);
        }
        $this->assertMatchesRegularExpression('/(?:Z|[+-]00:00)$/', $response->json('order.created_at'));
        $this->assertSame('payment', $payment['status']);
        $this->assertSame('Pago confirmado', $payment['label']);
        $this->assertTrue($payment['completed']);
        $this->assertSame($order->paid_at->copy()->utc()->toIso8601String(), $payment['date']);
    }

    public function test_tracking_uses_a_placeholder_when_the_current_public_image_is_removed(): void
    {
        Storage::fake('public');
        $legacyImagePath = 'products/removed-after-order.jpg';
        Storage::disk('public')->put($legacyImagePath, 'public fixture image bytes');
        $product = $this->product(1);
        $product->update(['image_path' => '/storage/'.$legacyImagePath]);
        $order = $this->createOrder($product, 1, 'contra_entrega');

        Storage::disk('public')->delete($legacyImagePath);
        Sanctum::actingAs($order->user);

        $this->getJson('/api/v1/orders/'.$order->id.'/tracking')
            ->assertOk()
            ->assertJsonPath('order.items.0.image_url', null);
    }

    public function test_product_image_url_does_not_expose_local_paths_and_tracking_allows_no_image(): void
    {
        $product = $this->product(2);
        $product->update(['image_path' => 'C:\\private\\products\\secret.jpg']);
        $this->assertNull($product->refresh()->image_url);

        $product->update(['image_path' => null]);
        $order = $this->createOrder($product, 1, 'contra_entrega');
        Sanctum::actingAs($order->user);
        $this->getJson('/api/v1/orders/'.$order->id.'/tracking')->assertOk()->assertJsonPath('order.items.0.image_url', null);
    }

    public function test_cancellation_before_preparation_releases_without_return_and_cannot_prepare_afterward(): void
    {
        $admin = $this->admin();
        $product = $this->product(5);
        $order = $this->createOrder($product, 2, 'contra_entrega');
        Sanctum::actingAs($admin);
        $this->postJson($this->url($order, 'cancel'), ['reason' => 'Cliente desistio'])->assertOk()->assertJsonPath('fulfillment.status', 'canceled');
        $this->assertSame(5, $this->inventory($product)->quantity);
        $this->assertSame(0, $this->inventory($product)->reserved_quantity);
        $this->assertSame(0, InventoryMovement::where('type', 'cancellation_return')->where('reference_id', (string) $order->id)->count());
        $this->postJson($this->url($order, 'start-preparation'))->assertUnprocessable();
    }

    public function test_cancellation_after_preparation_restores_once(): void
    {
        $admin = $this->admin();
        $product = $this->product(5);
        $order = $this->createOrder($product, 2, 'contra_entrega');
        $item = $order->items()->firstOrFail();
        Sanctum::actingAs($admin);
        $this->postJson($this->url($order, 'start-preparation'))->assertOk();
        $this->assertSame(3, $this->inventory($product)->quantity);
        $this->postJson($this->url($order, 'cancel'), ['reason' => 'Incidencia operativa'])->assertOk();
        $this->postJson($this->url($order, 'cancel'), ['reason' => 'Repetida'])->assertOk();
        $this->assertSame(5, $this->inventory($product)->quantity);
        $this->assertSame(1, InventoryMovement::where('idempotency_key', 'cancellation-order-item-'.$item->id)->count());
        $this->assertSame(1, OrderFulfillmentHistory::where(['order_id' => $order->id, 'to_status' => 'canceled'])->count());
    }

    public function test_consumed_reservation_and_preparing_order_are_not_expired(): void
    {
        $admin = $this->admin();
        $product = $this->product(3);
        $order = $this->createOrder($product, 1, 'contra_entrega');
        Sanctum::actingAs($admin);
        $this->postJson($this->url($order, 'start-preparation'))->assertOk();
        InventoryReservation::where('order_id', $order->id)->update(['expires_at' => now()->subMinute()]);
        $before = $this->inventory($product)->quantity;
        $this->artisan('inventory:expire-reservations')->assertSuccessful();
        $this->assertSame($before, $this->inventory($product)->quantity);
        $this->assertSame('preparing', $order->refresh()->fulfillment_status);
        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $order->id, 'status' => 'consumed']);
    }

    public function test_customer_is_forbidden_and_admin_response_contains_actions_people_and_history(): void
    {
        $product = $this->product(2);
        $order = $this->createOrder($product, 1, 'contra_entrega');
        Sanctum::actingAs($this->customer());
        $this->postJson($this->url($order, 'start-preparation'))->assertForbidden();
        $this->getJson('/api/v1/admin/orders/'.$order->id.'/fulfillment')->assertForbidden();
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/admin/orders/'.$order->id.'/fulfillment')->assertOk()->assertJsonStructure(['order' => ['items'], 'fulfillment' => ['status', 'label', 'actions', 'payment_status', 'reservation_status', 'warehouse', 'expires_at', 'history']]);
    }

    public function test_admin_fulfillment_detail_projects_only_the_operational_order_allowlist(): void
    {
        $product = $this->product(5);
        $order = $this->createOrder($product, 1, 'contra_entrega');
        $order->update([
            'checkout_token' => 'checkout-token-private-sentinel',
            'payment_data' => [
                'provider_payload' => 'provider-payload-private-sentinel',
                'credential' => 'provider-credential-private-sentinel',
            ],
        ]);
        Sanctum::actingAs($this->admin());

        $response = $this->getJson('/api/v1/admin/orders/'.$order->id.'/fulfillment')->assertOk();
        $json = $response->json();

        $this->assertSame([
            'id', 'user', 'items', 'payment_method', 'payment_status', 'delivery_type',
            'has_payment_submission', 'payment_submission_status', 'pickup_branch_name_snapshot',
            'pickup_address_snapshot', 'pickup_district_snapshot', 'pickup_business_hours_snapshot',
            'pickup_instructions_snapshot', 'shipping_info', 'shipping_district_snapshot',
            'shipping_zone_name_snapshot', 'shipping_amount', 'shipping_estimated_days_min_snapshot',
            'shipping_estimated_days_max_snapshot',
        ], array_keys($json['order']));
        $this->assertSame(['name'], array_keys($json['order']['user']));
        $this->assertSame([
            'id', 'product', 'warehouse', 'reservation', 'quantity', 'price',
        ], array_keys($json['order']['items'][0]));
        $this->assertSame(['name'], array_keys($json['order']['items'][0]['product']));
        $this->assertSame($product->name, $json['order']['items'][0]['product']['name']);
        $this->assertSame(['name'], array_keys($json['order']['items'][0]['warehouse']));
        $this->assertSame(['status'], array_keys($json['order']['items'][0]['reservation']));
        $this->assertSame('reserved', $json['fulfillment']['status']);
        $this->assertArrayHasKey('start_preparation', $json['fulfillment']['actions']);
        $this->assertTrue($json['fulfillment']['actions']['start_preparation']);
        $this->assertTrue($json['fulfillment']['picking_packing']['available']);
        $this->assertSame('pending', $json['fulfillment']['picking_packing']['picking']['status']);
        $this->assertArrayHasKey('start_picking', $json['fulfillment']['picking_packing']['actions']);
        $this->assertArrayNotHasKey('checkout_token', $json['order']);
        $this->assertArrayNotHasKey('payment_data', $json['order']);
        $this->assertArrayNotHasKey('paymentSubmission', $json['order']);

        $this->assertNoPrivateOrderFields($json);
        $this->assertStringNotContainsString('checkout-token-private-sentinel', $response->getContent());
        $this->assertStringNotContainsString('provider-payload-private-sentinel', $response->getContent());
        $this->assertStringNotContainsString('provider-credential-private-sentinel', $response->getContent());
    }

    public function test_admin_fulfillment_detail_eager_loads_products_for_multiple_items_without_n_plus_one(): void
    {
        $product = $this->product(5);
        $order = $this->createOrder($product, 1, 'contra_entrega');
        Sanctum::actingAs($this->admin());
        $captureQueries = true;
        $queriesByRequest = [];
        DB::listen(static function ($query) use (&$captureQueries, &$queriesByRequest): void {
            if ($captureQueries && preg_match('/\\bfrom\\s+[`"]?products[`"]?(?:\\s|$)/i', $query->sql) === 1) {
                $queriesByRequest[count($queriesByRequest)] = strtolower(preg_replace('/\\s+/', ' ', str_replace(['`', '"', '[', ']'], '', trim($query->sql))));
            }
        });

        $smallResponse = $this->getJson('/api/v1/admin/orders/'.$order->id.'/fulfillment')->assertOk();
        $smallQueryCount = count($queriesByRequest);
        $captureQueries = false;

        $products = collect([$product]);
        foreach (range(1, 3) as $index) {
            $additionalProduct = $this->product(5);
            $products->push($additionalProduct);
            OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $additionalProduct->id,
                'product_name' => $additionalProduct->name,
                'quantity' => 1,
                'price' => '10.00',
                'subtotal' => '10.00',
            ]);
        }

        $queriesBeforeLargeRequest = count($queriesByRequest);
        $captureQueries = true;
        $largeResponse = $this->getJson('/api/v1/admin/orders/'.$order->id.'/fulfillment')->assertOk();
        $captureQueries = false;
        $largeQueries = array_slice($queriesByRequest, $queriesBeforeLargeRequest);

        $this->assertCount(1, $smallResponse->json('order.items'));
        $this->assertCount(4, $largeResponse->json('order.items'));
        $this->assertLessThanOrEqual(4, $smallQueryCount, 'Product lookups must stay within a fixed bounded query count for one item.');
        $this->assertLessThanOrEqual(4, count($largeQueries), 'Product lookups must stay within the same fixed bound as item count grows.');
        $this->assertNotEmpty($largeQueries);
        $boundedQueries = array_values(array_filter($largeQueries, fn (string $query) => preg_match('/\\bwhere\\b.*\\bid\\s+in\\s*\\(/', $query) === 1));
        $this->assertNotEmpty($boundedQueries, 'The endpoint must fetch products for multiple items using a set query.');
        $this->assertMatchesRegularExpression('/\\bid\\s+in\\s*\\([^)]*,/', implode(' ', $boundedQueries));
        foreach ($largeQueries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\\bwhere\\b.*\\bproducts?\\.id\\s*=\\s*\\?/', $query);
        }
        foreach ($products as $product) {
            $this->assertContains($product->name, collect($largeResponse->json('order.items'))->pluck('product.name')->all());
        }
    }

    public function test_fulfillment_detail_get_for_reserved_order_is_read_only_and_returns_initial_projection(): void
    {
        $product = $this->product(5);
        $order = $this->createOrder($product, 2, 'contra_entrega');
        $before = $order->fresh()->only(['status', 'fulfillment_status', 'tracking_status', 'updated_at']);
        $before['updated_at'] = $order->fresh()->updated_at?->toISOString();
        Sanctum::actingAs($this->admin());

        [$firstResponse, $firstWrites] = $this->captureDatabaseWrites(fn () => $this->getJson('/api/v1/admin/orders/'.$order->id.'/fulfillment')->assertOk());
        [$secondResponse, $secondWrites] = $this->captureDatabaseWrites(fn () => $this->getJson('/api/v1/admin/orders/'.$order->id.'/fulfillment')->assertOk());

        $firstResponse->assertJsonPath('fulfillment.status', Order::FULFILLMENT_RESERVED)
            ->assertJsonPath('fulfillment.picking_packing.available', true)
            ->assertJsonPath('fulfillment.picking_packing.legacy', false)
            ->assertJsonPath('fulfillment.picking_packing.picking.status', OrderHandlingProcess::PENDING)
            ->assertJsonPath('fulfillment.picking_packing.packing.status', OrderHandlingProcess::PENDING)
            ->assertJsonPath('fulfillment.picking_packing.picking.started_at', null)
            ->assertJsonPath('fulfillment.picking_packing.actions.start_picking', false)
            ->assertJsonPath('fulfillment.picking_packing.actions.mark_ready', false)
            ->assertJsonPath('fulfillment.picking_packing.items.0.ordered_quantity', 2);
        $this->assertSame($firstResponse->json(), $secondResponse->json());
        $this->assertSame([], $firstWrites);
        $this->assertSame([], $secondWrites);
        $this->assertSame(0, OrderHandlingProcess::where('order_id', $order->id)->count());
        $this->assertSame(0, OrderHandlingItem::whereHas('process', fn ($query) => $query->where('order_id', $order->id))->count());
        $after = $order->fresh();
        $afterSnapshot = $after->only(['status', 'fulfillment_status', 'tracking_status']);
        $afterSnapshot['updated_at'] = $after->updated_at?->toISOString();
        $this->assertSame($before, $afterSnapshot);
    }

    public function test_fulfillment_detail_get_for_preparing_order_without_process_is_read_only(): void
    {
        $product = $this->product(5);
        $order = $this->createOrder($product, 2, 'contra_entrega');
        $order->update([
            'status' => 'confirmed',
            'fulfillment_status' => Order::FULFILLMENT_PREPARING,
            'tracking_status' => 'processing',
            'preparing_at' => now(),
        ]);
        $before = $order->fresh()->only(['status', 'fulfillment_status', 'tracking_status', 'preparing_at', 'updated_at']);
        $before['preparing_at'] = $order->fresh()->preparing_at?->toISOString();
        $before['updated_at'] = $order->fresh()->updated_at?->toISOString();
        Sanctum::actingAs($this->admin());

        [$response, $writes] = $this->captureDatabaseWrites(fn () => $this->getJson('/api/v1/admin/orders/'.$order->id.'/fulfillment')->assertOk());

        $response->assertJsonPath('fulfillment.status', Order::FULFILLMENT_PREPARING)
            ->assertJsonPath('fulfillment.actions.mark_ready', false)
            ->assertJsonPath('fulfillment.picking_packing.picking.status', OrderHandlingProcess::PENDING)
            ->assertJsonPath('fulfillment.picking_packing.picking.started_at', null)
            ->assertJsonPath('fulfillment.picking_packing.packing.status', OrderHandlingProcess::PENDING)
            ->assertJsonPath('fulfillment.picking_packing.actions.start_picking', true)
            ->assertJsonPath('fulfillment.picking_packing.actions.update_picking', false)
            ->assertJsonPath('fulfillment.picking_packing.items.0.picked_quantity', 0);
        $this->assertSame([], $writes);
        $this->assertSame(0, OrderHandlingProcess::where('order_id', $order->id)->count());
        $this->assertSame(0, OrderHandlingItem::whereHas('process', fn ($query) => $query->where('order_id', $order->id))->count());
        $after = $order->fresh();
        $afterSnapshot = $after->only(['status', 'fulfillment_status', 'tracking_status']);
        $afterSnapshot['preparing_at'] = $after->preparing_at?->toISOString();
        $afterSnapshot['updated_at'] = $after->updated_at?->toISOString();
        $this->assertSame($before, $afterSnapshot);
    }

    public function test_fulfillment_detail_get_preserves_existing_process_and_items_without_writes(): void
    {
        $product = $this->product(5);
        $order = $this->createOrder($product, 2, 'contra_entrega');
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $this->postJson($this->url($order, 'start-preparation'))->assertOk();
        $process = OrderHandlingProcess::where('order_id', $order->id)->firstOrFail();
        $handlingItem = OrderHandlingItem::where('order_handling_process_id', $process->id)->firstOrFail();
        $handlingItem->update(['picked_quantity' => 1]);
        $processFresh = $process->fresh();
        $processBefore = $processFresh->only(['picking_status', 'packing_status']);
        $processBefore['updated_at'] = $processFresh->updated_at?->toISOString();
        $itemFresh = $handlingItem->fresh();
        $itemBefore = $itemFresh->only(['picked_quantity', 'packed_quantity']);
        $itemBefore['updated_at'] = $itemFresh->updated_at?->toISOString();

        [$response, $writes] = $this->captureDatabaseWrites(fn () => $this->getJson('/api/v1/admin/orders/'.$order->id.'/fulfillment')->assertOk());

        $response->assertJsonPath('fulfillment.picking_packing.items.0.picked_quantity', 1)
            ->assertJsonPath('fulfillment.picking_packing.picking.status', OrderHandlingProcess::PENDING);
        $this->assertSame([], $writes);
        $this->assertSame(1, OrderHandlingProcess::where('order_id', $order->id)->count());
        $this->assertSame(1, OrderHandlingItem::where('order_handling_process_id', $process->id)->count());
        $processAfter = $process->fresh();
        $processAfterSnapshot = $processAfter->only(['picking_status', 'packing_status']);
        $processAfterSnapshot['updated_at'] = $processAfter->updated_at?->toISOString();
        $itemAfter = $handlingItem->fresh();
        $itemAfterSnapshot = $itemAfter->only(['picked_quantity', 'packed_quantity']);
        $itemAfterSnapshot['updated_at'] = $itemAfter->updated_at?->toISOString();
        $this->assertSame($processBefore, $processAfterSnapshot);
        $this->assertSame($itemBefore, $itemAfterSnapshot);
    }

    public function test_start_preparation_explicitly_initializes_handling_once_and_unauthorized_user_cannot_initialize(): void
    {
        $product = $this->product(5);
        $order = $this->createOrder($product, 2, 'contra_entrega');
        Sanctum::actingAs($order->user);
        $this->getJson('/api/v1/admin/orders/'.$order->id.'/fulfillment')->assertForbidden();
        $this->postJson($this->url($order, 'start-preparation'))->assertForbidden();
        $this->assertSame(0, OrderHandlingProcess::where('order_id', $order->id)->count());
        $this->assertSame(0, OrderHandlingItem::whereHas('process', fn ($query) => $query->where('order_id', $order->id))->count());

        Sanctum::actingAs($this->admin());
        $this->postJson($this->url($order, 'start-preparation'))->assertOk()
            ->assertJsonPath('fulfillment.picking_packing.picking.status', OrderHandlingProcess::PENDING);
        $process = OrderHandlingProcess::where('order_id', $order->id)->firstOrFail();
        $itemsAfterFirstStart = OrderHandlingItem::where('order_handling_process_id', $process->id)->count();
        $this->assertSame(1, OrderHandlingProcess::where('order_id', $order->id)->count());
        $this->assertSame($order->items()->count(), $itemsAfterFirstStart);

        $this->postJson($this->url($order, 'start-preparation'))->assertOk();
        $this->assertSame(1, OrderHandlingProcess::where('order_id', $order->id)->count());
        $this->assertSame($itemsAfterFirstStart, OrderHandlingItem::where('order_handling_process_id', $process->id)->count());
    }

    public function test_legacy_order_preparation_never_duplicates_existing_sale(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $product = $this->product(5);
        $order = Order::create(['user_id' => $customer->id, 'status' => 'confirmed', 'total' => 20, 'shipping_info' => ['address' => 'Legacy', 'city' => 'Lima'], 'payment_method' => 'contra_entrega', 'payment_status' => 'pending', 'delivery_type' => 'delivery', 'tracking_status' => 'confirmed']);
        $item = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'warehouse_id' => app(InventoryService::class)->defaultWarehouse()->id, 'quantity' => 2, 'price' => 10, 'subtotal' => 20]);
        $item->setRelation('product', $product);
        $item->setRelation('warehouse', app(InventoryService::class)->defaultWarehouse());
        app(InventoryService::class)->sell($item, $customer);
        $before = $this->inventory($product)->quantity;
        Sanctum::actingAs($admin);
        $this->postJson($this->url($order, 'start-preparation'))->assertOk();
        $this->assertSame($before, $this->inventory($product)->quantity);
        $this->assertSame(1, InventoryMovement::where('idempotency_key', 'sale-order-item-'.$item->id)->count());
    }

    private function createOrder(Product $product, int $quantity, string $method): Order
    {
        Sanctum::actingAs($this->customer());
        $id = $this->postJson('/api/v1/orders', $this->withShippingCoverage(['shipping_info' => ['address' => 'Av. Operativa', 'city' => 'Lima', 'phone' => '999'], 'payment_method' => $method, 'delivery_type' => 'delivery', 'items' => [['product_id' => $product->id, 'quantity' => $quantity]]]))->assertCreated()->json('id');

        return Order::with('items.reservation')->findOrFail($id);
    }

    private function assertNoPrivateOrderFields(array $value): void
    {
        foreach ($value as $key => $child) {
            $this->assertNotContains($key, ['checkout_token', 'payment_data', 'paymentSubmission', 'provider_payload', 'credential']);
            if (is_array($child)) {
                $this->assertNoPrivateOrderFields($child);
            }
        }
    }

    private function captureDatabaseWrites(callable $callback): array
    {
        $capturing = true;
        $writes = [];
        DB::listen(static function ($query) use (&$capturing, &$writes): void {
            if ($capturing && preg_match('/^\\s*(?:insert|update|delete|replace)\\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        try {
            $result = $callback();
        } finally {
            $capturing = false;
        }

        return [$result, $writes];
    }

    private function product(int $stock): Product
    {
        $p = Product::create(['name' => 'Producto '.Str::random(6), 'slug' => 'producto-'.Str::uuid(), 'sku' => 'FUL-'.Str::random(8), 'price' => 10, 'is_active' => true]);
        app(InventoryService::class)->initializeProduct($p, $stock, app(InventoryService::class)->defaultWarehouse());

        return $p->refresh();
    }

    private function inventory(Product $product): WarehouseInventory
    {
        return WarehouseInventory::where('product_id', $product->id)->where('warehouse_id', app(InventoryService::class)->defaultWarehouse()->id)->firstOrFail()->refresh();
    }

    private function url(Order $order, string $action): string
    {
        return '/api/v1/admin/orders/'.$order->id.'/fulfillment/'.$action;
    }

    private function completeHandling(Order $order): void
    {
        $base = '/api/v1/admin/orders/'.$order->id;
        $this->postJson($base.'/picking/start')->assertOk();
        foreach ($order->items as $item) {
            $this->patchJson($base.'/picking/items/'.$item->id, ['picked_quantity' => $item->quantity])->assertOk();
        }$this->postJson($base.'/picking/complete')->assertOk();
        $this->postJson($base.'/packing/start')->assertOk();
        foreach ($order->items as $item) {
            $this->patchJson($base.'/packing/items/'.$item->id, ['packed_quantity' => $item->quantity])->assertOk();
        }$this->postJson($base.'/packing/complete')->assertOk();
    }

    private function completeDelivery(Order $order): void
    {
        $base = '/api/v1/admin/orders/'.$order->id.'/delivery';
        $driver = User::factory()->create(['role' => 'admin', 'can_deliver' => true]);
        $this->postJson($base.'/initialize')->assertCreated();
        $this->postJson($base.'/assign-driver', ['delivery_user_id' => $driver->id])->assertOk();
        $this->postJson($base.'/dispatch')->assertOk();
        $this->postJson($base.'/attempts')->assertCreated();
        $this->postJson($base.'/out-for-delivery')->assertOk();
        $this->postJson($base.'/confirm', $this->deliveryPayload())->assertOk()->assertJsonPath('delivery.status', 'delivered');
    }

    private function deliveryPayload(): array
    {
        return ['recipient_name' => 'Cliente de prueba', 'recipient_document_type' => 'DNI', 'recipient_document_number' => '12345678', 'confirmation_method' => 'manual', 'money_received' => true, 'collection_method' => 'cash'];
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
