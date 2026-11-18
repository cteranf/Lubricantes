<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductModel;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductModelManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_models_are_unique_by_normalized_name_within_brand_and_shared_across_presentations(): void
    {
        Sanctum::actingAs($this->admin());
        $brand = $this->brand('Liqui Moly');
        $otherBrand = $this->brand('Marca B');

        $model = $this->postJson("/api/v1/admin/brands/{$brand->id}/product-models", ['name' => ' Top Tec 4200 '])
            ->assertCreated()->assertJsonPath('name', 'Top Tec 4200')->json();
        $this->postJson("/api/v1/admin/brands/{$brand->id}/product-models", ['name' => 'top   tec 4200'])
            ->assertUnprocessable();
        $this->postJson("/api/v1/admin/brands/{$otherBrand->id}/product-models", ['name' => 'TOP TEC 4200'])
            ->assertCreated();

        $oneLitre = $this->createProductViaApi($brand, (int) $model['id'], 'TOPTEC-1L', 'Top Tec 1 L', '1 L', 4, '28.50', '4006381333931');
        $fiveLitres = $this->createProductViaApi($brand, (int) $model['id'], 'TOPTEC-5L', 'Top Tec 5 L', '5 L', 9, '62.00', '96385074');
        $this->assertNotSame($oneLitre->id, $fiveLitres->id);
        $this->assertSame('TOPTEC-1L', $oneLitre->sku);
        $this->assertSame('TOPTEC-5L', $fiveLitres->sku);
        $this->assertSame('1 L', $oneLitre->presentation);
        $this->assertSame('5 L', $fiveLitres->presentation);
        $money = app(\App\Services\MoneyService::class);
        $this->assertSame(2850, $money->toMinor((string) $oneLitre->price));
        $this->assertSame(6200, $money->toMinor((string) $fiveLitres->price));
        $this->assertSame((int) $model['id'], (int) $oneLitre->product_model_id);
        $this->assertSame((int) $model['id'], (int) $fiveLitres->product_model_id);
        $warehouse = Warehouse::query()->where('code', Warehouse::INITIAL_CODE)->firstOrFail();
        $this->assertSame(4, (int) $oneLitre->inventories()->where('warehouse_id', $warehouse->id)->value('quantity'));
        $this->assertSame(9, (int) $fiveLitres->inventories()->where('warehouse_id', $warehouse->id)->value('quantity'));
        $this->assertSame('4006381333931', ProductBarcode::query()->where('product_id', $oneLitre->id)->value('code'));
        $this->assertSame('96385074', ProductBarcode::query()->where('product_id', $fiveLitres->id)->value('code'));

        $publicProduct = $this->getJson('/api/v1/products/'.$oneLitre->slug)->assertOk()
            ->assertJsonPath('product_model.name', 'Top Tec 4200')->json();
        $this->assertSame(['name'], array_keys($publicProduct['product_model']));
    }

    public function test_model_assignment_is_brand_scoped_and_brand_changes_require_an_explicit_resolution(): void
    {
        Sanctum::actingAs($this->admin());
        $brandA = $this->brand('Marca A');
        $brandB = $this->brand('Marca B');
        $modelA = $this->createModel($brandA, 'Filtro ABC-00123');
        $modelB = $this->createModel($brandB, 'Modelo B');

        $this->postJson('/api/v1/admin/products', $this->productPayload($brandA, $modelB->id, 'FILTER-WRONG'))
            ->assertUnprocessable()->assertJsonValidationErrors('product_model_id');

        $product = $this->postJson('/api/v1/admin/products', $this->productPayload($brandA, $modelA->id, 'FILTER-ABC'))
            ->assertCreated()->assertJsonPath('product_model_id', $modelA->id)->json();

        $this->putJson('/api/v1/admin/products/'.$product['id'], ['brand_id' => $brandB->id])
            ->assertUnprocessable()->assertJsonValidationErrors('product_model_id');
        $this->putJson('/api/v1/admin/products/'.$product['id'], ['brand_id' => $brandB->id, 'product_model_id' => $modelB->id])
            ->assertOk()->assertJsonPath('product_model_id', $modelB->id);
        $this->putJson('/api/v1/admin/products/'.$product['id'], ['brand_id' => $brandA->id, 'product_model_id' => null])
            ->assertOk()->assertJsonPath('product_model_id', null);

        $this->putJson('/api/v1/admin/products/'.$product['id'], ['brand_id' => $brandA->id, 'product_model_id' => $modelB->id])
            ->assertUnprocessable()->assertJsonValidationErrors('product_model_id');
    }

    public function test_product_form_options_return_active_brand_models_and_saved_selection_survives_editing(): void
    {
        Sanctum::actingAs($this->admin());
        $brand = $this->brand('Castrol');
        $models = collect(['CVAA', 'RRFF', 'TOPC'])->map(fn (string $name) => $this->createModel($brand, $name));

        $options = $this->getJson("/api/v1/admin/brands/{$brand->id}/product-models/options")
            ->assertOk()
            ->assertJsonCount(3)
            ->json();

        $this->assertSame(['CVAA', 'RRFF', 'TOPC'], collect($options)->pluck('name')->all());
        $this->assertSame([$brand->id], collect($options)->pluck('brand_id')->unique()->all());

        $selectedModel = $models->firstWhere('name', 'TOPC');
        $product = $this->postJson('/api/v1/admin/products', $this->productPayload($brand, $selectedModel->id, 'CASTROL-TOPC'))
            ->assertCreated()
            ->assertJsonPath('product_model_id', $selectedModel->id)
            ->json();

        $this->getJson('/api/v1/admin/products/'.$product['id'])
            ->assertOk()
            ->assertJsonPath('product_model_id', $selectedModel->id)
            ->assertJsonPath('product_model.name', 'TOPC');

        $this->putJson('/api/v1/admin/products/'.$product['id'], ['price' => '18.25'])
            ->assertOk()
            ->assertJsonPath('product_model_id', $selectedModel->id)
            ->assertJsonPath('product_model.name', 'TOPC');
    }

    public function test_inactive_assigned_model_can_be_retained_but_not_assigned_to_another_product(): void
    {
        Sanctum::actingAs($this->admin());
        $brand = $this->brand('Liqui Moly');
        $model = $this->createModel($brand, 'Top Tec 4200');
        $product = $this->postJson('/api/v1/admin/products', $this->productPayload($brand, $model->id, 'OIL-001'))
            ->assertCreated()->json();

        $this->patchJson("/api/v1/admin/product-models/{$model->id}/status", ['is_active' => false])->assertOk();
        $this->putJson('/api/v1/admin/products/'.$product['id'], ['price' => '22.50'])
            ->assertOk()->assertJsonPath('product_model_id', $model->id)
            ->assertJsonPath('product_model.name', 'Top Tec 4200');
        $this->postJson('/api/v1/admin/products', $this->productPayload($brand, $model->id, 'OIL-002'))
            ->assertUnprocessable()->assertJsonValidationErrors('product_model_id');
    }

    public function test_models_can_be_renamed_deactivated_and_reactivated_without_losing_associations(): void
    {
        Sanctum::actingAs($this->admin());
        $brand = $this->brand('Marca con modelos');
        $model = $this->postJson("/api/v1/admin/brands/{$brand->id}/product-models", ['name' => 'Filtro ABC-00123'])
            ->assertCreated()->json();
        $product = $this->createProduct($brand, (int) $model['id'], 'CABIN-123', 'Filtro de cabina', '');

        $this->putJson('/api/v1/admin/product-models/'.$model['id'], ['name' => 'ABC-00123 Rev. 2'])
            ->assertOk()->assertJsonPath('name', 'ABC-00123 Rev. 2');
        $this->patchJson('/api/v1/admin/product-models/'.$model['id'].'/status', ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);
        $this->getJson('/api/v1/admin/brands/'.$brand->id.'/product-models')
            ->assertOk()->assertJsonPath('data.0.products_count', 1)->assertJsonPath('data.0.name', 'ABC-00123 Rev. 2');
        $this->assertSame((int) $model['id'], (int) $product->fresh()->product_model_id);

        $this->patchJson('/api/v1/admin/product-models/'.$model['id'].'/status', ['is_active' => true])
            ->assertOk()->assertJsonPath('is_active', true);
        $this->getJson('/api/v1/admin/brands/'.$brand->id.'/product-models/options')
            ->assertOk()->assertJsonPath('0.name', 'ABC-00123 Rev. 2');
    }

    public function test_admin_model_search_and_filter_preserve_server_pagination(): void
    {
        Sanctum::actingAs($this->admin());
        $brand = $this->brand('Liqui Moly');
        $model = $this->postJson("/api/v1/admin/brands/{$brand->id}/product-models", ['name' => 'ABC-00123'])
            ->assertCreated()->json();
        $product = $this->createProduct($brand, (int) $model['id'], 'FILTER-1', 'Filtro de cabina', '');

        $this->getJson('/api/v1/admin/products?search=ABC-00123&product_model_id='.$model['id'].'&page=1&per_page=15')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.product_model.name', 'ABC-00123');
        $public = $this->getJson('/api/v1/products/'.$product->slug)->assertOk()
            ->assertJsonPath('product_model.name', 'ABC-00123')->json('product_model');
        $this->assertSame(['name'], array_keys($public));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function brand(string $name): Brand
    {
        $slug = strtolower(str_replace(' ', '-', $name)).'-'.uniqid();

        return Brand::create(['name' => $name, 'slug' => $slug, 'is_active' => true]);
    }

    private function createModel(Brand $brand, string $name): ProductModel
    {
        return ProductModel::create([
            'brand_id' => $brand->id,
            'name' => $name,
            'normalized_name' => ProductModel::normalizeName($name),
            'is_active' => true,
        ]);
    }

    private function createProduct(Brand $brand, int $modelId, string $sku, string $name, string $presentation): Product
    {
        return Product::create([
            'brand_id' => $brand->id,
            'product_model_id' => $modelId,
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(),
            'sku' => $sku,
            'price' => '10.00',
            'presentation' => $presentation,
            'stock' => 0,
            'is_active' => true,
        ]);
    }

    private function createProductViaApi(Brand $brand, int $modelId, string $sku, string $name, string $presentation, int $initialQuantity, string $price, string $barcode): Product
    {
        $warehouse = Warehouse::query()->where('code', Warehouse::INITIAL_CODE)->firstOrFail();
        $response = $this->postJson('/api/v1/admin/products', [
            'name' => $name,
            'sku' => $sku,
            'price' => $price,
            'presentation' => $presentation,
            'cantidad_inicial' => $initialQuantity,
            'warehouse_id' => $warehouse->id,
            'brand_id' => $brand->id,
            'product_model_id' => $modelId,
            'barcodes' => [['code' => $barcode, 'format' => strlen($barcode) === 13 ? 'ean13' : 'ean8', 'is_primary' => true]],
        ])->assertCreated();

        return Product::query()->findOrFail($response->json('id'));
    }

    private function productPayload(Brand $brand, ?int $modelId, string $sku): array
    {
        return [
            'name' => 'Producto '.$sku,
            'sku' => $sku,
            'price' => '10.00',
            'cantidad_inicial' => 0,
            'brand_id' => $brand->id,
            'product_model_id' => $modelId,
        ];
    }
}
