<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryVehicle;
use App\Services\ManagementReportService;
use Illuminate\Http\Request;

class ManagementReportController extends Controller
{
    public function __construct(private ManagementReportService $reports, private \App\Services\ManagementCsvExportService $csv) {}

    public function show(Request $request, string $section)
    {
        abort_unless(in_array($section, ['sales', 'inventory', 'inventory_movements', 'operations', 'cycle_times', 'catalogs'], true), 404);
        $this->validateFilters($request);
        if ($section === 'catalogs') {
            $request->validate(['catalog' => 'required|string']);
            $this->validateCatalogFilters($request);
        }

        return response()->json($this->reports->build($section, $request->only(['period', 'date_from', 'date_to', 'delivery_type', 'category_id', 'brand_id', 'payment_method', 'warehouse_id', 'branch_id', 'status', 'movement_type', 'movement_direction', 'product_id', 'severity', 'reason_code', 'stage', 'overdue', 'metric', 'catalog', 'search', 'is_active', 'department_id', 'province_id', 'zone_id', 'district_id', 'type', 'is_available', 'allows_pickup'])));
    }

    public function export(Request $request, string $section)
    {
        abort_unless(in_array($section, ['sales', 'inventory', 'inventory_movements', 'operations', 'cycle_times', 'catalogs'], true), 404);
        $this->validateFilters($request);
        if ($section === 'catalogs') {
            $request->validate(['catalog' => 'required|string']);
            $this->validateCatalogFilters($request);
        }
        $report = $this->reports->build($section, $request->only(['period', 'date_from', 'date_to', 'delivery_type', 'category_id', 'brand_id', 'payment_method', 'warehouse_id', 'branch_id', 'status', 'movement_type', 'movement_direction', 'product_id', 'catalog', 'search', 'is_active', 'department_id', 'province_id', 'zone_id', 'district_id', 'type', 'is_available', 'allows_pickup']), (int) config('management_reports.export_limit', 5000));
        if (($report['metadata']['truncated'] ?? false) === true) {
            return response()->json(['message' => 'El reporte supera el límite de exportación.'], 422);
        }

        return $this->csv->download($report, $section);
    }

    private function validateFilters(Request $request): void
    {
        $request->merge(array_map(fn ($v) => $v === '' ? null : $v, $request->only(['period', 'date_from', 'date_to', 'delivery_type', 'category_id', 'brand_id', 'payment_method', 'severity', 'reason_code', 'stage', 'overdue', 'metric'])));
        $request->validate(['period' => 'nullable|in:today,last_7_days,last_30_days,this_month,previous_month,this_year,custom', 'date_from' => 'required_with:date_to|date', 'date_to' => 'required_with:date_from|date|after_or_equal:date_from', 'delivery_type' => 'nullable|in:delivery,pickup', 'category_id' => 'nullable|integer|exists:categories,id', 'brand_id' => 'nullable|integer|exists:brands,id', 'payment_method' => 'nullable|string|max:50', 'warehouse_id' => 'nullable|integer|exists:warehouses,id', 'product_id' => 'nullable|integer|exists:products,id', 'movement_type' => 'nullable|in:initial,manual_in,manual_out,transfer_in,transfer_out,sale,cancellation_return,correction', 'movement_direction' => 'nullable|in:in,out,neutral', 'severity' => 'nullable|in:critical,high,medium,low,information', 'reason_code' => 'nullable|in:PAYMENT_PENDING_OLD,PICKING_NOT_STARTED,PICKING_STALLED,PACKING_NOT_STARTED,PACKING_STALLED,READY_NOT_DISPATCHED,ASSIGNED_NOT_DISPATCHED,RESERVATION_EXPIRING,RESERVATION_EXPIRED_ACTIVE,PICKUP_WAITING,PICKUP_EXPIRING,PICKUP_EXPIRED,DELIVERY_FAILED,DELIVERY_REPROGRAM_REQUIRED', 'stage' => 'nullable|in:payment_pending,waiting_picking,picking,packing,ready,reservation,pickup,assigned,delivery', 'overdue' => 'nullable|boolean', 'metric' => 'nullable|in:picking_duration,packing_duration,assignment_to_dispatch,dispatch_to_delivery,ready_to_delivery,total_delivery_cycle,ready_to_pickup']);
        if ($request->filled('date_from') && $request->filled('date_to') && now()->parse($request->date_from)->diffInDays(now()->parse($request->date_to)) > (int) config('management_reports.max_range_days', 366)) {
            abort(response()->json(['message' => 'El rango solicitado excede el máximo permitido.'], 422));
        }
    }

    private function validateCatalogFilters(Request $request): void
    {
        $catalog = $request->input('catalog');
        if (is_string($catalog) && preg_match('/(?:^|&)catalog=([^&]*)/', (string) $request->server('QUERY_STRING'), $match)) {
            if (urldecode($match[1]) !== $catalog) {
                abort(response()->json(['message' => 'Catálogo inválido.'], 422));
            }
        }
        $allowed = [
            'products' => ['search', 'is_active', 'category_id', 'brand_id'],
            'categories' => ['search', 'is_active'],
            'brands' => ['search', 'is_active'],
            'warehouses' => ['search', 'is_active', 'branch_id'],
            'branches' => ['search', 'is_active', 'allows_pickup'],
            'drivers' => ['search', 'is_active', 'is_available'],
            'vehicles' => ['search', 'is_active', 'is_available', 'type'],
            'departments' => ['search', 'is_active'],
            'provinces' => ['search', 'is_active', 'department_id'],
            'districts' => ['search', 'is_active', 'department_id', 'province_id'],
            'shipping_zones' => ['search', 'is_active'],
            'shipping_rates' => ['search', 'is_active', 'zone_id', 'district_id'],
        ];
        $known = ['search', 'is_active', 'category_id', 'brand_id', 'branch_id', 'allows_pickup', 'is_available', 'type', 'department_id', 'province_id', 'zone_id', 'district_id'];
        $invalid = collect($known)->filter(fn ($key) => $request->filled($key) && ! in_array($key, $allowed[$catalog] ?? [], true))->values()->all();
        if ($invalid !== []) {
            abort(response()->json(['message' => 'Filtro no permitido para este catálogo.', 'filters' => $invalid], 422));
        }
        $request->validate([
            'search' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'allows_pickup' => 'nullable|boolean',
            'is_available' => 'nullable|boolean',
            'type' => 'nullable|string|in:'.implode(',', DeliveryVehicle::TYPES),
            'branch_id' => 'nullable|integer|exists:branches,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'province_id' => 'nullable|integer|exists:provinces,id',
            'zone_id' => 'nullable|integer|exists:shipping_zones,id',
            'district_id' => 'nullable|integer|exists:districts,id',
        ]);
    }
}
