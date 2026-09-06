<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementInventoryDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function dashboardResponse(array $q = [])
    {
        return $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson('/api/v1/admin/dashboard?'.http_build_query($q));
    }

    public function test_admin_receives_inventory_snapshot(): void
    {
        $this->dashboardResponse()->assertOk()->assertJsonStructure(['inventory' => ['physical', 'reserved', 'available', 'reservation_deficit', 'inconsistent_count', 'by_warehouse', 'highest_outflow', 'movement_types', 'reservation_statuses', 'metadata']]);
    }

    public function test_inventory_table_is_present_for_operational_rows(): void
    {
        $this->assertIsArray($this->dashboardResponse()->assertOk()->json('inventory.table'));
    }

    public function test_customer_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_empty_inventory_has_zero_snapshot(): void
    {
        $this->dashboardResponse()->assertJsonPath('inventory.physical', 0)->assertJsonPath('inventory.available', 0);
    }

    public function test_inventory_metadata_exposes_period_and_as_of(): void
    {
        $this->dashboardResponse(['period' => 'this_month'])->assertJsonStructure(['inventory' => ['metadata' => ['as_of', 'period_start', 'period_end']]]);
    }

    public function test_warehouse_filter_is_validated(): void
    {
        $this->dashboardResponse(['warehouse_id' => 999999])->assertStatus(422);
    }

    public function test_commercial_filter_does_not_break_inventory_contract(): void
    {
        $this->dashboardResponse(['delivery_type' => 'pickup'])->assertOk()->assertJsonStructure(['inventory' => ['physical', 'reserved', 'available']]);
    }

    public function test_inventory_collections_are_arrays_when_empty(): void
    {
        $json = $this->dashboardResponse()->json('inventory');
        foreach (['by_warehouse', 'highest_outflow', 'movement_types', 'reservation_statuses', 'no_movement_products'] as $k) {
            $this->assertIsArray($json[$k]);
        }
    }

    public function test_available_never_returns_nan_or_infinity(): void
    {
        $body = json_encode($this->dashboardResponse()->json('inventory'));
        $this->assertStringNotContainsString('NaN', $body);
        $this->assertStringNotContainsString('Infinity', $body);
    }

    private function inventoryRow(int $physical, int $reserved): array
    {
        $branch = Branch::create(['code' => 'BR-'.$physical.'-'.$reserved, 'name' => 'Sede prueba', 'address' => 'Dirección', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-'.$physical.'-'.$reserved, 'name' => 'Almacén prueba', 'is_active' => true]);
        $category = Category::create(['name' => 'Cat '.$physical.$reserved, 'slug' => 'cat-'.$physical.$reserved, 'is_active' => true]);
        $brand = Brand::create(['name' => 'Brand '.$physical.$reserved, 'slug' => 'brand-'.$physical.$reserved, 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'brand_id' => $brand->id, 'name' => 'Producto inventario', 'slug' => 'prod-'.$physical.$reserved, 'sku' => 'SKU-'.$physical.$reserved, 'price' => 10, 'is_active' => true]);
        WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => $physical, 'reserved_quantity' => $reserved]);

        return ['warehouse' => $warehouse, 'product' => $product];
    }

    public function test_normal_inventory_availability_formula(): void
    {
        $this->inventoryRow(10, 2);
        $row = $this->dashboardResponse()->assertOk()->json('inventory.table.0');
        $this->assertSame(8, $row['raw_available']);
        $this->assertSame(8, $row['sellable_available']);
        $this->assertSame(0, $row['reservation_deficit']);
        $this->assertFalse((bool) $row['has_inconsistency']);
    }

    public function test_equal_inventory_availability_formula(): void
    {
        $this->inventoryRow(5, 5);
        $row = $this->dashboardResponse()->assertOk()->json('inventory.table.0');
        $this->assertSame(0, $row['raw_available']);
        $this->assertSame(0, $row['sellable_available']);
        $this->assertSame(0, $row['reservation_deficit']);
    }

    public function test_overreserved_inventory_exposes_negative_raw_and_deficit(): void
    {
        $this->inventoryRow(5, 8);
        $row = $this->dashboardResponse()->assertOk()->json('inventory.table.0');
        $this->assertSame(-3, $row['raw_available']);
        $this->assertSame(0, $row['sellable_available']);
        $this->assertSame(3, $row['reservation_deficit']);
        $this->assertTrue((bool) $row['has_inconsistency']);
        $this->assertSame(3, $this->dashboardResponse()->json('inventory.reservation_deficit'));
        $this->assertSame(1, $this->dashboardResponse()->json('inventory.inconsistent_count'));
    }
}
