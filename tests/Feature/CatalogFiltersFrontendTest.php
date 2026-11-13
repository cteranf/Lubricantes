<?php

namespace Tests\Feature;

use Tests\TestCase;

class CatalogFiltersFrontendTest extends TestCase
{
    public function test_results_summary_uses_the_paginator_meta_total_and_handles_loading_singular_plural_and_empty_states(): void
    {
        $source = file_get_contents(resource_path('js/views/Catalog.vue'));
        preg_match('/const resultsSummary\s*=\s*computed\(\(\)\s*=>\s*\{(?<body>[\s\S]*?)\n\}\);/', $source, $summaryMatch);
        $summary = $summaryMatch['body'] ?? '';

        $this->assertNotSame('', $summary, 'The results summary computed body must be identifiable.');
        $this->assertStringContainsString('if (loading.value)', $summary);
        $this->assertStringContainsString("'Buscando productos…'", $summary);
        $this->assertStringContainsString("'0 productos'", $summary);
        $this->assertStringContainsString("total === 1 ? 'producto' : 'productos'", $summary);
        $this->assertStringContainsString('meta.value.total', $summary);
        $this->assertStringNotContainsString('products.value.length', $summary);
        $this->assertStringContainsString('const pagination = response.data?.meta', $source);
        $this->assertStringContainsString('total: Math.max(0, Number(pagination.total))', $source);
        $this->assertStringNotContainsString('meta.value = response.data;', $source);
        $this->assertStringContainsString('requestId !== productRequestId', $source);
        $this->assertStringContainsString('productRequestController?.abort()', $source);
    }

    public function test_category_and_brand_searches_filter_local_options_without_changing_commercial_query(): void
    {
        $source = file_get_contents(resource_path('js/views/Catalog.vue'));
        preg_match('/const filteredCategories\s*=\s*computed\(\(\)\s*=>\s*\{(?<body>[\s\S]*?)\n\}\);/', $source, $categoryMatch);
        preg_match('/const filteredBrands\s*=\s*computed\(\(\)\s*=>\s*\{(?<body>[\s\S]*?)\n\}\);/', $source, $brandMatch);
        $categoryFilter = $categoryMatch['body'] ?? '';
        $brandFilter = $brandMatch['body'] ?? '';

        $this->assertStringContainsString('aria-label="Buscar categoría"', $source);
        $this->assertStringContainsString('aria-label="Buscar marca"', $source);
        $this->assertStringContainsString("toLocaleLowerCase('es').normalize('NFD')", $source);
        $this->assertStringContainsString("replace(/[\\u0300-\\u036f]/g, '')", $source);
        $this->assertStringContainsString('categories.value.filter(category => normalizeOptionText(category.name).includes(query))', $categoryFilter);
        $this->assertStringContainsString('brands.value.filter(brand => normalizeOptionText(brand.name).includes(query))', $brandFilter);
        $this->assertStringNotContainsString('api.get', $categoryFilter.$brandFilter);
        $this->assertStringContainsString('Todas las categorías', $source);
        $this->assertStringContainsString('Todas las marcas', $source);
        $this->assertStringContainsString('No se encontraron categorías', $source);
        $this->assertStringContainsString('No se encontraron marcas', $source);
        $this->assertStringContainsString('selectedCategoryHidden', $source);
        $this->assertStringContainsString('selectedBrandHidden', $source);
        $this->assertStringContainsString('v-model="categorySearch"', $source);
        $this->assertStringContainsString('v-model="brandSearch"', $source);
        $this->assertSame(1, substr_count($source, "api.get('/products'"));
        $this->assertStringNotContainsString('categorySearch', substr($source, strpos($source, 'const filters = computed'), strpos($source, 'const activeFilterCount') - strpos($source, 'const filters = computed')));
        $this->assertStringNotContainsString('brandSearch', substr($source, strpos($source, 'const requestSignature ='), strpos($source, 'const visibleCategories =') - strpos($source, 'const requestSignature =')));
        $this->assertStringContainsString('const setFilter = (key, value) => updateQuery({ [key]: value, page: undefined })', $source);
        $this->assertStringContainsString('const next = cleanQuery({ ...route.query, ...changes })', $source);
        $this->assertStringContainsString('filter-options-scroll', $source);
        $this->assertStringContainsString('max-height: 13rem', $source);
        $this->assertStringContainsString('<details open class="filter-section">', $source);
        $this->assertStringContainsString('aria-controls="catalog-filters"', $source);
        $this->assertStringContainsString('role="status"', $source);
        $this->assertStringContainsString('filter-search:focus-within', $source);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $source);
    }
}
