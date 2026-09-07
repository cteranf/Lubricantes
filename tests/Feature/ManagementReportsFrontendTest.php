<?php

namespace Tests\Feature;

use Tests\TestCase;

class ManagementReportsFrontendTest extends TestCase
{
    public function test_management_reports_frontend_contract_is_present(): void
    {
        $router = file_get_contents(resource_path('js/router.js'));
        $layout = file_get_contents(resource_path('js/layouts/AdminLayout.vue'));
        $view = file_get_contents(resource_path('js/views/admin/ManagementReports.vue'));

        $this->assertStringContainsString('/admin/reports/management', $router);
        $this->assertStringContainsString('admin-management-reports', $router);
        $this->assertStringContainsString('Reportes gerenciales', $layout);
        foreach (['Ventas', 'Inventario', 'Movimientos', 'Operaciones', 'Tiempos de ciclo', 'Catálogos'] as $tab) {
            $this->assertStringContainsString($tab, $view);
        }
        foreach (['responseType: \'blob\'', 'URL.createObjectURL', 'URL.revokeObjectURL', 'loading', 'error', 'truncated', 'safeParams', 'router.replace', 'requestId', 'safeAdminRoute', 'filename\\*=UTF-8', 'finally', 'El total del pedido puede repetirse', 'Fotografía actual del inventario', 'Cola operativa actual'] as $contract) {
            $this->assertStringContainsString($contract, $view);
        }
        foreach (['inventory_movements', 'date_from', 'date_to', 'reason_code', 'stage', 'cycle_times', 'metric', 'catalogFilterMap', 'products:', 'shipping_rates:', 'catalogChanged', 'Completa las fechas'] as $contract) {
            $this->assertStringContainsString($contract, $view);
        }
        $this->assertStringNotContainsString('v-html', $view);
        $this->assertStringContainsString('/admin/reports/management/${activeSection.value}', $view);
    }
}
