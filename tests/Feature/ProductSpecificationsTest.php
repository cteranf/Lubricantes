<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductSpecificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_normalized_ordered_specifications_with_the_product(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->postJson('/api/v1/admin/products', [
            'name' => 'Aceite de prueba',
            'price' => '25.50',
            'cantidad_inicial' => 0,
            'specifications' => [
                ['name' => "  Viscosidad\t SAE  ", 'value' => '  20W-50  '],
                ['name' => 'Presentación', 'value' => '  1 litro  '],
            ],
        ])->assertCreated();

        $response->assertJsonStructure(['specifications' => [['name', 'value']]])
            ->assertJsonPath('specifications.0.name', 'Viscosidad SAE')
            ->assertJsonPath('specifications.0.value', '20W-50')
            ->assertJsonPath('specifications.1.name', 'Presentación')
            ->assertJsonPath('specifications.1.value', '1 litro');

        $productId = $response->json('id');
        $this->assertDatabaseHas('product_specifications', [
            'product_id' => $productId,
            'name' => 'Viscosidad SAE',
            'value' => '20W-50',
            'sort_order' => 0,
        ]);
        $this->assertSame(['name', 'value'], array_keys($response->json('specifications.0')));
    }

    public function test_update_reorders_and_synchronizes_only_when_specifications_are_present(): void
    {
        Sanctum::actingAs($this->admin());
        $product = $this->product();
        $original = $product->specifications()->createMany([
            ['name' => 'Viscosidad', 'value' => '10W-30', 'sort_order' => 0],
            ['name' => 'Presentación', 'value' => '1 litro', 'sort_order' => 1],
        ]);

        $this->putJson('/api/v1/admin/products/'.$product->id, [
            'name' => 'Nombre actualizado',
            'specifications' => [
                ['name' => 'Presentación', 'value' => '4 litros'],
                ['name' => 'Viscosidad', 'value' => '5W-40'],
            ],
        ])->assertOk()
            ->assertJsonPath('specifications.0.name', 'Presentación')
            ->assertJsonPath('specifications.1.name', 'Viscosidad');

        $this->assertDatabaseMissing('product_specifications', ['id' => $original[0]->id]);
        $this->assertDatabaseMissing('product_specifications', ['id' => $original[1]->id]);
        $this->assertSame([0, 1], $product->specifications()->orderBy('sort_order')->pluck('sort_order')->all());

        $this->putJson('/api/v1/admin/products/'.$product->id, ['name' => 'Edición sin especificaciones'])->assertOk();
        $this->assertSame(2, $product->specifications()->count(), 'Omitting the field must preserve the existing specification set.');
    }

    public function test_explicit_empty_array_removes_all_specifications(): void
    {
        Sanctum::actingAs($this->admin());
        $product = $this->product();
        $product->specifications()->create(['name' => 'Viscosidad', 'value' => '20W-50', 'sort_order' => 0]);

        $this->putJson('/api/v1/admin/products/'.$product->id, ['specifications' => []])
            ->assertOk()
            ->assertJsonPath('specifications', []);

        $this->assertDatabaseCount('product_specifications', 0);
    }

    public function test_specification_validation_rejects_empty_duplicate_oversized_and_untrusted_fields(): void
    {
        Sanctum::actingAs($this->admin());
        $payload = ['name' => 'Dato', 'value' => 'Valor'];

        $this->postJson('/api/v1/admin/products', [
            'name' => 'Duplicadas', 'price' => 1, 'cantidad_inicial' => 0,
            'specifications' => [$payload, ['name' => ' dato ', 'value' => 'Otro']],
        ])->assertUnprocessable()->assertJsonValidationErrors('specifications.1.name');

        $this->postJson('/api/v1/admin/products', [
            'name' => 'Vacía', 'price' => 1, 'cantidad_inicial' => 0,
            'specifications' => [['name' => '   ', 'value' => '  ']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['specifications.0.name', 'specifications.0.value']);

        $tooMany = array_fill(0, 31, $payload);
        $this->postJson('/api/v1/admin/products', [
            'name' => 'Demasiadas', 'price' => 1, 'cantidad_inicial' => 0,
            'specifications' => $tooMany,
        ])->assertUnprocessable()->assertJsonValidationErrors('specifications');

        $this->postJson('/api/v1/admin/products', [
            'name' => 'Campo extra', 'price' => 1, 'cantidad_inicial' => 0,
            'specifications' => [['name' => 'SKU', 'value' => 'A', 'product_id' => 99, 'sort_order' => 80]],
        ])->assertUnprocessable()->assertJsonValidationErrors('specifications.0');

        $this->postJson('/api/v1/admin/products', [
            'name' => 'Dato largo', 'price' => 1, 'cantidad_inicial' => 0,
            'specifications' => [['name' => str_repeat('n', 101), 'value' => str_repeat('v', 501)]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['specifications.0.name', 'specifications.0.value']);

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_specifications', 0);
    }

    public function test_failed_specification_write_rolls_back_product_and_specification_rows(): void
    {
        Sanctum::actingAs($this->admin());
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Rollback trigger fixture requires the isolated SQLite test connection.');
        }

        DB::statement("CREATE TRIGGER reject_product_specifications BEFORE INSERT ON product_specifications BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");

        $this->postJson('/api/v1/admin/products', [
            'name' => 'Debe revertirse', 'price' => 1, 'cantidad_inicial' => 0,
            'specifications' => [['name' => 'Viscosidad', 'value' => '20W-50']],
        ])->assertServerError();

        $this->assertDatabaseMissing('products', ['name' => 'Debe revertirse']);
        $this->assertDatabaseCount('product_specifications', 0);
    }

    public function test_public_detail_and_admin_detail_project_only_public_specification_fields(): void
    {
        $product = $this->product();
        $product->specifications()->create(['name' => 'Viscosidad', 'value' => '20W-50', 'sort_order' => 0]);
        $product->specifications()->create(['name' => 'Temperatura', 'value' => '−20 a 40 °C', 'sort_order' => 1]);

        $public = $this->getJson('/api/v1/products/'.$product->slug)->assertOk()->json();
        $this->assertSame([
            ['name' => 'Viscosidad', 'value' => '20W-50'],
            ['name' => 'Temperatura', 'value' => '−20 a 40 °C'],
        ], $public['specifications']);

        foreach ($public['specifications'] as $specification) {
            $this->assertSame(['name', 'value'], array_keys($specification));
        }
        foreach (['product_id', 'sort_order', 'created_at', 'updated_at', 'id'] as $privateField) {
            $this->assertArrayNotHasKey($privateField, $public['specifications'][0]);
        }

        Sanctum::actingAs($this->admin());
        $admin = $this->getJson('/api/v1/admin/products/'.$product->id)->assertOk()->json();
        $this->assertSame($public['specifications'], $admin['specifications']);
        $this->assertArrayNotHasKey('data', $admin);
    }

    public function test_products_without_specifications_return_an_empty_public_list(): void
    {
        $product = $this->product();

        $response = $this->getJson('/api/v1/products/'.$product->slug)->assertOk()->json();

        $this->assertSame([], $response['specifications']);
    }

    public function test_specifications_are_eager_loaded_in_bounded_queries_and_not_loaded_for_admin_list(): void
    {
        foreach (range(1, 4) as $index) {
            $product = $this->product(['name' => 'Producto '.$index, 'slug' => 'producto-especificaciones-'.$index]);
            $product->specifications()->create(['name' => 'Medida', 'value' => (string) $index, 'sort_order' => 0]);
        }

        $specificationQueries = [];
        DB::listen(static function (QueryExecuted $query) use (&$specificationQueries): void {
            if (str_contains(strtolower($query->sql), 'product_specifications')) {
                $specificationQueries[] = $query->sql;
            }
        });

        $this->getJson('/api/v1/products?sort=newest&page=1')->assertOk();
        $this->assertCount(1, $specificationQueries, 'Public product listing should eager-load specifications in one bounded query.');

        $specificationQueries = [];
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/admin/products')->assertOk();
        $this->assertCount(0, $specificationQueries, 'Administrative list must not load specifications it does not render.');
    }

    public function test_product_deletion_cascades_specifications(): void
    {
        $product = $this->product();
        $product->specifications()->create(['name' => 'Viscosidad', 'value' => '20W-50', 'sort_order' => 0]);

        $product->delete();

        $this->assertDatabaseMissing('product_specifications', ['product_id' => $product->id]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function product(array $attributes = []): Product
    {
        static $sequence = 0;
        $sequence++;

        return Product::query()->create(array_merge([
            'name' => 'Producto de prueba '.$sequence,
            'slug' => 'producto-especificaciones-'.$sequence,
            'sku' => 'SPEC-'.$sequence,
            'price' => '20.00',
            'is_active' => true,
        ], $attributes));
    }
}
