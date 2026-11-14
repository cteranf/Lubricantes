<?php

namespace Tests\Feature;

use Tests\TestCase;

class UserAccountMenuFrontendTest extends TestCase
{
    public function test_authenticated_account_menu_uses_real_user_identity_and_accessible_primevue_popup(): void
    {
        $navbar = $this->navbarSource();
        $triggerMarker = strpos($navbar, 'aria-haspopup="menu"');
        $this->assertNotFalse($triggerMarker);
        $triggerStart = strrpos(substr($navbar, 0, $triggerMarker), '<button');
        $this->assertNotFalse($triggerStart);
        $triggerEnd = strpos($navbar, '</button>', $triggerMarker);
        $this->assertNotFalse($triggerEnd);
        $trigger = substr($navbar, $triggerStart, $triggerEnd - $triggerStart + strlen('</button>'));
        $menuHeading = $this->between($navbar, '<template #start>', '</template>');

        $this->assertStringContainsString('aria-haspopup="menu"', $trigger);
        $this->assertStringContainsString(':aria-expanded="accountMenuOpen"', $trigger);
        $this->assertStringContainsString('aria-controls="account-menu"', $trigger);
        $this->assertStringContainsString(':aria-label="`Menú de usuario: ${accountDisplayName}`"', $trigger);
        $this->assertStringContainsString('{{ accountInitials }}', $trigger);
        $this->assertStringContainsString('{{ accountDisplayName }}', $trigger);
        $this->assertStringContainsString('max-w-36 truncate md:inline', $trigger);
        $this->assertStringContainsString('pi pi-chevron-down hidden text-xs md:inline', $trigger);
        $this->assertStringNotContainsString('Mi cuenta', $trigger);
        $this->assertStringContainsString('Mi cuenta', $menuHeading);
        $this->assertStringContainsString('{{ accountDisplayName }}', $menuHeading);
        $this->assertStringContainsString('account-avatar', $trigger);
        $this->assertStringContainsString("import Menu from 'primevue/menu';", $navbar);
        $this->assertStringContainsString('popup', $navbar);
        $this->assertStringContainsString('aria-label="Menú de usuario"', $navbar);
        $this->assertStringContainsString('@show="accountMenuOpen = true"', $navbar);
        $this->assertStringContainsString('@hide="accountMenuOpen = false"', $navbar);
        $this->assertStringContainsString('append-to="body"', $navbar);
        $this->assertStringNotContainsString('role="dialog"', $navbar);
        $this->assertStringNotContainsString('aria-modal', $navbar);
    }

    public function test_menu_items_use_existing_routes_and_exact_role_getters(): void
    {
        $navbar = $this->navbarSource();
        $items = $this->between($navbar, 'const accountMenuItems = computed(() => [', ']);');

        foreach (["label: 'Mi perfil', icon: 'pi pi-user', to: '/profile'", "label: 'Mis pedidos', icon: 'pi pi-receipt', to: '/orders'"] as $item) {
            $this->assertStringContainsString($item, $items);
        }

        $this->assertStringContainsString("...(authStore.isAdmin ? [{ label: 'Panel administrativo'", $items);
        $this->assertStringContainsString("to: '/admin/dashboard'", $items);
        $this->assertStringContainsString("activeScope: 'admin'", $items);
        $this->assertStringContainsString("...(authStore.isTreasury ? [{ label: 'Panel de tesorería'", $items);
        $this->assertStringContainsString("to: { name: 'treasury-payments' }", $items);
        $this->assertStringNotContainsString("role === 'admin'", $items);
        $this->assertStringNotContainsString("role === 'treasury'", $items);
        $this->assertStringContainsString('v-if="authStore.isAuthenticated"', $navbar);
        $this->assertStringContainsString('v-else to="/login"', $navbar);
    }

    public function test_active_routes_focus_closure_and_logout_preserve_existing_behavior(): void
    {
        $navbar = $this->navbarSource();
        $logout = $this->between($navbar, 'async function logout()', 'function handleEscape');

        $this->assertStringContainsString(":aria-current=\"isAccountRouteActive(item) ? 'page' : undefined\"", $navbar);
        $this->assertStringContainsString(":class=\"{ 'account-menu-action-active': isAccountRouteActive(item) }\"", $navbar);
        $this->assertStringContainsString('function isAccountRouteActive(item)', $navbar);
        $this->assertStringContainsString('isAccountMenuRouteActive(item, route.path)', $navbar);
        $this->assertStringContainsString("import { isAccountMenuRouteActive } from '@/utils/accountMenuRoute.mjs';", $navbar);
        $this->assertStringNotContainsString("class: 'account-menu-emphasis'", $navbar);
        $this->assertStringContainsString('accountMenuRef.value?.toggle(event, event.currentTarget)', $navbar);
        $this->assertStringContainsString('accountMenuRef.value?.hide()', $navbar);
        $this->assertStringContainsString('watch(() => route.fullPath, closeMenus)', $navbar);
        $this->assertStringContainsString("if (event.key === 'Escape') mobileMenuOpen.value = false", $navbar);
        $this->assertStringContainsString('onBeforeUnmount(() => document.removeEventListener', $navbar);
        $this->assertStringContainsString('<a v-else href="#" v-bind="props.action"', $navbar);
        $this->assertStringContainsString('@click.prevent', $navbar);
        $this->assertStringContainsString('if (isLoggingOut.value) return;', $logout);
        $this->assertStringContainsString('await authStore.logout()', $logout);
        $this->assertStringContainsString("await router.push('/login')", $logout);
        $this->assertStringContainsString('finally {', $logout);
        $this->assertStringNotContainsString('localStorage', $navbar);
        $this->assertStringNotContainsString('sessionStorage', $navbar);
        $this->assertStringNotContainsString('v-html', $navbar);
    }

    public function test_account_menu_contains_no_profile_photo_dependency_and_keeps_header_shell_actions(): void
    {
        $navbar = $this->navbarSource();

        $this->assertStringContainsString("const name = typeof authStore.user?.name === 'string'", $navbar);
        $this->assertStringContainsString('parts.slice(0, 2)', $navbar);
        $this->assertStringContainsString("toLocaleUpperCase('es-PE')", $navbar);
        $this->assertStringNotContainsString('user?.photo', $navbar);
        $this->assertStringNotContainsString('user?.avatar', $navbar);
        $this->assertStringContainsString('cartStore.openDrawer($event.currentTarget)', $navbar);
        $this->assertStringContainsString('id="mobile-navigation"', $navbar);
        $this->assertStringContainsString('id="desktop-product-search"', $navbar);
        $this->assertStringContainsString('id="mobile-product-search"', $navbar);
        $this->assertStringContainsString('router.push({ path: \'/catalog\', query: { search } })', $navbar);
    }

    private function navbarSource(): string
    {
        return file_get_contents(resource_path('js/components/Navbar.vue'));
    }

    private function between(string $contents, string $start, string $end): string
    {
        $offset = strpos($contents, $start);
        $this->assertNotFalse($offset, "No se encontró el inicio: $start");
        $endOffset = strpos($contents, $end, $offset + strlen($start));
        $this->assertNotFalse($endOffset, "No se encontró el final: $end");

        return substr($contents, $offset, $endOffset - $offset + strlen($end));
    }
}
