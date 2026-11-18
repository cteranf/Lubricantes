<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductModelsFrontendContractTest extends TestCase
{
    public function test_product_form_scopes_searchable_model_selection_to_brand_and_submits_null_explicitly(): void
    {
        $products = file_get_contents(resource_path('js/views/admin/Products.vue'));
        $brands = file_get_contents(resource_path('js/views/admin/Brands.vue'));
        $adminResource = file_get_contents(app_path('Http/Resources/AdminProductListResource.php'));

        $this->assertStringContainsString("import Select from 'primevue/select';", $products);
        $this->assertStringContainsString('label="Modelo"', $products);
        $this->assertStringContainsString('option-label="name" option-value="id" placeholder="Sin modelo" show-clear filter', $products);
        $this->assertStringContainsString('@change="onBrandSelected"', $products);
        $this->assertStringContainsString('form.value.product_model_id = \'\'', $products);
        $this->assertStringContainsString('const { data } = await api.get(`/admin/brands/${Number(brandId)}/product-models/options`)', $products);
        $this->assertStringContainsString('productModelOptions.value = Array.isArray(data) ? data : []', $products);
        $this->assertStringContainsString(':options="productModelOptions"', $products);
        $this->assertStringContainsString('await loadProductModels(data.brand_id, data.product_model)', $products);
        $this->assertStringContainsString('return loadProductModels(form.value.brand_id)', $products);
        $this->assertStringContainsString('modelOptionsError.value = \'No se pudieron cargar los modelos de esta marca.\'', $products);
        $this->assertStringContainsString('La marca seleccionada no tiene modelos activos.', $products);
        $this->assertStringContainsString('@click="retryProductModels"', $products);
        $this->assertStringContainsString('data.append(\'product_model_id\', formState.product_model_id ?? \'\')', $products);
        $this->assertStringContainsString('productModelOptions.value.push({ ...assignedModel, is_active: false })', $products);
        $this->assertStringContainsString('product_model_id', $adminResource);
        $this->assertStringContainsString("whenLoaded('productModel'", $adminResource);

        $this->assertStringContainsString('slot name="row-actions"', file_get_contents(resource_path('js/components/admin/CatalogEntityManager.vue')));
        $this->assertStringContainsString('Administrar modelos de ${item.name}', $brands);
        $this->assertStringContainsString('/product-models/${model.id}', $brands);
        $this->assertStringContainsString('/product-models/${model.id}/status', $brands);
        $this->assertStringContainsString('Los productos que ya lo usan conservarán su asociación.', $brands);
        $this->assertStringNotContainsString('v-html', $brands);
    }

    public function test_public_product_detail_shows_only_the_assigned_commercial_model(): void
    {
        $detail = file_get_contents(resource_path('js/views/ProductDetail.vue'));
        $resource = file_get_contents(app_path('Http/Resources/ProductResource.php'));

        $this->assertStringContainsString('product.product_model?.name', $detail);
        $this->assertStringContainsString('>Modelo:</span>', $detail);
        $this->assertStringContainsString("whenLoaded('productModel'", $resource);
        $this->assertStringContainsString("'name' => (string) \$product->productModel->name", $resource);
        $this->assertStringNotContainsString("'normalized_name'", $resource);
    }
}
