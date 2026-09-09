<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminOperationsVisualFrontendTest extends TestCase
{
    public function test_operational_admin_views_preserve_their_static_contracts(): void
    {
        $views = [
            'Branches.vue' => ['/admin/branches', 'Administración de sedes', '<table', 'Código</th>', 'Sede</th>', 'Ubicación</th>', 'Pickup</th>', 'Estado</th>', 'Acciones</th>', 'scope="col"', 'overflow-x-auto', 'AdminPageHeader', 'AdminPagination', 'AdminStatusBadge', 'AdminIconButton', 'AdminEmptyState', 'Sin ubicación configurada', 'Mostrando ${pagination.from}–${pagination.to} de ${pagination.total} sedes', 'search.value || undefined', 'load(1)', 'clear', 'error.value'],
            'Warehouses.vue' => ['/admin/warehouses', 'Administración de almacenes', '<table', 'Código</th>', 'Almacén</th>', 'Sede</th>', 'Estado</th>', 'Acciones</th>', 'scope="col"', 'overflow-x-auto', 'AdminStatusBadge', 'AdminIconButton', 'AdminEmptyState', 'AdminPagination', 'search.value || undefined', 'branchId.value || undefined', 'Mostrando ${pagination.from}–${pagination.to} de ${pagination.total} almacenes'],
            'ShippingZones.vue' => ['/admin/shipping-zones', '/admin/shipping-rates', 'AdminPageHeader', 'Tarifas por distrito', '<th scope="col" class="p-3">Zona</th>', 'Distrito</th>', 'UBIGEO</th>', 'Tarifa</th>', 'Plazo</th>', 'Estado</th>', 'Acciones</th>', 'AdminStatusBadge', 'AdminIconButton', 'AdminEmptyState', 'AdminPagination', 'rateLoading', 'rateError', 'ratePagination', 'rateFilters', 'shipping_zone_id', 'is_active', 'current_page', 'last_page', 'total', 'from', 'to', 'Mostrando ${ratePagination.from}–${ratePagination.to} de ${ratePagination.total} tarifas', 'ratePagination.total', 'const pen = value => Number.isFinite(Number(value))', 'toFixed(2)', 'overflow-x-auto', 'title="Actualizar tarifas"', 'zonePagination', 'zoneError'],
            'DeliveryDrivers.vue' => ['/admin/delivery-drivers', 'Nuevo repartidor', 'Editar repartidor', 'role="dialog"', 'aria-modal="true"', 'aria-labelledby="driver-modal-title"', 'driver-modal-title', 'Cerrar modal de repartidor', 'Cuenta y repartidor', 'Información de conducción', 'Disponibilidad operativa', 'for="driver-code"', 'driver-phone', 'driver-first-name', 'driver-last-name', 'driver-document-type', 'driver-document-number', 'driver-email', 'driver-license-number', 'driver-license-category', 'driver-license-expires-at', 'driver-notes', 'driver-is-active', 'driver-is-available', 'fieldError', 'formErrors', 'Cancelar', 'Crear repartidor', 'Guardar cambios', 'Guardando…', 'max-h-[94vh]', 'max-w-3xl', 'overflow-y-auto', 'sm:grid-cols-2', 'toggleAvailability', 'toggleActive'],
            'DeliveryVehicles.vue' => ['/admin/delivery-vehicles', 'Nuevo vehículo', 'Editar vehículo', 'role="dialog"', 'aria-modal="true"', 'aria-labelledby="vehicle-modal-title"', 'vehicle-modal-title', 'Cerrar modal de vehículo', 'Identificación', 'Características', 'Vigencias', 'Disponibilidad', 'for="vehicle-code"', 'vehicle-plate-number', 'vehicle-type', 'vehicle-ownership-type', 'vehicle-brand', 'vehicle-model', 'vehicle-year', 'vehicle-color', 'vehicle-soat-expires-at', 'vehicle-inspection-expires-at', 'vehicle-is-active', 'vehicle-is-available', 'Cancelar', 'Crear vehículo', 'Guardar cambios', 'Guardando…', 'max-h-[94vh]', 'max-w-3xl', 'overflow-y-auto', 'sm:grid-cols-2', 'availability', 'statusChange'],
        ];
        foreach ($views as $file => $markers) {
            $view = file_get_contents(resource_path("js/views/admin/{$file}"));
            foreach ($markers as $marker) {
                $this->assertStringContainsString($marker, $view);
            }
            $this->assertStringNotContainsString('v-html', $view);
        }
    }
}
