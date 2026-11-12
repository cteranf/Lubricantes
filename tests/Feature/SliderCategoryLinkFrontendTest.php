<?php

namespace Tests\Feature;

use Tests\TestCase;

class SliderCategoryLinkFrontendTest extends TestCase
{
    public function test_home_preserves_real_short_sliders_and_uses_a_descriptive_router_link_only_with_a_category(): void
    {
        $home = file_get_contents(resource_path('js/views/Home.vue'));

        $this->assertStringNotContainsString('isPlaceholderSlide', $home);
        $this->assertStringContainsString("image: typeof slide.image_path === 'string' && slide.image_path !== '' ? slide.image_path : null", $home);
        $this->assertStringContainsString('description: typeof slide.description === \'string\' ? slide.description : \'\'', $home);
        $this->assertStringContainsString("import { RouterLink } from 'vue-router'", $home);
        $this->assertStringContainsString("slotProps.data.category?.slug ? RouterLink : 'div'", $home);
        $this->assertStringContainsString("{ name: 'Catalog', query: { category: slotProps.data.category.slug } }", $home);
        $this->assertStringContainsString('Ver productos', $home);
        $this->assertStringContainsString('aria-label="Promociones de LubriStore"', $home);
        $this->assertStringContainsString(':aria-label="slotProps.data.category?.slug ?', $home);
        $this->assertStringContainsString('Ver productos de ${slotProps.data.category.name}', $home);
        $this->assertStringNotContainsString('window.location', $home);
    }

    public function test_catalog_validates_category_query_and_reconciles_route_changes_without_duplicate_watchers(): void
    {
        $catalog = file_get_contents(resource_path('js/views/Catalog.vue'));
        preg_match('/const updateQuery = .*?(?=\nconst setFilter)/s', $catalog, $updateQueryMatch);
        $updateQuery = $updateQueryMatch[0] ?? '';

        $this->assertStringContainsString("typeof route.query.category === 'string' ? route.query.category.trim() : ''", $catalog);
        $this->assertStringContainsString("Object.prototype.hasOwnProperty.call(route.query, 'category')", $catalog);
        $this->assertStringContainsString('visibleCategories.value.some(category => category.slug === rawCategory.trim())', $catalog);
        $this->assertStringContainsString("router.replace({ path: '/catalog', query })", $catalog);
        $this->assertStringContainsString('category: undefined', $catalog);
        $this->assertStringContainsString('const requestSignature = computed(() => JSON.stringify({ filters: filters.value, rawCategory: route.query.category }))', $catalog);
        $this->assertSame(1, substr_count($catalog, 'watch(requestSignature, loadProducts'));
        $this->assertStringContainsString('productRequestController?.abort()', $catalog);
        $this->assertStringContainsString('requestId !== productRequestId', $catalog);
        $this->assertNotSame('', $updateQuery, 'The query navigation helper must be extracted from Catalog.vue.');
        $this->assertSame(1, preg_match('/if\s*\(\s*replace\s*\)\s*\{\s*return\s+router\.replace\(\s*destination\s*\)\s*;\s*\}\s*return\s+router\.push\(\s*destination\s*\)\s*;/s', $updateQuery), 'replace=true must call replace and the default false path must call push.');
        $this->assertSame(1, substr_count($updateQuery, 'router.replace('));
        $this->assertSame(1, substr_count($updateQuery, 'router.push('));
        $this->assertStringContainsString('const submitSearch = () => updateQuery(', $catalog);
        $this->assertStringContainsString('page: undefined }, true)', $catalog);
        $ordinaryUpdates = substr($catalog, strpos($catalog, 'const setFilter ='), strpos($catalog, 'const submitSearch =') - strpos($catalog, 'const setFilter ='));
        $this->assertStringContainsString('const setFilter = (key, value) => updateQuery(', $ordinaryUpdates);
        $this->assertStringNotContainsString(', true)', $ordinaryUpdates);
    }

    public function test_slider_admin_loads_active_category_options_and_sends_only_category_id(): void
    {
        $admin = file_get_contents(resource_path('js/views/admin/Sliders.vue'));

        $this->assertStringContainsString("api.get('/admin/categories/options')", $admin);
        $this->assertStringContainsString('Categoría de destino (opcional)', $admin);
        $this->assertStringContainsString('Al hacer clic en el banner, el cliente verá el catálogo filtrado por esta categoría.', $admin);
        $this->assertStringContainsString('slider.category_id ?? slider.category?.id ?? null', $admin);
        $this->assertStringContainsString("data.append('category_id'", $admin);
        $this->assertStringNotContainsString("data.append('url'", $admin);
        $this->assertStringNotContainsString('response?.data?.message', $admin);
        $this->assertStringContainsString('if (processing.value) return', $admin);
    }
}
