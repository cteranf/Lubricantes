<?php

namespace Tests\Feature;

use Tests\TestCase;

class NavbarTreasuryAccessFrontendTest extends TestCase
{
    public function test_treasury_shortcut_is_role_scoped_and_targets_the_named_route(): void
    {
        $navbar = $this->navbarSource();

        $this->assertStringContainsString("...(authStore.isTreasury ? [{ label: 'Panel de tesorería'", $navbar);
        $this->assertStringContainsString("to: { name: 'treasury-payments' }", $navbar);
        $this->assertSame(1, substr_count($navbar, "name: 'treasury-payments'"));
    }

    public function test_non_treasury_and_guest_users_cannot_receive_the_shortcut(): void
    {
        $navbar = $this->navbarSource();
        $items = $this->between($navbar, 'const accountMenuItems = computed(() => [', ']);');

        $this->assertStringContainsString('authStore.isTreasury ?', $items);
        $this->assertStringNotContainsString('authStore.isAdmin', substr($items, strpos($items, 'authStore.isTreasury ?')));
        $this->assertStringContainsString('v-if="authStore.isAuthenticated"', $navbar);
        $this->assertStringContainsString('v-else to="/login"', $navbar);
    }

    public function test_administrative_shortcut_and_menu_closing_behavior_remain_intact(): void
    {
        $navbar = $this->navbarSource();

        $this->assertStringContainsString("...(authStore.isAdmin ? [{ label: 'Panel administrativo'", $navbar);
        $this->assertStringContainsString("to: '/admin/dashboard'", $navbar);
        $this->assertStringContainsString('accountMenuRef.value?.hide()', $navbar);
        $this->assertStringContainsString('watch(() => route.fullPath, closeMenus);', $navbar);
    }

    private function navbarSource(): string
    {
        return file_get_contents(resource_path('js/components/Navbar.vue'));
    }

    private function between(string $contents, string $start, string $end): string
    {
        $offset = strpos($contents, $start);
        $this->assertNotFalse($offset, "No se encontró el inicio: $start");
        $endOffset = strpos($contents, $end, $offset);
        $this->assertNotFalse($endOffset, "No se encontró el final: $end");

        return substr($contents, $offset, $endOffset - $offset);
    }
}
