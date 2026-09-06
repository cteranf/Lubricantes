<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ManagementDashboardService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, ManagementDashboardService $dashboard)
    {
        $data = $request->validate([
            'period' => 'nullable|in:today,last_7_days,last_30_days,this_month,previous_month,this_year,custom',
            'section' => 'nullable|in:summary,sales,inventory,operations',
            'year' => 'nullable|integer|min:2000|max:2100',
            'month' => 'nullable|integer|min:1|max:12',
            'date_from' => 'nullable|date|required_with:date_to',
            'date_to' => 'nullable|date|required_with:date_from|after_or_equal:date_from',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'delivery_type' => 'nullable|in:delivery,pickup',
            'payment_method' => 'nullable|string|max:40',
            'status' => 'nullable|string|max:40',
        ]);
        if (($data['period'] ?? null) === 'custom' && empty($data['date_from'])) {
            abort(422, 'El periodo personalizado requiere date_from y date_to.');
        }
        if (! empty($data['date_from']) && ! empty($data['date_to']) && Carbon::parse($data['date_from'])->diffInDays(Carbon::parse($data['date_to'])) > 366) {
            abort(422, 'El rango máximo interactivo es de 366 días.');
        }

        if (! empty($data['section'])) {
            $section = $data['section'];
            $allowed = ['period', 'date_from', 'date_to', 'delivery_type', 'category_id', 'brand_id', 'payment_method', 'warehouse_id', 'branch_id', 'status', 'year', 'month', 'section'];
            $applied = array_replace(['period' => 'this_year', 'date_from' => null, 'date_to' => null, 'delivery_type' => null], array_intersect_key($data, array_flip($allowed)));
            $sectionData = $dashboard->section($section, $data);

            return response()->json(array_merge(['section' => $section, 'generated_at' => now()->toIso8601String(), 'applied_filters' => $applied, 'capabilities' => ['persistent_payment_preferences' => \Illuminate\Support\Facades\Schema::hasTable('payment_preferences'), 'payment_webhook_events' => \Illuminate\Support\Facades\Schema::hasTable('payment_webhook_events'), 'payment_provider_fields' => false, 'cycle_times_picking' => true, 'cycle_times_packing' => true, 'delivery_cycle_times' => true, 'pickup_cycle_times' => true], 'data' => $sectionData], $sectionData));
        }

        return response()->json($dashboard->legacyDashboard($data));
    }
}
