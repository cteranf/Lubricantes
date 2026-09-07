<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementCatalogCsvExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_catalog_csv_has_bom_headers_and_no_files(): void
    {
        Product::create(['name' => 'Árbol', 'slug' => 'arbol-cat', 'sku' => 'CAT-CSV', 'price' => 10]);
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get('/api/v1/admin/reports/management/catalogs/export?catalog=products');
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertSame("\xEF\xBB\xBF", substr($content, 0, 3));
        $this->assertStringContainsString('Nombre', $content);
        $this->assertStringContainsString('Árbol', $content);
    }

    public function test_catalog_export_requires_admin(): void
    {
        $this->withHeaders(['Accept' => 'application/json'])->get('/api/v1/admin/reports/management/catalogs/export?catalog=products')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'customer']))->get('/api/v1/admin/reports/management/catalogs/export?catalog=products')->assertForbidden();
    }

    public function test_all_catalog_exports_have_catalog_specific_safe_filename_and_headers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $expected = [
            'products' => ['Nombre', 'SKU', 'Categoría', 'Marca', 'Precio', 'Activo'],
            'categories' => ['Nombre', 'Activo'], 'brands' => ['Nombre', 'Activo'],
            'warehouses' => ['Código', 'Nombre', 'Sede', 'Activo'], 'branches' => ['Código', 'Nombre', 'Distrito', 'UBIGEO', 'Permite pickup', 'Activo'],
            'drivers' => ['Código', 'Nombre', 'Activo', 'Disponible'], 'vehicles' => ['Código', 'Tipo', 'Descripción', 'Activo', 'Disponible'],
            'departments' => ['Código', 'Nombre', 'Activo'], 'provinces' => ['Código departamento', 'Departamento', 'Código', 'Nombre', 'Activo'],
            'districts' => ['Código departamento', 'Departamento', 'Código provincia', 'Provincia', 'Código', 'Nombre', 'UBIGEO', 'Activo'],
            'shipping_zones' => ['Código', 'Nombre', 'Días mínimos', 'Días máximos', 'Activo'],
            'shipping_rates' => ['Código zona', 'Zona', 'Distrito', 'UBIGEO', 'Monto', 'Días mínimos', 'Días máximos', 'Activo'],
        ];
        foreach ($expected as $catalog => $headers) {
            $response = $this->actingAs($admin)->get('/api/v1/admin/reports/management/catalogs/export?catalog='.$catalog);
            $response->assertOk()->assertHeader('content-disposition');
            $disposition = (string) $response->headers->get('content-disposition');
            $this->assertStringContainsString('reporte-'.$catalog.'-', $disposition);
            $this->assertStringEndsWith('.csv', $disposition);
            $content = $response->streamedContent();
            $lines = preg_split("/\r\n|\n|\r/", substr($content, 3));
            $this->assertSame($headers, str_getcsv($lines[0]));
        }
    }

    public function test_export_limit_rejects_before_streaming(): void
    {
        config(['management_reports.export_limit' => 0]);
        Product::create(['name' => 'Límite', 'slug' => 'limite-cat', 'sku' => 'LIM-CAT', 'price' => 1]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/api/v1/admin/reports/management/catalogs/export?catalog=products')->assertStatus(422);
    }
}
