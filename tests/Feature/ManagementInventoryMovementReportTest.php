<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementInventoryMovementReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function movement(Warehouse $w, Product $p, string $type, int $qty, ?string $reason = 'Nota', ?int $before = null, ?int $after = null): InventoryMovement
    {
        return InventoryMovement::create(['warehouse_id' => $w->id, 'product_id' => $p->id, 'type' => $type, 'quantity' => $qty, 'quantity_before' => $before ?? 0, 'quantity_after' => $after ?? $qty, 'reason' => $reason, 'reference_type' => 'test', 'reference_id' => 'REF-'.$qty, 'created_at' => now()]);
    }

    /** @dataProvider movementTypes */
    public function test_each_real_type_has_explicit_direction_and_signed_quantity(string $type, string $direction, int $signed): void
    {
        $branch = Branch::create(['code' => 'BT'.$type, 'name' => 'Sede', 'address' => 'Dir']);
        $w = Warehouse::create(['branch_id' => $branch->id, 'code' => 'W'.$type, 'name' => 'W', 'is_active' => true]);
        $p = Product::create(['name' => 'P '.$type, 'slug' => 'p-'.$type, 'price' => 1]);
        $this->movement($w, $p, $type, 4);
        $row = $this->actingAs($this->admin())->getJson('/api/v1/admin/reports/management/inventory_movements?movement_type='.$type)->json();
        $this->assertSame($type, $row['rows'][0]['movement_type']);
        $this->assertSame($direction, $row['rows'][0]['movement_direction']);
        $this->assertSame(4, $row['rows'][0]['quantity']);
        $this->assertSame($signed * 4, $row['rows'][0]['signed_quantity']);
        $this->assertSame($direction === 'in' ? 4 : 0, $row['totals']['units_in']);
        $this->assertSame($direction === 'out' ? 4 : 0, $row['totals']['units_out']);
        $this->assertSame($signed * 4, $row['totals']['net_units']);
        $this->assertSame(1, $row['totals']['by_type'][0]['movements']);
    }

    public static function movementTypes(): array
    {
        return [[InventoryMovement::INITIAL, 'in', 1], [InventoryMovement::MANUAL_IN, 'in', 1], [InventoryMovement::MANUAL_OUT, 'out', -1], [InventoryMovement::TRANSFER_IN, 'in', 1], [InventoryMovement::TRANSFER_OUT, 'out', -1], [InventoryMovement::SALE, 'out', -1], [InventoryMovement::CANCELLATION_RETURN, 'in', 1]];
    }

    public function test_movements_report_types_totals_and_unknown_are_safe(): void
    {
        $branch = Branch::create(['code' => 'BM', 'name' => 'Sede', 'address' => 'Dir']);
        $w = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WM', 'name' => 'Almacén', 'is_active' => true]);
        $p = Product::create(['name' => 'Producto', 'slug' => 'movement-product', 'price' => 1]);
        $this->movement($w, $p, InventoryMovement::INITIAL, 10);
        $this->movement($w, $p, InventoryMovement::SALE, 3);
        $this->movement($w, $p, InventoryMovement::CORRECTION, 2, 'Nota', 2, 2);
        $this->movement($w, $p, 'future_type', 4);
        $r = $this->actingAs($this->admin())->getJson('/api/v1/admin/reports/management/inventory_movements')->assertOk()->json();
        $this->assertSame(4, $r['totals']['movements']);
        $this->assertSame(10, $r['totals']['units_in']);
        $this->assertSame(3, $r['totals']['units_out']);
        $this->assertSame(7, $r['totals']['net_units']);
        $this->assertSame(1, $r['totals']['warehouses']);
        $this->assertSame(1, $r['totals']['products']);
        $this->assertContains(['type' => 'unknown', 'movements' => 1, 'units' => 4], $r['totals']['by_type']);
        $this->assertSame('unknown', collect($r['rows'])->firstWhere('movement_type', 'unknown')['movement_type']);
    }

    public function test_movement_filters_limits_and_authorization(): void
    {
        $branch = Branch::create(['code' => 'BFM', 'name' => 'Sede', 'address' => 'Dir']);
        $w = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WFM', 'name' => 'W', 'is_active' => true]);
        $p = Product::create(['name' => 'P', 'slug' => 'movement-filter', 'price' => 1]);
        $this->movement($w, $p, InventoryMovement::INITIAL, 2);
        $this->movement($w, $p, InventoryMovement::SALE, 1);
        config(['management_reports.query_limit' => 1]);
        $r = $this->actingAs($this->admin())->getJson('/api/v1/admin/reports/management/inventory_movements?warehouse_id='.$w->id.'&movement_direction=out')->assertOk()->json();
        $this->assertSame(1, $r['metadata']['row_count']);
        $this->assertSame('out', $r['rows'][0]['movement_direction']);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/reports/management/inventory_movements')->assertUnauthorized();
    }

    public function test_correction_direction_and_signed_quantity_follow_balance_delta(): void
    {
        $branch = Branch::create(['code' => 'BCD', 'name' => 'Sede', 'address' => 'Dir']);
        $w = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WCD', 'name' => 'W', 'is_active' => true]);
        $p = Product::create(['name' => 'P', 'slug' => 'movement-correction', 'price' => 1]);
        $this->movement($w, $p, InventoryMovement::CORRECTION, 5, 'up', 10, 15);
        $this->movement($w, $p, InventoryMovement::CORRECTION, 5, 'down', 15, 10);
        $this->movement($w, $p, InventoryMovement::CORRECTION, 0, 'same', 10, 10);
        $rows = $this->actingAs($this->admin())->getJson('/api/v1/admin/reports/management/inventory_movements')->json('rows');
        $this->assertSame([0, -5, 5], array_column($rows, 'signed_quantity'));
        $this->assertSame(['neutral', 'out', 'in'], array_column($rows, 'movement_direction'));
    }

    public function test_unknown_type_is_neutral_and_correction_uses_real_delta(): void
    {
        $branch = Branch::create(['code' => 'BU', 'name' => 'Sede', 'address' => 'Dir']);
        $w = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WU', 'name' => 'W', 'is_active' => true]);
        $p = Product::create(['name' => 'P', 'slug' => 'unknown-movement', 'price' => 1]);
        $this->movement($w, $p, 'unknown_type', 9, 'sensible', 20, 29);
        $this->movement($w, $p, InventoryMovement::CORRECTION, 99, 'delta', 10, 15);
        $r = $this->actingAs($this->admin())->getJson('/api/v1/admin/reports/management/inventory_movements')->json();
        $unknown = collect($r['rows'])->firstWhere('movement_type', 'unknown');
        $this->assertSame('neutral', $unknown['movement_direction']);
        $this->assertSame(0, $unknown['signed_quantity']);
        $this->assertSame(5, collect($r['rows'])->firstWhere('movement_type', 'correction')['signed_quantity']);
        $this->assertSame(5, $r['totals']['units_in']);
    }
}
