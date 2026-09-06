<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderHandlingProcess;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementOperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function response(array $q = [])
    {
        return $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson('/api/v1/admin/dashboard?'.http_build_query($q));
    }

    private function process(string $kind, int $minutes, ?Carbon $completed = null, ?Carbon $started = null): void
    {
        $completed ??= Carbon::now('America/Lima')->startOfMonth()->addDays(2)->setTime(10, 0);
        $started ??= $completed->copy()->subMinutes($minutes);
        $order = Order::create(['status' => 'confirmed', 'total' => 10, 'shipping_info' => [], 'payment_status' => 'approved', 'created_at' => $started, 'updated_at' => $completed]);
        $data = ['order_id' => $order->id, $kind.'_status' => 'completed', $kind.'_started_at' => $started, $kind.'_completed_at' => $completed];
        OrderHandlingProcess::create($data);
    }

    private function seedSamples(): void
    {
        foreach ([20, 25, 45] as $m) {
            $this->process('picking', $m);
        }
        foreach ([10, 20, 30, 40] as $m) {
            $this->process('packing', $m);
        }
    }

    private function pickupCase(int $deadlineMinutes, bool $picked = false, bool $cancelled = false): int
    {
        $now = Carbon::now('America/Lima');
        $order = Order::create(['status' => $cancelled ? 'canceled' : ($picked ? 'picked_up' : 'confirmed'), 'total' => 10, 'shipping_info' => [], 'delivery_type' => 'pickup', 'ready_for_pickup_at' => $now->copy()->subHours(2), 'pickup_deadline_at' => $now->copy()->addMinutes($deadlineMinutes), 'picked_up_at' => $picked ? $now : null]);
        OrderDelivery::create(['order_id' => $order->id, 'method' => OrderDelivery::STORE_PICKUP, 'status' => $picked ? 'delivered' : ($cancelled ? 'canceled' : 'awaiting_pickup'), 'picked_up_at' => $picked ? $now : null]);

        return $order->id;
    }

    private function delivery(string $method, int $minutes, bool $pickup = false): void
    {
        $end = Carbon::now('America/Lima')->startOfMonth()->addDays(2)->setTime(12, 0);
        $order = Order::create(['status' => 'delivered', 'total' => 10, 'shipping_info' => [], 'ready_at' => $end->copy()->subMinutes($minutes), 'ready_for_pickup_at' => $pickup ? $end->copy()->subMinutes($minutes) : null]);
        OrderDelivery::create(['order_id' => $order->id, 'method' => $method, 'status' => $pickup ? 'picked_up' : 'delivered', 'started_at' => $end->copy()->subMinutes($minutes), 'assigned_at' => $end->copy()->subMinutes($minutes), 'dispatched_at' => $pickup ? null : $end->copy()->subMinutes(intdiv($minutes, 2)), 'delivered_at' => $pickup ? null : $end, 'picked_up_at' => $pickup ? $end : null]);
    }

    public function test_admin_can_read_operations_section(): void
    {
        $this->response(['section' => 'operations'])->assertOk()->assertJsonStructure(['operations' => ['current_backlog', 'period_flow', 'fulfillment_stages', 'delivery_methods', 'delivery_outcomes', 'aging_buckets', 'cycle_times', 'attention_queue', 'metadata']]);
    }

    public function test_customer_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))->getJson('/api/v1/admin/dashboard?section=operations')->assertForbidden();
    }

    public function test_invalid_section_is_rejected(): void
    {
        $this->response(['section' => 'unknown'])->assertStatus(422);
    }

    public function test_backlog_is_separate_from_period_flow(): void
    {
        $json = $this->response(['section' => 'operations'])->json('operations');
        $this->assertArrayHasKey('current_backlog', $json);
        $this->assertArrayHasKey('period_flow', $json);
    }

    public function test_empty_operations_have_stable_collections(): void
    {
        $json = $this->response(['section' => 'operations'])->json('operations');
        foreach (['delivery_methods', 'delivery_outcomes', 'aging_buckets', 'attention_queue'] as $k) {
            $this->assertIsArray($json[$k]);
        }
    }

    public function test_cycle_times_are_null_without_samples(): void
    {
        $this->assertNull($this->response(['section' => 'operations'])->json('operations.cycle_times.picking_duration.average_minutes'));
    }

    public function test_picking_sample_count(): void
    {
        $this->seedSamples();
        $this->assertSame(3, $this->response(['section' => 'operations'])->json('operations.cycle_times.picking_duration.sample_count'));
    }

    public function test_picking_average(): void
    {
        $this->seedSamples();
        $this->assertSame(30, $this->response(['section' => 'operations'])->json('operations.cycle_times.picking_duration.average_minutes'));
    }

    public function test_picking_median_odd(): void
    {
        $this->seedSamples();
        $this->assertSame(25, $this->response(['section' => 'operations'])->json('operations.cycle_times.picking_duration.median_minutes'));
    }

    public function test_picking_minimum(): void
    {
        $this->seedSamples();
        $this->assertSame(20, $this->response(['section' => 'operations'])->json('operations.cycle_times.picking_duration.minimum_minutes'));
    }

    public function test_picking_maximum(): void
    {
        $this->seedSamples();
        $this->assertSame(45, $this->response(['section' => 'operations'])->json('operations.cycle_times.picking_duration.maximum_minutes'));
    }

    public function test_packing_sample_count(): void
    {
        $this->seedSamples();
        $this->assertSame(4, $this->response(['section' => 'operations'])->json('operations.cycle_times.packing_duration.sample_count'));
    }

    public function test_packing_average(): void
    {
        $this->seedSamples();
        $this->assertSame(25, $this->response(['section' => 'operations'])->json('operations.cycle_times.packing_duration.average_minutes'));
    }

    public function test_packing_median_even(): void
    {
        $this->seedSamples();
        $this->assertSame(25, $this->response(['section' => 'operations'])->json('operations.cycle_times.packing_duration.median_minutes'));
    }

    public function test_packing_minimum(): void
    {
        $this->seedSamples();
        $this->assertSame(10, $this->response(['section' => 'operations'])->json('operations.cycle_times.packing_duration.minimum_minutes'));
    }

    public function test_packing_maximum(): void
    {
        $this->seedSamples();
        $this->assertSame(40, $this->response(['section' => 'operations'])->json('operations.cycle_times.packing_duration.maximum_minutes'));
    }

    public function test_incomplete_intervals_increase_missing_count(): void
    {
        $order = Order::create(['status' => 'confirmed', 'total' => 10, 'shipping_info' => []]);
        OrderHandlingProcess::create(['order_id' => $order->id, 'picking_status' => 'completed']);
        $this->assertGreaterThan(0, $this->response(['section' => 'operations'])->json('operations.cycle_times.picking_duration.missing_count'));
    }

    public function test_negative_interval_increases_invalid_count(): void
    {
        $c = Carbon::now('America/Lima')->startOfMonth()->addDays(2);
        $this->process('picking', 1, $c, $c->copy()->addMinute());
        $this->assertGreaterThan(0, $this->response(['section' => 'operations'])->json('operations.cycle_times.picking_duration.invalid_count'));
    }

    public function test_negative_interval_does_not_change_statistics(): void
    {
        $this->process('picking', 20);
        $c = Carbon::now('America/Lima')->startOfMonth()->addDays(2);
        $this->process('picking', 1, $c, $c->copy()->addMinute());
        $this->assertSame(1, $this->response(['section' => 'operations'])->json('operations.cycle_times.picking_duration.sample_count'));
    }

    public function test_completion_outside_period_is_excluded(): void
    {
        $c = Carbon::now('America/Lima')->startOfMonth()->subDay();
        $this->process('picking', 20, $c);
        $this->assertSame(0, $this->response(['section' => 'operations', 'period' => 'this_month'])->json('operations.cycle_times.picking_duration.sample_count'));
    }

    public function test_started_before_period_and_completed_inside_is_included(): void
    {
        $c = Carbon::now('America/Lima')->startOfMonth()->addDay();
        $this->process('picking', 20, $c, $c->copy()->subDay());
        $this->assertSame(1, $this->response(['section' => 'operations'])->json('operations.cycle_times.picking_duration.sample_count'));
    }

    public function test_cycle_values_are_finite(): void
    {
        $this->seedSamples();
        $json = json_encode($this->response(['section' => 'operations'])->json('operations.cycle_times'));
        $this->assertStringNotContainsString('NaN', $json);
        $this->assertStringNotContainsString('Infinity', $json);
    }

    public function test_cycle_source_is_correct(): void
    {
        $this->seedSamples();
        $this->assertSame('order_handling_processes', $this->response(['section' => 'operations'])->json('operations.cycle_times.picking_duration.source'));
    }

    public function test_cycle_response_has_no_personal_data(): void
    {
        $this->seedSamples();
        $body = json_encode($this->response(['section' => 'operations'])->json('operations.cycle_times'));
        $this->assertStringNotContainsString('email', $body);
        $this->assertStringNotContainsString('phone', $body);
    }

    public function test_delivery_and_pickup_cycles_are_separate(): void
    {
        $this->delivery(OrderDelivery::OWN_DELIVERY, 30);
        $this->delivery(OrderDelivery::STORE_PICKUP, 20, true);
        $json = $this->response(['section' => 'operations'])->json('operations.cycle_times');
        $this->assertSame(1, $json['ready_to_delivery']['sample_count']);
        $this->assertSame(1, $json['ready_to_pickup']['sample_count']);
    }

    public function test_assignment_to_dispatch_and_dispatch_to_delivery_use_real_timestamps(): void
    {
        $this->delivery(OrderDelivery::OWN_DELIVERY, 40);
        $json = $this->response(['section' => 'operations'])->json('operations.cycle_times');
        $this->assertSame(1, $json['assignment_to_dispatch']['sample_count']);
        $this->assertSame(1, $json['dispatch_to_delivery']['sample_count']);
    }

    public function test_delivery_missing_completion_is_reported(): void
    {
        $this->delivery(OrderDelivery::OWN_DELIVERY, 20);
        OrderDelivery::query()->update(['delivered_at' => null]);
        $this->assertGreaterThan(0, $this->response(['section' => 'operations'])->json('operations.cycle_times.dispatch_to_delivery.missing_count'));
    }

    public function test_pickup_waiting_reason(): void
    {
        $this->pickupCase(300);
        $this->assertSame('PICKUP_WAITING', $this->response(['section' => 'operations'])->json('operations.attention_queue.items.0.reason_code'));
    }

    public function test_pickup_expiring_reason_and_positive_remaining(): void
    {
        $this->pickupCase(30);
        $item = $this->response(['section' => 'operations'])->json('operations.attention_queue.items.0');
        $this->assertSame('PICKUP_EXPIRING', $item['reason_code']);
        $this->assertGreaterThan(0, $item['seconds_remaining']);
        $this->assertFalse($item['is_overdue']);
    }

    public function test_pickup_expired_reason_and_overdue(): void
    {
        $this->pickupCase(-30);
        $item = $this->response(['section' => 'operations'])->json('operations.attention_queue.items.0');
        $this->assertSame('PICKUP_EXPIRED', $item['reason_code']);
        $this->assertGreaterThan(0, $item['overdue_seconds']);
        $this->assertTrue($item['is_overdue']);
    }

    public function test_pickup_picked_up_and_cancelled_are_excluded(): void
    {
        $this->pickupCase(-30, true);
        $this->pickupCase(-30, false, true);
        $this->assertSame(0, $this->response(['section' => 'operations'])->json('operations.attention_queue.total'));
    }

    public function test_delivery_does_not_generate_pickup_reason(): void
    {
        $this->delivery(OrderDelivery::OWN_DELIVERY, 20);
        $codes = collect($this->response(['section' => 'operations'])->json('operations.attention_queue.items'))->pluck('reason_code');
        $this->assertFalse($codes->contains(fn ($code) => str_starts_with($code, 'PICKUP_')));
    }

    public function test_picking_stalled_reason_is_emitted(): void
    {
        $o = Order::create(['status' => 'confirmed', 'total' => 10, 'shipping_info' => [], 'fulfillment_status' => 'preparing']);
        OrderHandlingProcess::create(['order_id' => $o->id, 'picking_status' => 'in_progress', 'picking_started_at' => Carbon::now('America/Lima')->subHours(3)]);
        $this->assertTrue(collect($this->response(['section' => 'operations'])->json('operations.attention_queue.items'))->contains(fn ($i) => $i['reason_code'] === 'PICKING_STALLED'));
    }

    public function test_packing_not_started_requires_completed_picking(): void
    {
        $o = Order::create(['status' => 'confirmed', 'total' => 10, 'shipping_info' => []]);
        OrderHandlingProcess::create(['order_id' => $o->id, 'picking_status' => 'completed', 'picking_completed_at' => Carbon::now('America/Lima')->subHours(2), 'packing_status' => 'pending']);
        $this->assertTrue(collect($this->response(['section' => 'operations'])->json('operations.attention_queue.items'))->contains(fn ($i) => $i['reason_code'] === 'PACKING_NOT_STARTED'));
    }

    public function test_assigned_not_dispatched_reason_is_emitted(): void
    {
        $this->delivery(OrderDelivery::OWN_DELIVERY, 20);
        OrderDelivery::query()->update(['status' => OrderDelivery::ASSIGNED, 'assigned_at' => Carbon::now('America/Lima')->subHours(2), 'dispatched_at' => null]);
        $this->assertTrue(collect($this->response(['section' => 'operations'])->json('operations.attention_queue.items'))->contains(fn ($i) => $i['reason_code'] === 'ASSIGNED_NOT_DISPATCHED'));
    }

    public function test_operations_metadata_has_cutoff(): void
    {
        $this->response(['section' => 'operations'])->assertJsonStructure(['operations' => ['metadata' => ['as_of', 'period_start', 'period_end']]]);
    }

    public function test_operations_response_has_no_personal_data(): void
    {
        $body = json_encode($this->response(['section' => 'operations'])->json('operations'));
        $this->assertStringNotContainsString('email', $body);
        $this->assertStringNotContainsString('phone', $body);
    }

    public function test_operations_values_are_finite(): void
    {
        $body = json_encode($this->response(['section' => 'operations'])->json('operations'));
        $this->assertStringNotContainsString('NaN', $body);
        $this->assertStringNotContainsString('Infinity', $body);
    }

    public function test_default_endpoint_keeps_operations_contract(): void
    {
        $this->response()->assertOk()->assertJsonStructure(['operations']);
    }
}
