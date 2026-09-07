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

class ManagementInventoryReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function warehouse(string $code): Warehouse
    {
        $branch = Branch::create(['code' => 'B-'.$code, 'name' => 'Sede '.$code, 'address' => 'Dir']);

        return Warehouse::create(['branch_id' => $branch->id, 'code' => $code, 'name' => 'Almacén '.$code, 'is_active' => true]);
    }

    private function row(Warehouse $w, int $q, int $r, ?int $category = null, ?int $brand = null): Product
    {
        $p = Product::create(['name' => 'Producto '.$w->code.' '.$q.'-'.$r, 'slug' => strtolower($w->code.'-'.$q.'-'.$r), 'price' => 10, 'category_id' => $category, 'brand_id' => $brand]);
        WarehouseInventory::create(['warehouse_id' => $w->id, 'product_id' => $p->id, 'quantity' => $q, 'reserved_quantity' => $r]);

        return $p;
    }

    public function test_inventory_uses_warehouse_balances_and_signed_formulas(): void
    {
        $w1 = $this->warehouse('W1');
        $w2 = $this->warehouse('W2');
        $this->row($w1, 10, 2);
        $this->row($w1, 5, 5);
        $this->row($w2, 5, 8);
        $json = $this->actingAs($this->admin())->getJson('/api/v1/admin/reports/management/inventory?period=today')->assertOk()->json();
        $this->assertSame([10, 5, 5], array_column($json['rows'], 'physical'));
        $this->assertSame(20, $json['totals']['physical']);
        $this->assertSame(15, $json['totals']['reserved']);
        $this->assertSame(5, $json['totals']['raw_available']);
        $this->assertSame(8, $json['totals']['sellable_available']);
        $this->assertSame(3, $json['totals']['reservation_deficit']);
        $this->assertSame(1, $json['totals']['inconsistent_count']);
        $this->assertSame(2, $json['totals']['warehouse_count']);
        $this->assertSame(3, $json['totals']['product_count']);
        $this->assertTrue($json['metadata']['snapshot']);
        $this->assertSame('current_inventory', $json['metadata']['data_freshness']);
    }

    public function test_inventory_filters_and_period_do_not_change_snapshot(): void
    {
        $c = Category::create(['name' => 'Cat', 'slug' => 'cat']);
        $b = Brand::create(['name' => 'Brand', 'slug' => 'brand']);
        $w = $this->warehouse('WF');
        $this->row($w, 10, 2, $c->id, $b->id);
        $this->row($w, 3, 1);
        $admin = $this->admin();
        foreach (['warehouse_id='.$w->id => [2, 13], 'category_id='.$c->id => [1, 10], 'brand_id='.$b->id => [1, 10], 'warehouse_id='.$w->id.'&category_id='.$c->id.'&brand_id='.$b->id => [1, 10]] as $query => [$count, $physical]) {
            $r = $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/inventory?'.$query)->assertOk()->json();
            $this->assertSame($count, $r['metadata']['row_count']);
            $this->assertSame($physical, $r['totals']['physical']);
        }
        $a = $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/inventory?period=today')->json();
        $b2 = $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/inventory?period=previous_month&date_from=2020-01-01&date_to=2020-01-02')->json();
        $this->assertSame($a['totals'], $b2['totals']);
    }

    public function test_inventory_limit_contract_and_authorization(): void
    {
        config(['management_reports.query_limit' => 1]);
        $w = $this->warehouse('WL');
        $this->row($w, 1, 0);
        $this->row($w, 2, 0);
        $admin = $this->admin();
        $r = $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/inventory')->assertOk()->json();
        $this->assertSame(2, $r['metadata']['row_count']);
        $this->assertSame(1, $r['metadata']['returned_count']);
        $this->assertTrue($r['metadata']['truncated']);
        $this->assertSame(3, $r['totals']['physical']);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/reports/management/inventory')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'customer']))->getJson('/api/v1/admin/reports/management/inventory')->assertForbidden();
    }
}
