<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminSidebarFrontendTest extends TestCase
{
    public function test_admin_sidebar_contract_is_present_without_backend_changes(): void
    {
        $layout = file_get_contents(resource_path('js/layouts/AdminLayout.vue'));
        $router = file_get_contents(resource_path('js/router.js'));
        foreach (['isCollapsed', 'isMobileViewport', 'mobileOpen', 'lubristore.admin.sidebar.collapsed', 'toggleCollapsed', 'Expandir menú lateral', 'Contraer menú lateral', 'aria-expanded', 'aria-controls="admin-navigation"', 'aria-label="Navegación administrativa"', 'data-label', 'isRouteActive', 'Escape', 'matchMedia', 'removeEventListener', 'document.body.style.overflow', 'prefers-reduced-motion', 'sr-only'] as $marker) {
            $this->assertStringContainsString($marker, $layout);
        }
        $this->assertStringNotContainsString('v-html', $layout);
        foreach (['logout-button', 'data-label="Cerrar sesión"', 'aria-label="Cerrar sesión"', 'sr-only', 'white-space: nowrap', 'overflow-y-auto', 'shrink-0', 'async function logout()'] as $marker) {
            $this->assertStringContainsString($marker, $layout);
        }
        foreach (['/admin/dashboard', '/admin/reports/management', '/admin/orders', '/admin/inventory', '/admin/contact-inquiries'] as $path) {
            $this->assertStringContainsString($path, $layout);
            $this->assertStringContainsString($path, $router);
        }
    }
}
