<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminSidebarFrontendTest extends TestCase
{
    public function test_admin_sidebar_contract_is_present_without_backend_changes(): void
    {
        $layout = file_get_contents(resource_path('js/layouts/AdminLayout.vue'));
        $router = file_get_contents(resource_path('js/router.js'));
        foreach (['isCollapsed', 'isMobileViewport', 'mobileOpen', 'lubristore.admin.sidebar.collapsed', 'toggleCollapsed', 'Expandir menú', 'Contraer menú', 'aria-expanded', 'aria-controls="admin-navigation"', 'aria-label="Navegación administrativa"', 'data-label', 'isRouteActive', 'Escape', 'matchMedia', 'removeEventListener', 'document.body.style.overflow', 'prefers-reduced-motion', 'sr-only'] as $marker) {
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

    public function test_sidebar_collapse_tooltips_active_groups_and_mobile_drawer_are_accessible(): void
    {
        $layout = file_get_contents(resource_path('js/layouts/AdminLayout.vue'));
        $router = file_get_contents(resource_path('js/router.js'));

        foreach ([
            "isCollapsed ? 'Expandir menú' : 'Contraer menú'",
            'aria-controls="admin-navigation"',
            ':aria-expanded="!isSidebarCollapsed"',
            'class="fixed inset-0 z-40 bg-slate-950/60 lg:hidden"',
            '@click.self="closeMobileMenu"',
            'lg:static lg:z-auto lg:w-64 lg:translate-x-0',
            'class="flex min-h-16 items-center justify-between border-b border-slate-200 bg-white px-4 shadow-sm lg:hidden"',
            "window.matchMedia('(max-width: 1023px)')",
            'function isGroupExpanded(groupId) { return !isSidebarCollapsed.value && openGroupId.value === groupId; }',
            '@click="toggleGroup(group.id)"',
            ':aria-expanded="isGroupExpanded(group.id)"',
            ':aria-current="activeGroupId === group.id ? \'location\' : undefined"',
            'visibleAdminGroups = computed(() => !isAdmin.value ? []',
            'directNavigation = computed(() => isTreasury.value ? treasuryNavigation : isBilling.value ? billingNavigation : isAdmin.value ? dashboardNavigation : [])',
            'if (event.key === \'Escape\')',
            'document.body.style.overflow = \'hidden\'',
            'document.body.style.overflow = previousOverflow',
            'if (mobileOpen.value) closeMobileMenu(false)',
            'watch(() => route.fullPath, () => { closeMobileMenu(); loadPendingCount(); });',
            'nextTick(() => menuButton.value?.focus())',
            'data-label="Cerrar sesión"',
            'content: attr(data-label)',
            ':focus-visible::after',
            'max-width: min(14rem, calc(100vw - 6rem))',
            '@media (prefers-reduced-motion: reduce)',
        ] as $marker) {
            $this->assertStringContainsString($marker, $layout, "Sidebar contract missing: $marker");
        }

        $this->assertStringNotContainsString('md:static', $layout);
        $this->assertStringNotContainsString('md:hidden', $layout);
        $this->assertStringNotContainsString(':title="isSidebarCollapsed', $layout);
        $this->assertStringContainsString('meta: { requiresAdmin: true }', $router);
        $this->assertStringContainsString('meta: { requiresTreasury: true', $router);
        $this->assertStringContainsString('to.meta.requiresAdmin', $router);
        $this->assertStringContainsString('to.meta.requiresTreasury', $router);
        $this->assertStringContainsString('else if (!authStore.isAdmin)', $router);
        $this->assertStringContainsString('else if (!authStore.isTreasury)', $router);
    }
}
