<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderFulfillmentHistory;
use App\Models\User;
use App\Services\OrderStateService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderOperationalStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_explicit_operational_states_are_preserved_when_coherent(): void
    {
        foreach ([
            'reserved' => 'reserved',
            'preparing' => 'preparing',
            'ready' => 'ready',
            'delivered' => 'delivered',
            'canceled' => 'canceled',
        ] as $explicit => $expected) {
            $order = $this->unpersistedOrder(['fulfillment_status' => $explicit]);

            $this->assertSame($expected, $order->effectiveFulfillmentStatus());
        }
    }

    public function test_unknown_explicit_state_is_ignored_and_never_returned(): void
    {
        $order = $this->unpersistedOrder([
            'fulfillment_status' => 'future_internal_state',
            'tracking_status' => 'processing',
        ]);

        $this->assertSame('preparing', $order->effectiveFulfillmentStatus());
    }

    public function test_canceled_reserved_tracking_canceled_legacy_contradiction_is_canceled(): void
    {
        $order = $this->unpersistedOrder([
            'status' => 'canceled',
            'fulfillment_status' => 'reserved',
            'tracking_status' => 'canceled',
        ]);

        $this->assertSame('canceled', $order->effectiveFulfillmentStatus());
    }

    public function test_delivery_evidence_wins_over_commercial_conflicts_without_writing(): void
    {
        $order = $this->order([
            'status' => 'canceled',
            'fulfillment_status' => 'reserved',
            'tracking_status' => 'canceled',
            'delivered_at' => now(),
        ]);
        $columns = ['status', 'fulfillment_status', 'tracking_status', 'delivered_at', 'updated_at'];
        $before = (array) DB::table('orders')->where('id', $order->id)->first($columns);
        $deliveryCount = OrderDelivery::where('order_id', $order->id)->count();
        $historyCount = OrderFulfillmentHistory::where('order_id', $order->id)->count();
        $updateQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$updateQueries): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'update ')) {
                $updateQueries++;
            }
        });

        $this->assertSame('delivered', $order->effectiveFulfillmentStatus());
        $after = (array) DB::table('orders')->where('id', $order->id)->first($columns);

        $this->assertSame($before, $after);
        $this->assertSame(0, $updateQueries, 'Read-time normalization must not execute an UPDATE.');
        $this->assertSame($deliveryCount, OrderDelivery::where('order_id', $order->id)->count());
        $this->assertSame($historyCount, OrderFulfillmentHistory::where('order_id', $order->id)->count());
    }

    public function test_delivery_timestamps_and_tracking_completion_are_delivery_evidence(): void
    {
        foreach ([
            ['fulfillment_status' => 'reserved', 'status' => 'pending', 'delivered_at' => now()],
            ['fulfillment_status' => 'reserved', 'status' => 'pending', 'picked_up_at' => now()],
            ['fulfillment_status' => 'reserved', 'status' => 'pending', 'tracking_status' => 'picked_up'],
            ['fulfillment_status' => 'reserved', 'status' => 'delivered', 'tracking_status' => 'pending'],
        ] as $attributes) {
            $order = $this->unpersistedOrder($attributes);
            $order->setRelation('delivery', null);

            $this->assertSame('delivered', $order->effectiveFulfillmentStatus());
        }
    }

    public function test_order_delivery_completed_is_delivery_evidence(): void
    {
        $order = $this->unpersistedOrder(['fulfillment_status' => 'reserved']);
        $order->setRelation('delivery', new OrderDelivery(['status' => 'delivered']));

        $this->assertSame('delivered', $order->effectiveFulfillmentStatus());
    }

    public function test_tracking_and_delivery_progress_map_without_claiming_delivery(): void
    {
        $cases = [
            [['tracking_status' => 'processing'], 'preparing'],
            [['tracking_status' => 'ready_for_pickup'], 'ready'],
            [['tracking_status' => 'shipped'], 'ready'],
            [['status' => 'shipped'], 'ready'],
        ];

        foreach ($cases as [$attributes, $expected]) {
            $order = $this->unpersistedOrder(array_merge(['fulfillment_status' => null], $attributes));
            $order->setRelation('delivery', null);

            $this->assertSame($expected, $order->effectiveFulfillmentStatus());
            $this->assertNotSame('delivered', $order->effectiveFulfillmentStatus());
        }

        foreach (['dispatched', 'out_for_delivery', 'awaiting_pickup', 'failed_attempt', 'rescheduled'] as $deliveryStatus) {
            $order = $this->unpersistedOrder(['fulfillment_status' => null]);
            $order->setRelation('delivery', new OrderDelivery(['status' => $deliveryStatus]));

            $this->assertSame('ready', $order->effectiveFulfillmentStatus());
        }
    }

    public function test_all_operational_fields_absent_fall_back_to_reserved(): void
    {
        $order = $this->unpersistedOrder([
            'fulfillment_status' => null,
            'status' => null,
            'tracking_status' => null,
            'delivered_at' => null,
            'picked_up_at' => null,
        ]);
        $order->setRelation('delivery', null);

        $this->assertSame('reserved', $order->effectiveFulfillmentStatus());
    }

    public function test_refund_before_delivery_cancels_all_three_operational_fields(): void
    {
        $order = $this->order([
            'status' => 'confirmed',
            'tracking_status' => 'processing',
            'fulfillment_status' => 'preparing',
            'payment_status' => 'approved',
        ]);

        app(OrderStateService::class)->applyPaymentStatus($order, ['status' => 'refunded']);

        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertSame('canceled', $order->fresh()->status);
        $this->assertSame('canceled', $order->fresh()->tracking_status);
        $this->assertSame('canceled', $order->fresh()->fulfillment_status);
    }

    public function test_refund_with_delivered_tracking_is_rejected_without_mutating_any_order_fields(): void
    {
        $this->assertRefundRejectedWithoutMutation('delivered');
    }

    public function test_refund_with_picked_up_tracking_is_rejected_without_mutating_any_order_fields(): void
    {
        $this->assertRefundRejectedWithoutMutation('picked_up');
    }

    public function test_rejected_payment_does_not_cancel_operational_order(): void
    {
        $order = $this->order([
            'status' => 'pending',
            'tracking_status' => 'pending',
            'fulfillment_status' => 'reserved',
            'payment_status' => 'pending',
        ]);

        app(OrderStateService::class)->applyPaymentStatus($order, ['status' => 'rejected']);

        $this->assertSame('rejected', $order->fresh()->payment_status);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('pending', $order->fresh()->tracking_status);
        $this->assertSame('reserved', $order->fresh()->fulfillment_status);
        $this->assertSame('reserved', $order->fresh()->effectiveFulfillmentStatus());
    }

    public function test_admin_filter_matches_normalized_state_and_paginates_server_side(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $orders = [
            $this->order(['status' => 'pending', 'fulfillment_status' => 'reserved', 'tracking_status' => 'pending']),
            $this->order(['status' => 'confirmed', 'fulfillment_status' => 'future_state', 'tracking_status' => 'processing']),
            $this->order(['status' => 'shipped', 'fulfillment_status' => null, 'tracking_status' => 'shipped']),
            $this->order(['status' => 'canceled', 'fulfillment_status' => 'reserved', 'tracking_status' => 'canceled']),
            $this->order(['status' => 'canceled', 'fulfillment_status' => 'canceled', 'tracking_status' => 'canceled', 'delivered_at' => now()]),
        ];
        Sanctum::actingAs($admin);

        foreach (['reserved', 'preparing', 'ready', 'delivered', 'canceled'] as $state) {
            $response = $this->getJson('/api/v1/admin/orders?fulfillment_status='.$state.'&per_page=15')->assertOk();
            $row = $response->json('data.0');

            $this->assertSame(15, $response->json('meta.per_page'));
            $this->assertSame($state, $row['fulfillment_status'] ?? null);
            $this->assertContains($row['id'], array_map(fn (Order $order) => $order->id, $orders));
        }
    }

    public function test_admin_list_batches_delivery_state_loading_for_many_orders(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        foreach (range(1, 20) as $_) {
            $this->order(['status' => 'shipped', 'fulfillment_status' => null, 'tracking_status' => 'shipped']);
        }
        Sanctum::actingAs($admin);
        $deliveryQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$deliveryQueries): void {
            $sql = strtolower($query->sql);
            $normalizedSql = str_replace(['`', '"', '[', ']'], '', $sql);
            if (str_contains($normalizedSql, 'from order_deliveries')
                && str_contains($normalizedSql, 'order_deliveries.order_id in (')) {
                $deliveryQueries++;
            }
        });

        $this->getJson('/api/v1/admin/orders?fulfillment_status=ready&per_page=25')->assertOk();

        $this->assertSame(1, $deliveryQueries, 'Delivery state must be eager-loaded once for the page, never once per order.');
    }

    private function assertRefundRejectedWithoutMutation(string $trackingStatus): void
    {
        $order = $this->order([
            'status' => 'delivered',
            'tracking_status' => $trackingStatus,
            'fulfillment_status' => 'delivered',
            'payment_status' => 'approved',
            'payment_data' => ['marker' => 'must-remain'],
            'delivered_at' => now(),
        ]);
        $before = $this->orderStateSnapshot($order);
        $historyCount = OrderFulfillmentHistory::where('order_id', $order->id)->count();

        try {
            app(OrderStateService::class)->applyPaymentStatus($order, ['status' => 'refunded']);
            $this->fail('Refund notifications after delivery must remain rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame($before, $this->orderStateSnapshot($order));
        $this->assertSame($historyCount, OrderFulfillmentHistory::where('order_id', $order->id)->count());
    }

    private function unpersistedOrder(array $overrides = []): Order
    {
        $order = new Order(array_merge([
            'status' => 'pending',
            'fulfillment_status' => null,
            'tracking_status' => 'pending',
            'delivered_at' => null,
            'picked_up_at' => null,
        ], $overrides));
        $order->setRelation('delivery', null);

        return $order;
    }

    private function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'status' => 'pending',
            'total' => '10.00',
            'shipping_info' => ['address' => 'Test'],
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
            'fulfillment_status' => 'reserved',
        ], $overrides));
    }

    private function orderStateSnapshot(Order $order): array
    {
        $order->refresh();

        return [
            'status' => $order->getRawOriginal('status'),
            'fulfillment_status' => $order->getRawOriginal('fulfillment_status'),
            'tracking_status' => $order->getRawOriginal('tracking_status'),
            'payment_status' => $order->getRawOriginal('payment_status'),
            'delivered_at' => $order->getRawOriginal('delivered_at'),
            'updated_at' => $order->getRawOriginal('updated_at'),
        ];
    }
}
