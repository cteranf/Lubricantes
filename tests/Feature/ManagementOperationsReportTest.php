<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ManagementOperationsReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_report_contract_is_read_only_and_filtered(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Order::create(['status' => 'pending', 'total' => 1, 'subtotal' => 1, 'shipping_info' => [], 'payment_status' => 'pending', 'delivery_type' => 'delivery', 'created_at' => now()->subHours(5)]);
        $before = Order::count();
        $r = $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/operations?severity=medium')->assertOk()->json();
        $this->assertArrayHasKey('totals', $r);
        $this->assertArrayHasKey('rows', $r);
        $this->assertSame($before, Order::count());
    }

    public function test_cycle_times_report_exposes_certified_metrics(): void
    {
        $r = $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson('/api/v1/admin/reports/management/cycle_times')->assertOk()->json();
        $this->assertArrayHasKey('metrics', $r);
        $this->assertContains('picking_duration', array_column($r['metrics'], 'key'));
        $this->assertContains('packing_duration', array_column($r['metrics'], 'key'));
    }

    public function test_operations_and_cycle_reports_are_faithful_adapters_of_dashboard(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-06 12:00:00', 'America/Lima'));
        $admin = User::factory()->create(['role' => 'admin']);
        Order::create(['status' => 'pending', 'total' => 1, 'subtotal' => 1, 'shipping_info' => [], 'payment_status' => 'pending', 'delivery_type' => 'delivery', 'created_at' => now()->subHours(5)]);
        $dashboard = $this->actingAs($admin)->getJson('/api/v1/admin/dashboard')->json();
        $operations = $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/operations')->json();
        $queue = collect($dashboard['operations']['attention_queue']['items']);
        foreach ($operations['rows'] as $row) {
            $source = $queue->firstWhere('reason_code', $row['reason_code']);
            $this->assertNotNull($source);
            $this->assertSame($source['severity'], $row['severity']);
            $this->assertSame($source['stage'], $row['stage']);
            $this->assertSame($source['stage_started_at'], $row['relevant_at']);
            $this->assertSame($source['deadline_at'] ?? null, $row['deadline_at']);
        }
        $cycles = $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/cycle_times')->json();
        $this->assertSame($dashboard['operations']['cycle_times'], collect($cycles['metrics'])->keyBy('key')->all());
        Carbon::setTestNow();
    }

    public function test_operations_totals_filters_and_sql_limit_are_exact(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-06 12:00:00', 'America/Lima'));
        $admin = User::factory()->create(['role' => 'admin']);
        $old = Order::create(['status' => 'pending', 'fulfillment_status' => 'preparing', 'total' => 1, 'subtotal' => 1, 'shipping_info' => [], 'payment_status' => 'pending', 'delivery_type' => 'delivery']);
        $old->created_at = now()->subHours(5);
        $old->saveQuietly();
        Order::create(['status' => 'confirmed', 'total' => 1, 'subtotal' => 1, 'shipping_info' => [], 'payment_status' => 'approved', 'delivery_type' => 'pickup', 'ready_for_pickup_at' => now()->subHours(4), 'pickup_deadline_at' => now()->subHour()]);
        config(['management_reports.query_limit' => 2]);
        $r = $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/operations?severity=')->assertOk()->json();
        $this->assertSame(3, $r['totals']['alerts']);
        $this->assertSame(2, $r['totals']['affected_orders']);
        $this->assertSame(1, $r['totals']['critical']);
        $this->assertSame(1, $r['totals']['high']);
        $this->assertSame(1, $r['totals']['medium']);
        $this->assertSame(0, $r['totals']['low']);
        $this->assertSame(1, $r['totals']['overdue']);
        $this->assertSame(3, $r['metadata']['row_count']);
        $this->assertSame(2, $r['metadata']['returned_count']);
        $this->assertTrue($r['metadata']['truncated']);
        $this->assertTrue($r['totals']['has_more']);
        $this->assertSame('critical', $r['rows'][0]['severity']);
        foreach (['severity=critical', 'reason_code=PICKUP_EXPIRED', 'stage=pickup', 'delivery_type=pickup', 'overdue=1', 'severity=critical&reason_code=PICKUP_EXPIRED&stage=pickup&delivery_type=pickup&overdue=1'] as $filter) {
            $x = $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/operations?'.$filter)->assertOk()->json();
            $this->assertSame(1, $x['metadata']['row_count'], $filter);
            $this->assertSame(1, $x['totals']['alerts'], $filter);
            $this->assertSame(1, $x['totals']['affected_orders'], $filter);
        }
        foreach (['severity=bogus', 'reason_code=bogus', 'stage=bogus', 'overdue=bogus'] as $invalid) {
            $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/operations?'.$invalid)->assertStatus(422);
        }
        Carbon::setTestNow();
    }
}
