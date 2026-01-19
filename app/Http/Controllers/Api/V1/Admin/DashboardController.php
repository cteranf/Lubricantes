<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // Get filter parameters
        $year = $request->input('year', date('Y'));
        $month = $request->input('month'); // Optional month filter
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        // Build base query for orders
        $ordersQuery = Order::where('status', '!=', 'canceled');

        // Apply date filters
        if ($startDate && $endDate) {
            $ordersQuery->whereBetween('created_at', [$startDate, $endDate]);
        } elseif ($month) {
            $ordersQuery->whereYear('created_at', $year)
                ->whereMonth('created_at', $month);
        } else {
            $ordersQuery->whereYear('created_at', $year);
        }

        // Sales data for chart (monthly breakdown)
        $salesData = (clone $ordersQuery)
            ->selectRaw('MONTH(created_at) as month, SUM(total) as total')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // Top products with date filter
        $topProducts = Product::withCount([
            'orderItems as total_sold' => function ($query) use ($startDate, $endDate, $year, $month) {
                $query->select(\DB::raw('sum(quantity)'));
                $query->whereHas('order', function ($q) use ($startDate, $endDate, $year, $month) {
                    $q->where('status', '!=', 'canceled');
                    if ($startDate && $endDate) {
                        $q->whereBetween('created_at', [$startDate, $endDate]);
                    } elseif ($month) {
                        $q->whereYear('created_at', $year)
                            ->whereMonth('created_at', $month);
                    } else {
                        $q->whereYear('created_at', $year);
                    }
                });
            }
        ])
            ->orderByDesc('total_sold')
            ->limit(5)
            ->get();

        return response()->json([
            'total_sales' => (clone $ordersQuery)->sum('total'),
            'orders_count' => (clone $ordersQuery)->count(),
            'products_count' => Product::count(),
            'client_count' => \App\Models\User::where('role', 'customer')->count(),
            'low_stock_products' => Product::where('stock', '<', 5)->get(),
            'sales_chart' => $salesData,
            'top_products' => $topProducts,
            'filters' => [
                'year' => $year,
                'month' => $month,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]
        ]);
    }
}
