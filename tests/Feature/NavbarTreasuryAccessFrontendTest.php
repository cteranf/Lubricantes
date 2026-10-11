<?php

namespace Tests\Feature;

use Tests\TestCase;

class NavbarTreasuryAccessFrontendTest extends TestCase
{
    public function test_treasury_shortcut_is_role_scoped_and_targets_the_named_route(): void
    {
        $navbar = $this->navbarSource();
        $shortcut = $this->treasuryShortcut($navbar);

        $this->assertStringContainsString('v-if="authStore.isTreasury"', $shortcut);
        $this->assertStringContainsString(':to="{ name: \'treasury-payments\' }"', $shortcut);
        $this->assertStringContainsString('Panel de tesorería', $shortcut);
        $this->assertSame(1, substr_count($navbar, 'Panel de tesorería'));
        $this->assertSame(1, substr_count($navbar, "name: 'treasury-payments'"));
    }

    public function test_non_treasury_and_guest_users_cannot_receive_the_shortcut(): void
    {
        $navbar = $this->navbarSource();
        $authenticatedMenu = $this->between(
            $navbar,
            '<div v-if="authStore.isAuthenticated" class="relative">',
            '<router-link v-else to="/login"',
        );
        $shortcut = $this->treasuryShortcut($navbar);

        $this->assertStringContainsString('v-if="authStore.isTreasury"', $shortcut);
        $this->assertStringNotContainsString('authStore.isAdmin', $shortcut);
        $this->assertStringContainsString($shortcut, $authenticatedMenu);
    }

    public function test_administrative_shortcut_and_menu_closing_behavior_remain_intact(): void
    {
        $navbar = $this->navbarSource();

        $this->assertStringContainsString(
            '<router-link v-if="authStore.isAdmin" to="/admin/dashboard" class="dropdown-link font-bold text-blue-700">Panel administrativo</router-link>',
            $navbar,
        );
        $this->assertStringContainsString('const closeMenus = () => { mobileMenuOpen.value = false; accountMenuOpen.value = false; };', $navbar);
        $this->assertStringContainsString('watch(() => route.fullPath, closeMenus);', $navbar);
    }

    private function navbarSource(): string
    {
        return file_get_contents(resource_path('js/components/Navbar.vue'));
    }

    private function treasuryShortcut(string $navbar): string
    {
        $match = preg_match(
            '/<router-link v-if="authStore\.isTreasury"[^>]*>Panel de tesorería<\/router-link>/',
            $navbar,
            $matches,
        );

        $this->assertSame(1, $match, 'El acceso Treasury debe existir una sola vez con su guard explícito.');

        return $matches[0];
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
