<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementCatalogReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_catalog_allowlisted_returns_explicit_rows(): void
    {
        $cat = Category::create(['name' => 'Cat', 'slug' => 'cat', 'is_active' => true]);
        $brand = Brand::create(['name' => 'Marca', 'slug' => 'marca', 'is_active' => true]);
        Product::create(['name' => 'Producto', 'slug' => 'producto-catalog', 'sku' => 'CAT-1', 'price' => 10, 'category_id' => $cat->id, 'brand_id' => $brand->id, 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['products', 'categories', 'brands', 'warehouses', 'branches', 'drivers', 'vehicles', 'departments', 'provinces', 'districts', 'shipping_zones', 'shipping_rates'] as $catalog) {
            $r = $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/catalogs?catalog='.$catalog);
            $r->assertOk()->assertJsonPath('metadata.catalog', $catalog)->assertJsonStructure(['rows', 'totals' => ['records', 'active', 'inactive']]);
        }
    }

    public function test_catalog_is_required_and_invalid_values_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['/api/v1/admin/reports/management/catalogs', '/api/v1/admin/reports/management/catalogs?catalog=', '/api/v1/admin/reports/management/catalogs?catalog=users', '/api/v1/admin/reports/management/catalogs?catalog[]=products'] as $url) {
            $this->actingAs($admin)->getJson($url)->assertStatus(422);
        }
    }

    public function test_catalog_rows_have_explicit_public_shape_and_filters_are_scoped(): void
    {
        $cat = Category::create(['name' => 'Filtro único', 'slug' => 'filtro-unico', 'is_active' => true]);
        $brand = Brand::create(['name' => 'Marca filtro', 'slug' => 'marca-filtro', 'is_active' => true]);
        Product::create(['name' => 'Visible', 'slug' => 'visible-cat', 'sku' => 'VISIBLE-CAT', 'price' => 11, 'category_id' => $cat->id, 'brand_id' => $brand->id, 'is_active' => true]);
        Product::create(['name' => 'Oculto', 'slug' => 'oculto-cat', 'sku' => 'OCULTO-CAT', 'price' => 12, 'is_active' => false]);
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/catalogs?catalog=products&category_id='.$cat->id.'&brand_id='.$brand->id.'&search=VISIBLE');
        $response->assertOk()->assertJsonPath('metadata.returned_count', 1);
        $this->assertSame(['name', 'sku', 'category_name', 'brand_name', 'price', 'is_active'], array_keys($response->json('rows.0')));
        $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/catalogs?catalog=categories&category_id='.$cat->id)->assertStatus(422);
        $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/catalogs?catalog=products&is_active=not-bool')->assertStatus(422);
    }

    public function test_hostile_catalog_names_are_rejected_and_never_query_models(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['users', ' products ', '../products', 'products.php', "products' OR 1=1 --", 'PRODUCTS', 'products[]', ''] as $catalog) {
            $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/catalogs?catalog='.urlencode($catalog))->assertStatus(422);
            $this->actingAs($admin)->withHeaders(['Accept' => 'application/json'])->get('/api/v1/admin/reports/management/catalogs/export?catalog='.urlencode($catalog))->assertStatus(422);
        }
    }

    public function test_catalog_response_and_export_do_not_expose_sensitive_fixture_values(): void
    {
        $product = Product::create(['name' => 'Producto público', 'slug' => 'privacidad-cat', 'sku' => 'PRIV-CAT', 'price' => 10]);
        $admin = User::factory()->create(['role' => 'admin']);
        $json = $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/catalogs?catalog=products')->assertOk()->getContent();
        $csv = $this->actingAs($admin)->get('/api/v1/admin/reports/management/catalogs/export?catalog=products')->streamedContent();
        foreach (['cliente-secreto@example.test', '999999999', 'DNI-PRIVADO', 'dirección-secreta', 'placa-secreta'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json.$csv);
        }
        foreach (['email', 'phone', 'document_number', 'address', 'plate_number'] as $forbiddenKey) {
            $this->assertStringNotContainsString($forbiddenKey, $json.$csv);
        }
        $this->assertStringContainsString((string) $product->name, $json.$csv);
    }
}
