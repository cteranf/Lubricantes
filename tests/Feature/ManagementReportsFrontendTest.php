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
        $params = file_get_contents(resource_path('js/utils/managementReportParams.mjs'));

        $this->assertStringContainsString('/admin/reports/management', $router);
        $this->assertStringContainsString('admin-management-reports', $router);
        $this->assertStringContainsString('buildManagementReportParams', $view);
        $this->assertStringContainsString('const params = { catalog }', $params);
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
        $this->assertStringContainsString('query: { tab: activeSection.value, ...safeParams() }', $view);
        $this->assertStringContainsString('buildManagementReportParams(activeSection.value, filters.value', $view);
        $this->assertStringNotContainsString("key === 'tab' || allowed.includes(key)", $view);
        $this->assertStringNotContainsString('v-html', $view);
        $this->assertStringContainsString('/admin/reports/management/${activeSection.value}', $view);
    }
}
