<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

class ManagementCsvExportService
{
    public function download(array $report, string $section): StreamedResponse
    {
        $suffix = $section === 'catalogs' ? (string) ($report['metadata']['catalog'] ?? 'catalog') : $section;
        $suffix = preg_replace('/[^A-Za-z0-9_-]/', '', $suffix) ?: 'report';

        return response()->streamDownload(function () use ($report, $section) {
            $out = fopen('php://output', 'w');
            echo "\xEF\xBB\xBF";
            $rows = $report['rows'] ?? [];
            if ($section === 'inventory_movements') {
                $headers = ['Fecha', 'Almacén', 'Producto', 'SKU', 'Categoría', 'Marca', 'Tipo de movimiento', 'Dirección', 'Cantidad', 'Cantidad con signo', 'Tipo de referencia', 'Referencia', 'Observación'];
                $keys = ['occurred_at', 'warehouse_name', 'product_name', 'sku', 'category_name', 'brand_name', 'movement_type', 'movement_direction', 'quantity', 'signed_quantity', 'reference_type', 'reference_code', 'safe_note'];
            } elseif ($section === 'operations') {
                $headers = ['Pedido', 'Etapa', 'Modalidad', 'Estado del pedido', 'Estado logístico', 'Severidad', 'Código de razón', 'Descripción', 'Fecha relevante', 'Antigüedad', 'Fecha límite', 'Tiempo restante', 'Tiempo vencido', 'Estado del plazo', 'Ruta administrativa'];
                $keys = ['order_number', 'stage', 'delivery_type', 'order_status', 'logistics_status', 'severity', 'reason_code', 'managerial_description', 'relevant_at', 'age_seconds', 'deadline_at', 'seconds_remaining', 'overdue_seconds', 'deadline_label', 'admin_route'];
            } elseif ($section === 'cycle_times') {
                $headers = ['Métrica', 'Disponible', 'Muestras', 'Faltantes', 'Inválidos', 'Promedio (min)', 'Mediana (min)', 'Mínimo (min)', 'Máximo (min)', 'Fuente', 'Motivo'];
                $keys = ['key', 'available', 'sample_count', 'missing_count', 'invalid_count', 'average_minutes', 'median_minutes', 'minimum_minutes', 'maximum_minutes', 'source', 'reason'];
                $rows = $report['metrics'] ?? [];
            } elseif ($section === 'catalogs') {
                $catalog = $report['metadata']['catalog'] ?? 'products';
                $maps = ['products' => [['Nombre', 'SKU', 'Categoría', 'Marca', 'Precio', 'Activo'], ['name', 'sku', 'category_name', 'brand_name', 'price', 'is_active']], 'categories' => [['Nombre', 'Activo'], ['name', 'is_active']], 'brands' => [['Nombre', 'Activo'], ['name', 'is_active']], 'warehouses' => [['Código', 'Nombre', 'Sede', 'Activo'], ['code', 'name', 'branch_name', 'is_active']], 'branches' => [['Código', 'Nombre', 'Distrito', 'UBIGEO', 'Permite pickup', 'Activo'], ['code', 'name', 'district_name', 'ubigeo', 'allows_pickup', 'is_active']], 'drivers' => [['Código', 'Nombre', 'Activo', 'Disponible'], ['code', 'display_name', 'is_active', 'is_available']], 'vehicles' => [['Código', 'Tipo', 'Descripción', 'Activo', 'Disponible'], ['code', 'type', 'description', 'is_active', 'is_available']], 'departments' => [['Código', 'Nombre', 'Activo'], ['code', 'name', 'is_active']], 'provinces' => [['Código departamento', 'Departamento', 'Código', 'Nombre', 'Activo'], ['department_code', 'department_name', 'code', 'name', 'is_active']], 'districts' => [['Código departamento', 'Departamento', 'Código provincia', 'Provincia', 'Código', 'Nombre', 'UBIGEO', 'Activo'], ['department_code', 'department_name', 'province_code', 'province_name', 'code', 'name', 'ubigeo', 'is_active']], 'shipping_zones' => [['Código', 'Nombre', 'Días mínimos', 'Días máximos', 'Activo'], ['code', 'name', 'minimum_days', 'maximum_days', 'is_active']], 'shipping_rates' => [['Código zona', 'Zona', 'Distrito', 'UBIGEO', 'Monto', 'Días mínimos', 'Días máximos', 'Activo'], ['zone_code', 'zone_name', 'district_name', 'ubigeo', 'amount', 'minimum_days', 'maximum_days', 'is_active']]];
                [$headers, $keys] = $maps[$catalog] ?? $maps['products'];
            } elseif ($section === 'inventory') {
                $headers = ['Almacén', 'Producto', 'SKU', 'Categoría', 'Marca', 'Stock físico', 'Stock reservado', 'Disponibilidad matemática', 'Disponibilidad vendible', 'Déficit de reserva', 'Inconsistencia', 'Fecha de corte'];
                $keys = ['warehouse_name', 'product_name', 'sku', 'category_name', 'brand_name', 'physical', 'reserved', 'raw_available', 'sellable_available', 'reservation_deficit', 'has_inconsistency', 'snapshot_at'];
            } else {
                $headers = ['Fecha reconocida', 'Pedido', 'Modalidad', 'Método de pago', 'Estado de pago', 'Producto', 'SKU', 'Categoría', 'Marca', 'Cantidad', 'Precio unitario', 'Subtotal de línea', 'Total del pedido'];
                $keys = ['recognized_at', 'order_number', 'delivery_type', 'payment_method', 'payment_status', 'product_name', 'sku', 'category_name', 'brand_name', 'quantity', 'historical_unit_price', 'line_subtotal', 'order_total'];
            }
            fputcsv($out, $headers, config('management_reports.csv_delimiter', ','));
            foreach ($rows as $row) {
                fputcsv($out, array_map(function ($key) use ($row) {
                    $value = $row[$key] ?? '';
                    if (! is_scalar($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                    }
                    if (is_string($value) && preg_match('/^\s*[=+\-@\t\r]/', $value)) {
                        $value = "'".$value;
                    }

                    return $value;
                }, $keys), config('management_reports.csv_delimiter', ','));
            } fclose($out);
        }, 'reporte-'.$suffix.'-'.now()->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
