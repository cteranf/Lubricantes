<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementInventoryMovementCsvExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_movement_csv_exports_exact_headers_and_neutralizes_notes(): void
    {
        $branch = Branch::create(['code' => 'BCM', 'name' => 'Sede', 'address' => 'Dir']);
        $w = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WCM', 'name' => 'W', 'is_active' => true]);
        $p = Product::create(['name' => 'P', 'slug' => 'movement-csv', 'price' => 1]);
        InventoryMovement::create(['warehouse_id' => $w->id, 'product_id' => $p->id, 'type' => InventoryMovement::MANUAL_IN, 'quantity' => 2, 'quantity_before' => 0, 'quantity_after' => 2, 'reason' => '=nota peligrosa', 'reference_type' => 'order', 'reference_id' => '42', 'created_at' => now()]);
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/api/v1/admin/reports/management/inventory_movements/export');
        $content = $response->streamedContent();
        $this->assertSame("\xEF\xBB\xBF", substr($content, 0, 3));
        $header = str_getcsv(strtok(substr($content, 3), "\n"));
        $this->assertSame(['Fecha', 'Almacén', 'Producto', 'SKU', 'Categoría', 'Marca', 'Tipo de movimiento', 'Dirección', 'Cantidad', 'Cantidad con signo', 'Tipo de referencia', 'Referencia', 'Observación'], $header);
        $this->assertStringContainsString("'=nota peligrosa", $content);
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }
}
