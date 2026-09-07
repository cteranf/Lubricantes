<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementInventoryCsvExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_csv_headers_bom_and_signed_negative_value(): void
    {
        $branch = Branch::create(['code' => 'BC', 'name' => 'Sede', 'address' => 'Dirección']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WC', 'name' => '=Almacén', 'is_active' => true]);
        $product = Product::create(['name' => '+Producto', 'slug' => 'csv-inv', 'sku' => '@SKU', 'price' => 1]);
        WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 5, 'reserved_quantity' => 8]);
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/api/v1/admin/reports/management/inventory/export');
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment', strtolower((string) $response->headers->get('content-disposition')));
        $content = $response->streamedContent();
        $this->assertSame("\xEF\xBB\xBF", substr($content, 0, 3));
        $lines = preg_split('/\r?\n/', trim(substr($content, 3)));
        $header = str_getcsv($lines[0]);
        $this->assertSame(['Almacén', 'Producto', 'SKU', 'Categoría', 'Marca', 'Stock físico', 'Stock reservado', 'Disponibilidad matemática', 'Disponibilidad vendible', 'Déficit de reserva', 'Inconsistencia', 'Fecha de corte'], $header);
        $this->assertStringContainsString("'=Almacén", $content);
        $this->assertStringContainsString("'+Producto", $content);
        $this->assertStringContainsString('SKU', $content);
        $this->assertStringContainsString('-3', $content);
        $this->assertStringNotContainsString("'-3", $content);
    }

    public function test_inventory_csv_filters_and_limit_policy(): void
    {
        $branch = Branch::create(['code' => 'BF', 'name' => 'Sede', 'address' => 'Dir']);
        $w = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WF', 'name' => 'W', 'is_active' => true]);
        $p = Product::create(['name' => 'P', 'slug' => 'p-filter', 'price' => 1]);
        WarehouseInventory::create(['warehouse_id' => $w->id, 'product_id' => $p->id, 'quantity' => 1, 'reserved_quantity' => 0]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/api/v1/admin/reports/management/inventory/export?warehouse_id='.$w->id)->assertOk();
        config(['management_reports.export_limit' => 0]);
        $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/inventory/export')->assertStatus(422);
    }
}
