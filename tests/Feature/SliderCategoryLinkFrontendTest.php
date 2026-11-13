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

    public function test_home_category_cards_are_accessible_responsive_image_links_with_safe_fallback_and_limit(): void
    {
        $home = file_get_contents(resource_path('js/views/Home.vue'));

        $this->assertStringContainsString('visibleCategories = computed(() => categories.value.slice(0, 8))', $home);
        $this->assertStringContainsString(":to=\"{ path: '/catalog', query: { category: category.slug } }\"", $home);
        $this->assertStringContainsString('Ver todas las categorías', $home);
        $this->assertStringContainsString('to="/catalog"', $home);
        $this->assertStringContainsString('category.image_url && !failedCategoryImages[category.id]', $home);
        $this->assertStringContainsString('loading="lazy" decoding="async"', $home);
        $this->assertStringContainsString(':alt="category.name"', $home);
        $this->assertStringContainsString('markCategoryImageFailed(category.id)', $home);
        $this->assertStringContainsString('category-image-fallback', $home);
        $this->assertStringContainsString('aspect-ratio: 16 / 10', $home);
        $this->assertStringContainsString('.category-card:focus-visible', $home);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $home);
        $this->assertStringContainsString('repeat(4, minmax(0, 1fr))', $home);
        $this->assertStringNotContainsString('window.location', $home);
    }

    public function test_category_admin_enables_image_management_without_changing_other_catalog_managers(): void
    {
        $categories = file_get_contents(resource_path('js/views/admin/Categories.vue'));
        $manager = file_get_contents(resource_path('js/components/admin/CatalogEntityManager.vue'));

        $this->assertStringContainsString('image-enabled', $categories);
        $this->assertStringContainsString("imageEnabled:{type:Boolean,default:false}", str_replace(' ', '', $manager));
        $this->assertStringContainsString('accept="image/jpeg,image/png,image/webp"', $manager);
        $this->assertStringContainsString('URL.createObjectURL(file)', $manager);
        $this->assertStringContainsString('URL.revokeObjectURL(previewUrl.value)', $manager);
        $this->assertStringContainsString('selectedFile.value = null', $manager);
        $this->assertStringContainsString('if (processing.value || imageProcessing.value) return', $manager);
        $this->assertStringContainsString('api.delete(`${props.endpoint}/${form.value.id}/image`)', $manager);
        $this->assertStringContainsString("_method', 'PUT'", $manager);
        $this->assertStringContainsString('La imagen existente se conserva si guardas sin elegir otra.', $manager);
        $this->assertStringContainsString('confirm.require(', $manager);
        $this->assertStringNotContainsString('response?.data?.message', $manager);
        $this->assertStringNotContainsString('v-html', $manager);
    }
}
