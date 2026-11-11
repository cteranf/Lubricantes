<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminProductListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_receives_only_the_explicit_product_projection_with_physical_stock(): void
    {
        Sanctum::actingAs($this->admin());
        $category = Category::create(['name' => 'Sintética', 'slug' => 'sintetica-listing']);
        $brand = Brand::create(['name' => 'Marca lista', 'slug' => 'marca-lista']);
        $product = $this->product(['category_id' => $category->id, 'brand_id' => $brand->id, 'stock' => 99, 'description' => 'Descripción sintética del producto.']);
        $productWithoutDescription = $this->product(['description' => null]);
        $warehouse = Warehouse::where('code', Warehouse::INITIAL_CODE)->firstOrFail();
        WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 8]);

        $response = $this->getJson('/api/v1/admin/products')->assertOk()
            ->assertJsonPath('data.1.id', $product->id)
            ->assertJsonPath('data.1.category', ['id' => $category->id, 'name' => 'Sintética'])
            ->assertJsonPath('data.1.brand', ['id' => $brand->id, 'name' => 'Marca lista'])
            ->assertJsonPath('data.1.physical_stock', 8);

        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertSame(['id', 'name', 'sku', 'description', 'price', 'is_active', 'category', 'brand', 'physical_stock', 'image_url'], array_keys($rows[$product->id]));
        $this->assertSame('Descripción sintética del producto.', $rows[$product->id]['description']);
        $this->assertNull($rows[$productWithoutDescription->id]['description']);
        $this->assertArrayNotHasKey('slug', $rows[$product->id]);
        $this->assertArrayNotHasKey('image_path', $rows[$product->id]);
        $this->assertArrayNotHasKey('stock', $rows[$product->id]);
    }

    public function test_global_and_specific_filters_are_combined_and_percent_is_literal(): void
    {
        Sanctum::actingAs($this->admin());
        $category = Category::create(['name' => 'Filtros', 'slug' => 'filtros-product-list']);
        $brand = Brand::create(['name' => 'Marca filtro', 'slug' => 'marca-filtro-product-list']);
        $wanted = $this->product(['name' => 'Aceite 100% sintético', 'sku' => 'ACEITE-PCT', 'price' => '12.50', 'category_id' => $category->id, 'brand_id' => $brand->id, 'is_active' => true]);
        $this->product(['name' => 'Aceite 100X sintético', 'sku' => 'ACEITE-X', 'price' => '12.50', 'category_id' => $category->id, 'brand_id' => $brand->id, 'is_active' => true]);
        $this->product(['name' => 'Aceite 100% sintético', 'sku' => 'ACEITE-INACTIVO', 'price' => '12.50', 'category_id' => $category->id, 'brand_id' => $brand->id, 'is_active' => false]);

        $this->getJson('/api/v1/admin/products?search=%25&category_id='.$category->id.'&brand_id='.$brand->id.'&is_active=1&price_min=12.50&price_max=12.50&sort=name&direction=asc')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $wanted->id);
    }

    public function test_name_and_sku_filters_are_literal_and_order_is_stable(): void
    {
        Sanctum::actingAs($this->admin());
        $first = $this->product(['name' => 'Aceite _ especial', 'sku' => 'SPECIAL_1', 'price' => '25.00']);
        $second = $this->product(['name' => 'Aceite _ especial', 'sku' => 'SPECIAL_2', 'price' => '25.00']);
        $this->product(['name' => 'Aceite X especial', 'sku' => 'SPECIALX3', 'price' => '25.00']);

        $response = $this->getJson('/api/v1/admin/products?name=%5F&sku=SPECIAL%5F&sort=price&direction=asc&per_page=25')->assertOk();
        $this->assertSame([$first->id, $second->id], array_column($response->json('data'), 'id'));
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(25, $response->json('meta.per_page'));
    }

    public function test_pagination_uses_allowed_sizes_and_stable_tie_breaking(): void
    {
        Sanctum::actingAs($this->admin());
        $products = [];
        for ($index = 0; $index < 17; $index++) {
            $products[] = $this->product(['name' => 'Producto ordenable', 'price' => '10.00']);
        }

        $response = $this->getJson('/api/v1/admin/products?per_page=15&sort=name&direction=asc')->assertOk();
        $this->assertSame(17, $response->json('meta.total'));
        $this->assertSame(15, $response->json('meta.per_page'));
        $this->assertSame(array_slice(array_map(fn (Product $product) => $product->id, $products), 0, 15), array_column($response->json('data'), 'id'));
        $this->getJson('/api/v1/admin/products?page=2&per_page=15&sort=name&direction=asc')->assertOk()->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.total', 17);
    }

    /** @dataProvider invalidProductQueryStrings */
    public function test_invalid_query_names_duplicates_arrays_and_values_are_rejected(string $query, int $expectedStatus): void
    {
        Sanctum::actingAs($this->admin());
        $response = $this->getJson('/api/v1/admin/products'.$query);
        $this->assertSame($expectedStatus, $response->status(), 'Product query validation case returned an unexpected status.');
    }

    public static function invalidProductQueryStrings(): array
    {
        return [
            'unknown name' => ['?unknown=1', 422],
            'literal dot name' => ['?search.foo=x', 422],
            'encoded dot name' => ['?search%2Efoo=x', 422],
            'encoded space name' => ['?search%20=x', 422],
            'plus sign in name' => ['?%2Bsearch=x', 422],
            'bracket array name' => ['?sku%5B%5D=A', 422],
            'nested object name' => ['?search%5Bterm%5D=x', 422],
            'empty name' => ['?=1', 422],
            'direct duplicate name' => ['?search=x&search=y', 422],
            'encoded equivalent duplicate name' => ['?search=x&%73earch=y', 422],
            'malformed percent encoding' => ['?search=%ZZ', 422],
            'page zero' => ['?page=0', 422],
            'page leading zero' => ['?page=01', 422],
            'page array' => ['?page%5B%5D=1', 422],
            'page integer overflow' => ['?page=999999999999999999999999999', 422],
            'page size unsupported' => ['?per_page=16', 422],
            'page size empty' => ['?per_page=', 422],
            'sort alias' => ['?sort=category.name', 422],
            'direction noncanonical' => ['?direction=ASC', 422],
            'search empty' => ['?search=', 422],
            'search whitespace only' => ['?search=%20%20%20', 422],
            'search tab and newline' => ['?search=%09%0A', 422],
            'search object value' => ['?search%5Bterm%5D=x', 422],
            'sku empty' => ['?sku=', 422],
            'sku whitespace only' => ['?sku=%20', 422],
            'sku array' => ['?sku%5B0%5D=A', 422],
            'name empty' => ['?name=', 422],
            'name whitespace only' => ['?name=%20%20%20', 422],
            'name too long' => ['?name='.str_repeat('a', 121), 422],
            'category zero' => ['?category_id=0', 422],
            'category leading zero' => ['?category_id=01', 422],
            'category array' => ['?category_id%5B%5D=1', 422],
            'category integer overflow' => ['?category_id=999999999999999999999999999', 422],
            'brand zero' => ['?brand_id=0', 422],
            'brand leading zero' => ['?brand_id=01', 422],
            'brand array' => ['?brand_id%5B%5D=1', 422],
            'activity boolean spelling' => ['?is_active=true', 422],
            'activity numeric alias' => ['?is_active=2', 422],
            'activity array' => ['?is_active%5B%5D=1', 422],
            'minimum negative price' => ['?price_min=-1', 422],
            'minimum leading zero price' => ['?price_min=01', 422],
            'minimum excessive precision' => ['?price_min=1.001', 422],
            'minimum out of product range' => ['?price_min=100000000', 422],
            'minimum price array' => ['?price_min%5B%5D=1', 422],
            'maximum negative price' => ['?price_max=-1', 422],
            'maximum leading zero price' => ['?price_max=01', 422],
            'maximum excessive precision' => ['?price_max=1.001', 422],
            'maximum out of product range' => ['?price_max=100000000', 422],
            'maximum price array' => ['?price_max%5B%5D=1', 422],
            'inverted price range' => ['?price_min=2&price_max=1', 422],
            'absent text filter allowed' => ['', 200],
            'valid text filter allowed' => ['?search=%20motor%20', 200],
        ];
    }

    public function test_customer_cannot_read_the_admin_product_listing(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'customer']));
        $this->getJson('/api/v1/admin/products')->assertForbidden();
    }

    public function test_listing_query_count_does_not_grow_per_product(): void
    {
        Sanctum::actingAs($this->admin());
        $category = Category::create(['name' => 'Categoría común', 'slug' => 'categoria-comun-list']);
        $brand = Brand::create(['name' => 'Marca común', 'slug' => 'marca-comun-list']);
        $this->product(['category_id' => $category->id, 'brand_id' => $brand->id]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $normalized = strtolower(ltrim(str_replace(['`', '"', '[', ']'], '', $query->sql)));
            $queries[] = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        });

        $start = count($queries);
        $this->getJson('/api/v1/admin/products')->assertOk();
        $firstRequestQueries = array_slice($queries, $start);

        for ($index = 0; $index < 9; $index++) {
            $this->product(['category_id' => $category->id, 'brand_id' => $brand->id]);
        }

        $start = count($queries);
        $this->getJson('/api/v1/admin/products')->assertOk();
        $secondRequestQueries = array_slice($queries, $start);

        $summarize = static function (array $statements): array {
            $purposes = [];
            foreach ($statements as $sql) {
                if (! str_starts_with($sql, 'select')) {
                    continue;
                }
                if (str_contains($sql, ' from products')) {
                    $purpose = str_contains($sql, 'count(') ? 'products_count' : 'products_rows';
                } elseif (str_contains($sql, ' from categories')) {
                    $purpose = 'category_relation';
                } elseif (str_contains($sql, ' from brands')) {
                    $purpose = 'brand_relation';
                } else {
                    $purpose = 'other_select';
                }
                $purposes[$purpose] = ($purposes[$purpose] ?? 0) + 1;
            }
            ksort($purposes);

            return $purposes;
        };

        $firstPurposes = $summarize($firstRequestQueries);
        $secondPurposes = $summarize($secondRequestQueries);
        $stockAggregateCount = static fn (array $statements): int => count(array_filter($statements, fn (string $sql): bool => str_contains($sql, ' from products') && str_contains($sql, 'sum(')));
        $this->assertSame(4, array_sum($firstPurposes), 'Unexpected relevant SELECT count in the first request: '.json_encode($firstPurposes));
        $this->assertSame(4, array_sum($secondPurposes), 'Unexpected relevant SELECT count in the second request: '.json_encode($secondPurposes));
        $this->assertSame($firstPurposes, $secondPurposes, 'Product listing query purposes changed with row count.');
        $this->assertSame(1, $stockAggregateCount($firstRequestQueries), 'The first product rows query must include the physical stock aggregate.');
        $this->assertSame(1, $stockAggregateCount($secondRequestQueries), 'The second product rows query must include the physical stock aggregate.');
        $this->assertSame(1, $firstPurposes['products_count'] ?? 0, 'The first request must use one paginator count query.');
        $this->assertSame(1, $firstPurposes['products_rows'] ?? 0, 'The first request must load product rows once.');
        $this->assertSame(1, $firstPurposes['category_relation'] ?? 0, 'The first request must eager-load categories in one query.');
        $this->assertSame(1, $firstPurposes['brand_relation'] ?? 0, 'The first request must eager-load brands in one query.');

        $this->assertSame(1, $secondPurposes['products_count'] ?? 0, 'The second request must use one paginator count query.');
        $this->assertSame(1, $secondPurposes['products_rows'] ?? 0, 'The second request must load product rows once.');
        $this->assertSame(1, $secondPurposes['category_relation'] ?? 0, 'The second request must eager-load categories in one query.');
        $this->assertSame(1, $secondPurposes['brand_relation'] ?? 0, 'The second request must eager-load brands in one query.');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function product(array $attributes = []): Product
    {
        static $sequence = 0;
        $sequence++;
        $name = $attributes['name'] ?? 'Producto de prueba '.$sequence;

        return Product::create(array_merge([
            'name' => $name,
            'slug' => 'producto-listado-'.$sequence.'-'.uniqid(),
            'sku' => 'LIST-'.$sequence.'-'.uniqid(),
            'price' => '10.00',
            'stock' => 0,
            'is_active' => true,
        ], $attributes));
    }
}
