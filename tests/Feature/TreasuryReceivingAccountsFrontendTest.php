<?php

namespace Tests\Feature;

use Tests\TestCase;

class TreasuryReceivingAccountsFrontendTest extends TestCase
{
    public function test_static_administrative_receiving_accounts_interface_contract(): void
    {
        $view = file_get_contents(resource_path('js/views/admin/TreasuryReceivingAccounts.vue'));
        $api = file_get_contents(resource_path('js/api.js'));
        $router = file_get_contents(resource_path('js/router.js'));
        $sidebar = file_get_contents(resource_path('js/layouts/AdminLayout.vue'));
        // Esta es una prueba estática del contrato; no sustituye un smoke interactivo.
        foreach (['listError', 'actionError', 'formError', 'fieldErrors', 'qrError', 'previewError', 'useToast', 'toast.add', '/default', '{ is_default: true }', 'handleQrSelection', 'event?.target?.files?.[0]', 'hasSelectedQrFile', 'globalThis.File', 'selectedQrFile.value', 'new FormData()', "formData.append('qr', selectedQrFile.value)", 'submitQr', 'Archivo seleccionado:', 'Subir QR', 'metric-card', 'filter-card', 'Cuenta', 'Destino', 'Configuración', 'Cargar QR', 'Reemplazar QR', 'Debes cargar un QR antes de activar esta cuenta', 'QR pendiente', 'Guardar y continuar', 'qrModal', 'role="dialog"', 'aria-modal="true"', 'mobile-list', 'AdminPageHeader', 'AdminStatusBadge', 'AdminIconButton', 'AdminEmptyState', 'AdminPagination', '/admin/treasury/receiving-accounts', 'api.get(', 'api.post(', 'api.put(', 'api.patch(', "responseType: 'blob'", 'URL.createObjectURL', 'URL.revokeObjectURL', 'overflow-x-auto', 'current_page', 'last_page', 'closeModal', 'delete data.code', 'delete data.channel', 'qr_path', 'qr_disk'] as $value) {
            $this->assertStringContainsString($value, $view);
        }
        $this->assertStringNotContainsString('filters.search', $view);
        $this->assertStringNotContainsString('localStorage', $view);
        $this->assertStringNotContainsString('sessionStorage', $view);
        $this->assertStringNotContainsString('console.log', $view);
        $this->assertStringNotContainsString('JSON.stringify(formData)', $view);
        $this->assertStringNotContainsString("'Content-Type': 'multipart/form-data'", $view);
        $template = explode('<script setup>', $view, 2)[0];
        $this->assertStringContainsString('!hasSelectedQrFile', $template);
        $this->assertStringNotContainsString('instanceof File', $template);
        $this->assertStringNotContainsString('globalThis.File', $template);
        $this->assertStringContainsString('config.data instanceof FormData', $api);
        $this->assertStringContainsString("delete config.headers['Content-Type']", $api);
        $this->assertStringContainsString('requiresAdmin: true', $router);
        $this->assertStringContainsString('Cuentas receptoras', $sidebar);
        $this->assertStringNotContainsString('v-html', $view);
    }
}
