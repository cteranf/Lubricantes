<?php

namespace Tests\Feature;

use Tests\TestCase;

class TreasuryReceivingAccountsFrontendTest extends TestCase
{
    public function test_static_administrative_receiving_accounts_interface_contract(): void
    {
        $view = file_get_contents(resource_path('js/views/admin/TreasuryReceivingAccounts.vue'));
        $router = file_get_contents(resource_path('js/router.js'));
        $sidebar = file_get_contents(resource_path('js/layouts/AdminLayout.vue'));
        // Esta es una certificación estática del contrato del cliente, no un smoke de navegador.
        foreach (['AdminPageHeader', 'AdminStatusBadge', 'AdminIconButton', 'AdminEmptyState', 'AdminPagination', '/admin/treasury/receiving-accounts', 'api.get(', 'api.post(', 'api.put(', 'api.patch(', "responseType: 'blob'", 'URL.createObjectURL', 'URL.revokeObjectURL', 'FormData', "data.append('qr'", 'overflow-x-auto', 'current_page', 'last_page', 'closeModal', 'delete data.code', 'delete data.channel', 'qr_path', 'qr_disk'] as $value) {
            $this->assertStringContainsString($value, $view);
        }
        $this->assertStringNotContainsString('filters.search', $view);
        $this->assertStringNotContainsString('localStorage', $view);
        $this->assertStringNotContainsString('sessionStorage', $view);
        $this->assertStringNotContainsString('console.log', $view);
        $this->assertStringContainsString('requiresAdmin: true', $router);
        $this->assertStringContainsString('Cuentas receptoras', $sidebar);
        $this->assertStringNotContainsString('v-html', $view);
    }
}
