<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductSpecificationsFrontendTest extends TestCase
{
    public function test_admin_form_edits_validates_reorders_and_sends_only_public_specification_fields(): void
    {
        $view = file_get_contents(resource_path('js/views/admin/Products.vue'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/V1/Admin/ProductController.php'));
        $storeRequest = file_get_contents(app_path('Http/Requests/Admin/StoreProductRequest.php'));
        $updateRequest = file_get_contents(app_path('Http/Requests/Admin/UpdateProductRequest.php'));
        $seeder = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        $section = $this->sourceBetween($view, 'aria-labelledby="product-specifications-title"', '</section>');
        $submit = $this->sourceBetween($view, 'async function submitForm() {', 'function confirmStatus(');
        $open = $this->sourceBetween($view, 'async function openModal(', 'function isCurrentOpening(');
        $loadDetail = $this->sourceBetween($view, 'async function loadProductSpecifications(', 'function retryProductSpecifications(');

        $this->assertStringContainsString('Especificaciones técnicas', $section);
        $this->assertStringContainsString('form.specifications?.length || 0 }} de 30', $section);
        $this->assertStringContainsString('maxlength="100"', $section);
        $this->assertStringContainsString('maxlength="500"', $section);
        $this->assertStringContainsString('Agregar especificación', $section);
        $this->assertStringContainsString('moveSpecification(index, -1)', $section);
        $this->assertStringContainsString('moveSpecification(index, 1)', $section);
        $this->assertStringContainsString('removeSpecification(index)', $section);
        $this->assertStringContainsString('aria-invalid=', $section);
        $this->assertStringContainsString('role="alert"', $section);
        $this->assertStringContainsString("specifications.*' => ['required', 'array:name,value']", $storeRequest.$updateRequest);
        $this->assertStringContainsString("'specifications' => ['sometimes', 'array', 'max:30']", $storeRequest.$updateRequest);
        $this->assertStringContainsString("'specifications.*.name' => ['required', 'string', 'max:100']", $storeRequest.$updateRequest);
        $this->assertStringContainsString("'specifications.*.value' => ['required', 'string', 'max:500']", $storeRequest.$updateRequest);
        $this->assertStringContainsString('normalizeProductSpecifications()', $storeRequest.$updateRequest);
        $this->assertStringContainsString('validateUniqueProductSpecificationNames($validator)', $storeRequest.$updateRequest);

        $this->assertStringContainsString('await api.get(`/admin/products/${productId}`)', $loadDetail);
        $this->assertStringContainsString('Array.isArray(data.specifications)', $loadDetail);
        $this->assertStringContainsString('specificationRows(data.specifications)', $loadDetail);
        $this->assertStringContainsString('specificationsLoading.value || specificationsError.value', $submit);
        $this->assertStringContainsString("data.append('specifications', JSON.stringify(formState.specifications.map(({ name, value }) => ({ name, value }))))", $submit);
        $this->assertStringNotContainsString('sort_order', $submit);
        $this->assertStringNotContainsString('product_id', $submit);
        $this->assertStringContainsString('this->replaceSpecifications($product, $specifications)', $controller);
        $this->assertStringContainsString('array_key_exists(\'specifications\', $validated)', $controller);
        $this->assertStringContainsString('$product->specifications()->delete();', $controller);
        $this->assertStringContainsString("'sort_order' => \$sortOrder", $controller);
        $this->assertStringContainsString('form.value = { name:', $open);

        $productCreation = $this->sourceBetween($seeder, '$product = Product::create([', ']);');
        $this->assertStringNotContainsString("'specifications' =>", $productCreation);
        $this->assertStringContainsString('$product->specifications()->create([', $seeder);
        $this->assertStringContainsString("'name' => 'Información técnica'", $seeder);
    }

    public function test_public_detail_hides_empty_specifications_and_uses_escaped_responsive_markup(): void
    {
        $view = file_get_contents(resource_path('js/views/ProductDetail.vue'));
        $resource = file_get_contents(app_path('Http/Resources/ProductResource.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/V1/ProductController.php'));

        $information = $this->sourceBetween($view, '<section class="mt-10 grid gap-5"', '</section>');

        $gridExpression = <<<'VUE'
product.specifications?.length ? 'lg:grid-cols-2' : 'lg:grid-cols-1'
VUE;
        $this->assertStringContainsString($gridExpression, $information);
        $this->assertStringContainsString('v-if="product.specifications?.length"', $information);
        $this->assertStringContainsString('v-for="(specification, index) in product.specifications"', $information);
        $this->assertStringContainsString('<dl', $information);
        $this->assertStringContainsString('<dt', $information);
        $this->assertStringContainsString('<dd', $information);
        $this->assertStringContainsString('{{ specification.name }}', $information);
        $this->assertStringContainsString('{{ specification.value }}', $information);
        $this->assertStringNotContainsString('No se registraron especificaciones', $information);
        $this->assertStringNotContainsString('v-html', $information);
        $resourceProjection = <<<'PHP'
'specifications' => $this->when($product->relationLoaded('specifications')
PHP;
        $this->assertStringContainsString($resourceProjection, $resource);
        $this->assertSame(2, substr_count($controller, "'specifications:id,product_id,name,value,sort_order'"));
        $legacyProjection = <<<'PHP'
'specifications' => $product->specifications
PHP;
        $this->assertStringNotContainsString($legacyProjection, $resource);
    }

    private function sourceBetween(string $source, string $start, string $end): string
    {
        $startOffset = strpos($source, $start);
        $this->assertNotFalse($startOffset, 'Expected source section start was not found.');
        $contentOffset = $startOffset + strlen($start);
        $endOffset = strpos($source, $end, $contentOffset);
        $this->assertNotFalse($endOffset, 'Expected source section end was not found.');

        return substr($source, $startOffset, $endOffset - $startOffset);
    }
}
