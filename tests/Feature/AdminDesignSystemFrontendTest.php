<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminDesignSystemFrontendTest extends TestCase
{
    public function test_first_stage_admin_cruds_use_the_shared_static_visual_contract(): void
    {
        $catalog = file_get_contents(resource_path('js/components/admin/CatalogEntityManager.vue'));
        $categories = file_get_contents(resource_path('js/views/admin/Categories.vue'));
        $brands = file_get_contents(resource_path('js/views/admin/Brands.vue'));
        $sliders = file_get_contents(resource_path('js/views/admin/Sliders.vue'));
        $payments = file_get_contents(resource_path('js/views/admin/PaymentSettings.vue'));

        foreach (['AdminPageHeader', 'AdminStatusBadge', 'AdminIconButton', 'AdminEmptyState', 'AdminPagination'] as $component) {
            $this->assertFileExists(resource_path("js/components/admin/{$component}.vue"));
        }

        foreach (['overflow-x-auto', 'scope="col"', 'animate-pulse', 'useConfirm', 'confirm.require', 'pi pi-pencil', 'pi pi-ban', 'aria-label', 'data-tooltip', 'No se pudo cargar el listado'] as $marker) {
            $this->assertStringContainsString($marker, $catalog);
        }
        foreach (['api.get(props.endpoint', 'api.post(props.endpoint', 'api.put(`${props.endpoint}/${form.value.id}`', 'api.patch(`${props.endpoint}/${item.id}/status`'] as $marker) {
            $this->assertStringContainsString($marker, $catalog);
        }
        foreach (['CATÁLOGO COMERCIAL', '/admin/categories', '/admin/brands'] as $marker) {
            $this->assertTrue(str_contains($categories.$brands, $marker));
        }
        foreach (['CONTENIDO DE LA TIENDA', '/admin/sliders', 'FormData', 'URL.createObjectURL', 'URL.revokeObjectURL', 'pi pi-trash', 'AdminStatusBadge', 'overflow-x-auto', 'scope="col"'] as $marker) {
            $this->assertStringContainsString($marker, $sliders);
        }
        foreach (['CONFIGURACIÓN COMERCIAL', 'AdminPageHeader', '/admin/payment-settings', 'loadError', 'mercadopago_access_token', 'type="password"'] as $marker) {
            $this->assertStringContainsString($marker, $payments);
        }
        foreach ([$catalog, $sliders, $payments] as $view) {
            $this->assertStringNotContainsString('v-html', $view);
        }
    }
}
