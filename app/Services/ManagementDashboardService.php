<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderDeliveryAttempt;
use App\Models\OrderHandlingProcess;
use App\Models\PaymentPreference;
use App\Models\Product;
use App\Models\WarehouseInventory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ManagementDashboardService
{
    public function legacyDashboard(array $filters): array
    {
        [$start, $end, $label] = $this->period($filters);
        $previousStart = $start->copy()->subSeconds($end->diffInSeconds($start) + 1);
        $previousEnd = $start->copy()->subSecond();

        $current = $this->recognizedOrders($filters, $start, $end);
        $previous = $this->recognizedOrders($filters, $previousStart, $previousEnd);
        $created = $this->orders($filters, $start, $end);
        $sales = (clone $current)->sum('total');
        $previousSales = (clone $previous)->sum('total');
        $ordersCount = (clone $current)->count('orders.id');
        $previousOrders = (clone $previous)->count('orders.id');
        $units = $this->unitsSold($filters, $start, $end);
        $previousUnits = $this->unitsSold($filters, $previousStart, $previousEnd);
        $buyers = (clone $current)->distinct('user_id')->count('user_id');
        $previousBuyers = (clone $previous)->distinct('user_id')->count('user_id');
        $createdCount = (clone $created)->count('orders.id');
        $canceled = (clone $created)->whereIn('orders.status', ['canceled', 'rejected'])->count('orders.id');
        $pendingAttention = (clone $created)->whereIn('orders.status', ['pending', 'confirmed'])->count('orders.id');

        return [
            'period' => ['label' => $label, 'start' => $start->toIso8601String(), 'end' => $end->toIso8601String(), 'previous_start' => $previousStart->toIso8601String(), 'previous_end' => $previousEnd->toIso8601String()],
            'summary' => [
                'sales_net' => $this->metric($sales, $previousSales, 'S/'),
                'recognized_orders' => $this->metric($ordersCount, $previousOrders),
                'average_ticket' => $this->metric($ordersCount ? $sales / $ordersCount : 0, $previousOrders ? $previousSales / $previousOrders : 0, 'S/'),
                'units_sold' => $this->metric($units, $previousUnits),
                'buyers' => $this->metric($buyers, $previousBuyers),
                'cancellation_rate' => $this->metric($createdCount ? ($canceled / $createdCount) * 100 : 0, $createdCount ? 0 : 0, '%'),
                'pending_attention' => $this->metric($pendingAttention, 0),
                'reserved_stock' => $this->currentReservedStock($filters),
                'reservations_generated' => $this->metric($this->reservationsGenerated($filters, $start, $end), 0),
            ],
            'sales' => $this->salesAnalytics($filters, $start, $end),
            'inventory' => $this->inventoryAnalytics($filters, $start, $end),
            'customers' => $this->customerAnalytics($filters, $start, $end),
            'operations' => $this->operations($filters, $start, $end),
            'alerts' => $this->alerts($filters),
            // Compatibility with the original dashboard contract.
            'total_sales' => $sales,
            'orders_count' => $ordersCount,
            'products_count' => Product::count(),
            'client_count' => DB::table('users')->where('role', 'customer')->count(),
            'low_stock_products' => $this->inventoryAnalytics($filters, $start, $end)['critical'],
            'sales_chart' => $this->salesAnalytics($filters, $start, $end)['series'],
            'top_products' => $this->salesAnalytics($filters, $start, $end)['top_products'],
            'filters' => $filters,
        ];
    }

    public function section(string $section, array $filters): array
    {
        return match ($section) {
            'summary' => $this->summarySection($filters),
            'sales' => $this->salesSection($filters),
            'inventory' => $this->inventorySection($filters),
            'operations' => $this->operationsSection($filters),
            default => [],
        };
    }

    public function reportOperations(array $filters): array
    {
        [$start, $end] = $this->period($filters);

        return ['attention_queue' => $this->attentionQueue(), 'cycle_times' => $this->cycleTimes($start, $end)];
    }

    public function summarySection(array $filters): array
    {
        $legacy = $this->legacyDashboard($filters);

        return ['summary' => $legacy['summary'], 'period' => $legacy['period'], 'alerts' => $legacy['alerts']];
    }

    public function salesSection(array $filters): array
    {
        $legacy = $this->legacyDashboard($filters);

        return ['sales' => $legacy['sales'], 'period' => $legacy['period']];
    }

    public function inventorySection(array $filters): array
    {
        $legacy = $this->legacyDashboard($filters);

        return ['inventory' => $legacy['inventory'], 'period' => $legacy['period']];
    }

    public function operationsSection(array $filters): array
    {
        $legacy = $this->legacyDashboard($filters);

        return ['operations' => $legacy['operations'], 'period' => $legacy['period']];
    }

    private function period(array $filters): array
    {
        $now = Carbon::now(config('app.timezone', 'America/Lima'))->startOfDay();
        $period = $filters['period'] ?? null;
        if ($period === 'today') {
            return [$now->copy(), $now->copy()->endOfDay(), 'Hoy'];
        }
        if ($period === 'last_7_days') {
            return [$now->copy()->subDays(6), $now->copy()->endOfDay(), 'Últimos 7 días'];
        }
        if ($period === 'last_30_days') {
            return [$now->copy()->subDays(29), $now->copy()->endOfDay(), 'Últimos 30 días'];
        }
        if ($period === 'previous_month') {
            $month = $now->copy()->subMonth();

            return [$month->copy()->startOfMonth(), $month->copy()->endOfMonth(), 'Mes anterior'];
        }
        if ($period === 'this_month') {
            return [$now->copy()->startOfMonth(), $now->copy()->endOfDay(), 'Este mes'];
        }
        if ($period === 'this_year') {
            return [$now->copy()->startOfYear(), $now->copy()->endOfYear(), 'Este año'];
        }
        if (! empty($filters['date_from']) && ! empty($filters['date_to'])) {
            return [Carbon::parse($filters['date_from'], config('app.timezone'))->startOfDay(), Carbon::parse($filters['date_to'], config('app.timezone'))->endOfDay(), 'Rango personalizado'];
        }

        $year = (int) ($filters['year'] ?? $now->year);
        if (! empty($filters['month'])) {
            $from = Carbon::create($year, (int) $filters['month'], 1, 0, 0, 0, config('app.timezone'));

            return [$from, $from->copy()->endOfMonth(), $from->translatedFormat('F Y')];
        }
        $from = Carbon::create($year, 1, 1, 0, 0, 0, config('app.timezone'));

        return [$from, $from->copy()->endOfYear(), (string) $year];
    }

    private function orders(array $filters, Carbon $start, Carbon $end, string $dateColumn = 'orders.created_at'): Builder
    {
        return Order::query()->whereBetween(DB::raw($dateColumn), [$start, $end])
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->whereHas('items.warehouse', fn ($w) => $w->where('branch_id', $v)))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->whereHas('items', fn ($i) => $i->where('warehouse_id', $v)))
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->whereHas('items.product', fn ($p) => $p->where('category_id', $v)))
            ->when($filters['brand_id'] ?? null, fn ($q, $v) => $q->whereHas('items.product', fn ($p) => $p->where('brand_id', $v)))
            ->when($filters['delivery_type'] ?? null, fn ($q, $v) => $q->where('delivery_type', $v))
            ->when($filters['payment_method'] ?? null, fn ($q, $v) => $q->where('payment_method', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v));
    }

    private function recognizedOrders(array $filters, Carbon $start, Carbon $end): Builder
    {
        return $this->orders($filters, $start, $end, 'COALESCE(orders.paid_at, orders.created_at)')
            ->where('payment_status', 'approved');
    }

    private function unitsSold(array $filters, Carbon $start, Carbon $end): int
    {
        return (int) DB::query()->fromSub($this->recognizedOrders($filters, $start, $end)->select('orders.id'), 'recognized')
            ->join('order_items', 'order_items.order_id', '=', 'recognized.id')->sum('order_items.quantity');
    }

    private function salesAnalytics(array $filters, Carbon $start, Carbon $end): array
    {
        $rows = (clone $this->recognizedOrders($filters, $start, $end))->selectRaw('DATE(COALESCE(orders.paid_at, orders.created_at)) as day, SUM(orders.total) as total, COUNT(orders.id) as orders_count')->groupBy('day')->orderBy('day')->get();
        $rows = $this->completeSeries($rows, $start, $end);
        $items = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->leftJoin('products', 'products.id', '=', 'order_items.product_id')->where('orders.payment_status', 'approved')->whereBetween(DB::raw('COALESCE(orders.paid_at, orders.created_at)'), [$start, $end])
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('products.category_id', $v))->when($filters['brand_id'] ?? null, fn ($q, $v) => $q->where('products.brand_id', $v))->when($filters['delivery_type'] ?? null, fn ($q, $v) => $q->where('orders.delivery_type', $v))->when($filters['payment_method'] ?? null, fn ($q, $v) => $q->where('orders.payment_method', $v))->when($filters['status'] ?? null, fn ($q, $v) => $q->where('orders.status', $v))->select('products.id', 'products.name', 'products.sku', DB::raw('SUM(order_items.quantity) units'), DB::raw('COUNT(DISTINCT orders.id) orders_count'), DB::raw('SUM(order_items.subtotal) revenue'))->groupBy('products.id', 'products.name', 'products.sku')->orderByDesc('units')->get();

        $totals = ['sales' => (float) $this->recognizedOrders($filters, $start, $end)->sum('total'), 'orders' => (int) $this->recognizedOrders($filters, $start, $end)->count('orders.id'), 'units' => (int) $this->unitsSold($filters, $start, $end)];
        $merchandise = (float) $items->sum('revenue');

        $decorate = fn ($rows) => collect($rows)->map(function ($row) use ($merchandise) {
            $row->share_percentage = $merchandise > 0 ? round(((float) ($row->revenue ?? $row->sales ?? 0) / $merchandise) * 100, 2) : null;

            return $row;
        });
        $items = $decorate($items);

        return ['series' => $rows, 'timeline' => $rows, 'top_products' => $items->take(5)->values(), 'by_product_revenue' => $items->sortByDesc('revenue')->values(), 'top_products_by_revenue' => $items->sortByDesc('revenue')->take(10)->values(), 'top_products_by_units' => $items->sortByDesc('units')->take(10)->values(), 'product_performance' => $items->sortByDesc('revenue')->take(10)->values(), 'by_delivery_type' => $this->groupedSales('delivery_type', $filters, $start, $end), 'by_payment_method' => $this->groupedSales('payment_method', $filters, $start, $end), 'by_status' => $this->groupedSales('status', $filters, $start, $end), 'by_category' => $this->groupedItemDimension('products.category_id', 'categories.name', 'category', $filters, $start, $end), 'by_brand' => $this->groupedItemDimension('products.brand_id', 'brands.name', 'brand', $filters, $start, $end), 'by_district' => $this->groupedSnapshot('shipping_district_snapshot', 'district', $filters, $start, $end), 'totals' => $totals];
    }

    private function groupedItemDimension(string $key, string $label, string $alias, array $filters, Carbon $start, Carbon $end)
    {
        return DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->join('products', 'products.id', '=', 'order_items.product_id')->leftJoin($alias === 'category' ? 'categories' : 'brands', $key, '=', ($alias === 'category' ? 'categories.id' : 'brands.id'))->where('orders.payment_status', 'approved')->whereBetween(DB::raw('COALESCE(orders.paid_at, orders.created_at)'), [$start, $end])->when($filters['delivery_type'] ?? null, fn ($q, $v) => $q->where('orders.delivery_type', $v))->selectRaw("COALESCE($key, 0) as `key`, COALESCE($label, 'Sin clasificar') as label, SUM(order_items.subtotal) sales, COUNT(DISTINCT orders.id) orders_count, SUM(order_items.quantity) units")->groupBy('key', 'label')->orderByDesc('sales')->get();
    }

    private function groupedSnapshot(string $column, string $alias, array $filters, Carbon $start, Carbon $end)
    {
        return (clone $this->recognizedOrders($filters, $start, $end))->selectRaw("COALESCE($column, 'Sin clasificar') as label, SUM(total) sales, COUNT(orders.id) orders_count")->groupBy($column)->orderByDesc('sales')->get();
    }

    private function completeSeries($rows, Carbon $start, Carbon $end): array
    {
        $days = $start->diffInDays($end) + 1;
        $step = $days > 180 ? 'month' : ($days > 31 ? 'week' : 'day');
        $cursor = $start->copy()->startOf($step);
        $limit = $end->copy()->startOf($step);
        $indexed = collect($rows)->keyBy(fn ($row) => (string) $row->day);
        $result = [];
        while ($cursor <= $limit) {
            $key = $cursor->format($step === 'month' ? 'Y-m' : ($step === 'week' ? 'o-W' : 'Y-m-d'));
            $row = $indexed->get($cursor->format('Y-m-d')) ?? $indexed->get($key);
            $result[] = ['period' => $key, 'label' => $step === 'month' ? $cursor->translatedFormat('M Y') : $cursor->format('d/m'), 'sales' => number_format((float) ($row->total ?? 0), 2, '.', ''), 'orders' => (int) ($row->orders_count ?? 0)];
            $cursor->add(1, $step);
        }

        return $result;
    }

    private function groupedSales(string $column, array $filters, Carbon $start, Carbon $end)
    {
        return (clone $this->recognizedOrders($filters, $start, $end))->select($column, DB::raw('SUM(total) total'), DB::raw('COUNT(orders.id) orders_count'))->groupBy($column)->orderByDesc('total')->get();
    }

    private function inventoryAnalytics(array $filters, Carbon $start, Carbon $end): array
    {
        $base = WarehouseInventory::query()->join('warehouses', 'warehouses.id', '=', 'warehouse_inventories.warehouse_id')->join('products', 'products.id', '=', 'warehouse_inventories.product_id')->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('warehouses.branch_id', $v))->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_inventories.warehouse_id', $v));
        $signedDifference = 'CAST(warehouse_inventories.quantity AS SIGNED) - CAST(warehouse_inventories.reserved_quantity AS SIGNED)';
        $signedDeficit = 'CAST(warehouse_inventories.reserved_quantity AS SIGNED) - CAST(warehouse_inventories.quantity AS SIGNED)';
        $availableExpression = DB::connection()->getDriverName() === 'sqlite' ? 'MAX('.$signedDifference.', 0)' : 'GREATEST('.$signedDifference.', 0)';
        $deficitExpression = DB::connection()->getDriverName() === 'sqlite' ? 'MAX('.$signedDeficit.', 0)' : 'GREATEST('.$signedDeficit.', 0)';
        $rows = (clone $base)->select('warehouse_inventories.product_id', 'products.name', 'products.sku', 'products.is_active', 'warehouse_inventories.warehouse_id', 'warehouses.name as warehouse_name', 'warehouse_inventories.quantity as physical', 'warehouse_inventories.reserved_quantity as reserved', DB::raw('('.$signedDifference.') raw_available'), DB::raw($availableExpression.' sellable_available'), DB::raw($deficitExpression.' reservation_deficit'), DB::raw('(warehouse_inventories.reserved_quantity > warehouse_inventories.quantity) has_inconsistency'))->orderBy('sellable_available')->limit(100)->get();
        $reserved = (clone $base)->sum('warehouse_inventories.reserved_quantity');
        $physical = (clone $base)->sum('warehouse_inventories.quantity');

        $movementBase = DB::table('inventory_movements')->whereBetween('created_at', [$start, $end])->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v));
        $outflow = (clone $movementBase)->whereIn('type', InventoryMovement::OUTGOING)->select('product_id', DB::raw('SUM(quantity) units'))->groupBy('product_id')->orderByDesc('units')->limit(10)->get();
        $byWarehouse = (clone $base)->select('warehouse_id', 'warehouses.name as warehouse_name', DB::raw('SUM(quantity) physical'), DB::raw('SUM(reserved_quantity) reserved'))->groupBy('warehouse_id', 'warehouses.name')->get()->map(function ($row) {
            $row->available = max(0, (int) $row->physical - (int) $row->reserved);

            return $row;
        });
        $statuses = InventoryReservation::select('status', DB::raw('COUNT(*) reservations'), DB::raw('SUM(quantity) units'))->groupBy('status')->get();

        $inconsistent = $rows->where('has_inconsistency', 1);

        return ['physical' => (int) $physical, 'available' => max(0, (int) $physical - (int) $reserved), 'reserved' => (int) $reserved, 'reservation_deficit' => (int) $inconsistent->sum('reservation_deficit'), 'inconsistent_count' => $inconsistent->count(), 'critical' => $rows->where('sellable_available', '<=', 0)->values(), 'table' => $rows, 'product_inventory' => $rows, 'by_warehouse' => $byWarehouse, 'highest_outflow' => $outflow, 'movement_types' => (clone $movementBase)->select('type', DB::raw('COUNT(*) movements'), DB::raw('SUM(quantity) units'))->groupBy('type')->get(), 'reservation_statuses' => $statuses, 'no_movement_products' => [], 'metadata' => ['as_of' => now()->toIso8601String(), 'period_start' => $start->toIso8601String(), 'period_end' => $end->toIso8601String()]];
    }

    private function currentReservedStock(array $filters): int
    {
        return (int) WarehouseInventory::when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))->sum('reserved_quantity');
    }

    private function reservationsGenerated(array $filters, Carbon $start, Carbon $end): int
    {
        return (int) InventoryReservation::whereBetween('created_at', [$start, $end])->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))->sum('quantity');
    }

    private function customerAnalytics(array $filters, Carbon $start, Carbon $end): array
    {
        $recognized = $this->recognizedOrders($filters, $start, $end);

        return ['buyers' => (clone $recognized)->distinct('user_id')->count('user_id'), 'new_buyers' => (clone $recognized)->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('orders as previous')->whereColumn('previous.user_id', 'orders.user_id')->where('previous.payment_status', 'approved')->whereColumn('previous.created_at', '<', 'orders.created_at'))->distinct('user_id')->count('user_id'), 'by_district' => (clone $recognized)->select('shipping_district_snapshot as district', DB::raw('SUM(total) total'))->groupBy('shipping_district_snapshot')->orderByDesc('total')->limit(10)->get()];
    }

    private function operations(array $filters, Carbon $start, Carbon $end): array
    {
        $current = Order::query();
        $backlog = ['pending_payment' => (clone $current)->whereIn('payment_status', ['pending', 'rejected'])->count(), 'preparing' => (clone $current)->where('fulfillment_status', 'preparing')->count(), 'ready' => (clone $current)->where('fulfillment_status', 'ready')->count(), 'in_transit' => DB::table('order_deliveries')->whereIn('status', [OrderDelivery::DISPATCHED, OrderDelivery::OUT_FOR_DELIVERY])->count(), 'awaiting_pickup' => DB::table('order_deliveries')->where('status', OrderDelivery::AWAITING_PICKUP)->count(), 'failed_deliveries' => DB::table('order_delivery_attempts')->where('status', OrderDeliveryAttempt::FAILED)->count()];
        $flow = ['created' => $this->orders($filters, $start, $end)->distinct('orders.id')->count('orders.id'), 'recognized' => $this->recognizedOrders($filters, $start, $end)->distinct('orders.id')->count('orders.id'), 'preparing' => $this->orders($filters, $start, $end)->whereNotNull('preparing_at')->distinct('orders.id')->count('orders.id'), 'completed' => $this->orders($filters, $start, $end)->whereIn('status', ['delivered'])->distinct('orders.id')->count('orders.id')];

        $attention = collect($this->attentionQueue());
        $now = now();
        $aging = ['under_2h' => 0, 'from_2h_to_6h' => 0, 'from_6h_to_12h' => 0, 'from_12h_to_24h' => 0, 'from_1d_to_2d' => 0, 'over_2d' => 0];
        foreach ($attention as $item) {
            $h = ($item['age_seconds'] ?? 0) / 3600;
            $key = $h < 2 ? 'under_2h' : ($h < 6 ? 'from_2h_to_6h' : ($h < 12 ? 'from_6h_to_12h' : ($h < 24 ? 'from_12h_to_24h' : ($h < 48 ? 'from_1d_to_2d' : 'over_2d'))));
            $aging[$key]++;
        }
        $totalAging = max(1, array_sum($aging));
        $agingBuckets = collect($aging)->map(fn ($v, $k) => ['key' => $k, 'label' => $k, 'orders' => $v, 'percentage' => round($v / $totalAging * 100, 2)])->values();

        return ['current_backlog' => $backlog, 'period_flow' => $flow, 'fulfillment_stages' => $backlog, 'delivery_methods' => (clone $current)->select('delivery_type', DB::raw('COUNT(*) orders'))->groupBy('delivery_type')->get(), 'delivery_outcomes' => DB::table('order_deliveries')->select('status', DB::raw('COUNT(*) orders'))->groupBy('status')->get(), 'aging_buckets' => $agingBuckets, 'cycle_times' => $this->cycleTimes($start, $end), 'attention_queue' => ['total' => $attention->count(), 'shown' => min(20, $attention->count()), 'has_more' => $attention->count() > 20, 'generated_at' => now()->toIso8601String(), 'items' => $attention->take(20)->values()], 'metadata' => ['as_of' => $now->toIso8601String(), 'period_start' => $start->toIso8601String(), 'period_end' => $end->toIso8601String()], 'pending_payment' => $backlog['pending_payment'], 'preparing' => $backlog['preparing'], 'ready' => $backlog['ready'], 'in_transit' => $backlog['in_transit'], 'awaiting_pickup' => $backlog['awaiting_pickup'], 'failed_deliveries' => $backlog['failed_deliveries']];
    }

    private function cycleTimes(Carbon $start, Carbon $end): array
    {
        $processes = OrderHandlingProcess::query()->get(['order_id', 'picking_started_at', 'picking_completed_at', 'packing_started_at', 'packing_completed_at']);
        $metrics = [];
        foreach (['picking_duration' => ['picking_started_at', 'picking_completed_at', 'Duración de picking'], 'packing_duration' => ['packing_started_at', 'packing_completed_at', 'Duración de packing']] as $key => [$from,$to,$label]) {
            $metrics[$key] = $this->cycleMetric($key, $label, $processes, $from, $to, $start, $end, 'order_handling_processes');
        }

        $deliveries = OrderDelivery::query()->get(['id', 'order_id', 'method', 'created_at', 'assigned_at', 'started_at', 'dispatched_at', 'delivered_at', 'picked_up_at']);
        $orders = Order::query()->whereIn('id', $deliveries->pluck('order_id'))->get(['id', 'ready_at', 'ready_for_pickup_at'])->keyBy('id');
        $deliveryMetrics = [
            'assignment_to_dispatch' => ['assigned_at', 'dispatched_at', 'Asignación → despacho', [OrderDelivery::OWN_DELIVERY, OrderDelivery::EXTERNAL_COURIER]],
            'dispatch_to_delivery' => ['dispatched_at', 'delivered_at', 'Despacho → entrega', [OrderDelivery::OWN_DELIVERY, OrderDelivery::EXTERNAL_COURIER]],
            'ready_to_delivery' => ['ready_at', 'delivered_at', 'Listo → entrega', [OrderDelivery::OWN_DELIVERY, OrderDelivery::EXTERNAL_COURIER]],
            'total_delivery_cycle' => ['started_at', 'delivered_at', 'Ciclo total de delivery', [OrderDelivery::OWN_DELIVERY, OrderDelivery::EXTERNAL_COURIER]],
            'ready_to_pickup' => ['ready_for_pickup_at', 'picked_up_at', 'Listo → recojo', [OrderDelivery::STORE_PICKUP]],
        ];
        foreach ($deliveryMetrics as $key => [$from, $to, $label, $methods]) {
            $rows = $deliveries->filter(fn ($d) => in_array($d->method, $methods, true))->map(function ($d) use ($orders, $from) {
                $order = $orders->get($d->order_id);
                $d->{$from} = $d->{$from} ?? ($order?->{$from});

                return $d;
            });
            $metrics[$key] = $this->cycleMetric($key, $label, $rows, $from, $to, $start, $end, 'order_deliveries/orders');
        }

        return $metrics;
    }

    private function attentionQueue(): array
    {
        $now = now();
        $items = [];
        $orders = Order::query()->whereNotIn('status', ['delivered', 'picked_up', 'canceled'])->get(['id', 'status', 'payment_status', 'fulfillment_status', 'delivery_type', 'created_at', 'paid_at', 'preparing_at', 'ready_at']);
        foreach ($orders as $order) {
            $add = function (string $code, string $stage, string $label, string $severity, string $reason, $at, string $action = 'Abrir pedido', array $extra = []) use (&$items, $order, $now): void {
                $seconds = $at ? $at->diffInSeconds($now, false) : null;
                $items[] = array_merge(['order_id' => $order->id, 'order_number' => (string) $order->id, 'stage' => $stage, 'stage_label' => $label, 'severity' => $severity, 'severity_rank' => ['critical' => 4, 'high' => 3, 'medium' => 2, 'information' => 1][$severity], 'reason_code' => $code, 'reason' => $reason, 'stage_started_at' => $at?->toIso8601String(), 'age_seconds' => $seconds, 'age_label' => $seconds === null ? 'Sin fecha disponible' : $at->diffForHumans($now, true), 'delivery_type' => $order->delivery_type, 'action_label' => $action, 'action_route' => '/admin/orders/'.$order->id, 'deadline_at' => null, 'is_overdue' => false, 'deadline_at' => null, 'seconds_remaining' => null, 'overdue_seconds' => null, 'deadline_label' => null], $extra);
            };
            if (in_array($order->payment_status, ['pending', 'rejected'], true) && $order->created_at && $order->created_at->diffInMinutes($now) >= config('management_dashboard.payment_pending_warning_minutes')) {
                $add('PAYMENT_PENDING_OLD', 'payment_pending', 'Pago pendiente', 'medium', 'El pago requiere revisión.', $order->created_at, 'Abrir pago');
            }
            if ($order->fulfillment_status === 'preparing' && ! $order->preparing_at) {
                $add('PICKING_NOT_STARTED', 'waiting_picking', 'Esperando picking', 'high', 'El pedido todavía no inicia picking.', $order->paid_at ?: $order->created_at, 'Abrir preparación');
            }
            if ($order->ready_at && ! OrderDelivery::where('order_id', $order->id)->whereIn('status', [OrderDelivery::DISPATCHED, OrderDelivery::DELIVERED])->exists()) {
                $add('READY_NOT_DISPATCHED', 'ready', 'Listo sin despacho', 'high', 'El pedido listo aún no se despacha.', $order->ready_at, 'Abrir delivery');
            }
        }
        $handling = OrderHandlingProcess::whereIn('order_id', $orders->pluck('id'))->get()->keyBy('order_id');
        foreach ($orders as $order) {
            $p = $handling->get($order->id);
            if (! $p) {
                continue;
            }
            if ($p->picking_started_at && ! $p->picking_completed_at && $p->picking_started_at->diffInMinutes($now) >= config('management_dashboard.picking_stalled_minutes')) {
                $items[] = ['order_id' => $order->id, 'order_number' => (string) $order->id, 'stage' => 'picking', 'stage_label' => 'Picking detenido', 'severity' => 'high', 'severity_rank' => 3, 'reason_code' => 'PICKING_STALLED', 'reason' => 'El picking iniciado supera el umbral operativo.', 'stage_started_at' => $p->picking_started_at->toIso8601String(), 'age_seconds' => $p->picking_started_at->diffInSeconds($now), 'age_label' => $p->picking_started_at->diffForHumans($now, true), 'delivery_type' => $order->delivery_type, 'action_label' => 'Abrir preparación', 'action_route' => '/admin/orders/'.$order->id, 'deadline_at' => null, 'is_overdue' => false];
            }
            if ($p->picking_completed_at && ! $p->packing_started_at && $p->picking_completed_at->diffInMinutes($now) >= config('management_dashboard.packing_not_started_minutes')) {
                $items[] = ['order_id' => $order->id, 'order_number' => (string) $order->id, 'stage' => 'packing', 'stage_label' => 'Packing sin iniciar', 'severity' => 'medium', 'severity_rank' => 2, 'reason_code' => 'PACKING_NOT_STARTED', 'reason' => 'El picking terminó y packing aún no inicia.', 'stage_started_at' => $p->picking_completed_at->toIso8601String(), 'age_seconds' => $p->picking_completed_at->diffInSeconds($now), 'age_label' => $p->picking_completed_at->diffForHumans($now, true), 'delivery_type' => $order->delivery_type, 'action_label' => 'Abrir preparación', 'action_route' => '/admin/orders/'.$order->id, 'deadline_at' => null, 'is_overdue' => false];
            }
            if ($p->packing_started_at && ! $p->packing_completed_at && $p->packing_started_at->diffInMinutes($now) >= config('management_dashboard.packing_stalled_minutes')) {
                $items[] = ['order_id' => $order->id, 'order_number' => (string) $order->id, 'stage' => 'packing', 'stage_label' => 'Packing detenido', 'severity' => 'high', 'severity_rank' => 3, 'reason_code' => 'PACKING_STALLED', 'reason' => 'El packing iniciado supera el umbral operativo.', 'stage_started_at' => $p->packing_started_at->toIso8601String(), 'age_seconds' => $p->packing_started_at->diffInSeconds($now), 'age_label' => $p->packing_started_at->diffForHumans($now, true), 'delivery_type' => $order->delivery_type, 'action_label' => 'Abrir preparación', 'action_route' => '/admin/orders/'.$order->id, 'deadline_at' => null, 'is_overdue' => false];
            }
        }
        foreach (InventoryReservation::where('status', InventoryReservation::ACTIVE)->whereNotNull('expires_at')->get(['order_id', 'expires_at'])->groupBy('order_id') as $orderId => $reservations) {
            $order = $orders->firstWhere('id', (int) $orderId);
            if (! $order) {
                continue;
            }
            $deadline = $reservations->sortBy('expires_at')->first()->expires_at;
            if ($deadline->isPast()) {
                $items[] = ['order_id' => $order->id, 'order_number' => (string) $order->id, 'stage' => 'reservation', 'stage_label' => 'Reserva vencida', 'severity' => 'critical', 'severity_rank' => 4, 'reason_code' => 'RESERVATION_EXPIRED_ACTIVE', 'reason' => 'Existe una reserva activa vencida.', 'stage_started_at' => null, 'age_seconds' => null, 'age_label' => 'Sin fecha disponible', 'delivery_type' => $order->delivery_type, 'action_label' => 'Abrir inventario', 'action_route' => '/admin/inventory', 'deadline_at' => $deadline->toIso8601String(), 'is_overdue' => true, 'seconds_remaining' => null, 'overdue_seconds' => $deadline->diffInSeconds($now), 'deadline_label' => 'Vencida'];
            } elseif ($deadline->diffInMinutes($now) <= config('management_dashboard.reservation_expiring_minutes', 120)) {
                $items[] = ['order_id' => $order->id, 'order_number' => (string) $order->id, 'stage' => 'reservation', 'stage_label' => 'Reserva próxima a vencer', 'severity' => 'medium', 'severity_rank' => 2, 'reason_code' => 'RESERVATION_EXPIRING', 'reason' => 'La reserva vencerá pronto.', 'stage_started_at' => null, 'age_seconds' => null, 'age_label' => 'Sin fecha disponible', 'delivery_type' => $order->delivery_type, 'action_label' => 'Abrir inventario', 'action_route' => '/admin/inventory', 'deadline_at' => $deadline->toIso8601String(), 'is_overdue' => false, 'seconds_remaining' => $now->diffInSeconds($deadline), 'overdue_seconds' => null, 'deadline_label' => 'Vence pronto'];
            }
        }
        foreach (Order::whereNotNull('ready_for_pickup_at')->whereNotNull('pickup_deadline_at')->whereNull('picked_up_at')->whereNotIn('status', ['canceled', 'picked_up'])->get(['id', 'delivery_type', 'ready_for_pickup_at', 'pickup_deadline_at']) as $pickup) {
            if ($pickup->delivery_type !== 'pickup') {
                continue;
            }
            $deadline = $pickup->pickup_deadline_at;
            $base = ['order_id' => $pickup->id, 'order_number' => (string) $pickup->id, 'stage' => 'pickup', 'stage_started_at' => $pickup->ready_for_pickup_at->toIso8601String(), 'age_seconds' => $pickup->ready_for_pickup_at->diffInSeconds($now), 'age_label' => $pickup->ready_for_pickup_at->diffForHumans($now, true), 'delivery_type' => 'pickup', 'action_label' => 'Abrir pickup', 'action_route' => '/admin/orders/'.$pickup->id, 'deadline_at' => $deadline->toIso8601String()];
            if ($deadline->isPast()) {
                $items[] = array_merge($base, ['stage_label' => 'Recojo vencido', 'severity' => 'critical', 'severity_rank' => 4, 'reason_code' => 'PICKUP_EXPIRED', 'reason' => 'El plazo de recojo ha vencido.', 'is_overdue' => true, 'seconds_remaining' => null, 'overdue_seconds' => $deadline->diffInSeconds($now), 'deadline_label' => 'Vencida']);
            } elseif ($deadline->diffInMinutes($now) <= config('management_dashboard.pickup_expiring_minutes', 120)) {
                $items[] = array_merge($base, ['stage_label' => 'Recojo próximo a vencer', 'severity' => 'medium', 'severity_rank' => 2, 'reason_code' => 'PICKUP_EXPIRING', 'reason' => 'El plazo de recojo está próximo a vencer.', 'is_overdue' => false, 'seconds_remaining' => $now->diffInSeconds($deadline), 'overdue_seconds' => null, 'deadline_label' => 'Vence pronto']);
            } else {
                $items[] = array_merge($base, ['stage_label' => 'Esperando recojo', 'severity' => 'information', 'severity_rank' => 1, 'reason_code' => 'PICKUP_WAITING', 'reason' => 'El pedido está listo para recojo.', 'is_overdue' => false, 'seconds_remaining' => null, 'overdue_seconds' => null, 'deadline_label' => null]);
            }
        }
        foreach (OrderDelivery::whereIn('method', [OrderDelivery::OWN_DELIVERY, OrderDelivery::EXTERNAL_COURIER])->whereNotNull('assigned_at')->whereNull('dispatched_at')->where('status', OrderDelivery::ASSIGNED)->get(['order_id', 'assigned_at']) as $delivery) {
            if ($delivery->assigned_at->diffInMinutes($now) >= config('management_dashboard.assigned_not_dispatched_minutes')) {
                $items[] = ['order_id' => $delivery->order_id, 'order_number' => (string) $delivery->order_id, 'stage' => 'assigned', 'stage_label' => 'Asignado sin salida', 'severity' => 'medium', 'severity_rank' => 2, 'reason_code' => 'ASSIGNED_NOT_DISPATCHED', 'reason' => 'La entrega asignada aún no inicia despacho.', 'stage_started_at' => $delivery->assigned_at->toIso8601String(), 'age_seconds' => $delivery->assigned_at->diffInSeconds($now), 'age_label' => $delivery->assigned_at->diffForHumans($now, true), 'delivery_type' => 'delivery', 'action_label' => 'Abrir delivery', 'action_route' => '/admin/orders/'.$delivery->order_id, 'deadline_at' => null, 'is_overdue' => false];
            }
        }
        $failedAttempts = OrderDeliveryAttempt::where('status', OrderDeliveryAttempt::FAILED)->with('delivery:id,order_id,method,status')->orderByDesc('finished_at')->get();
        foreach ($failedAttempts->groupBy('order_delivery_id') as $attempts) {
            $attempt = $attempts->first();
            $delivery = $attempt->delivery;
            if (! $delivery || in_array($delivery->method, [OrderDelivery::STORE_PICKUP], true) || in_array($delivery->status, [OrderDelivery::DELIVERED, OrderDelivery::CANCELED], true)) {
                continue;
            }
            $at = $attempt->finished_at ?: $attempt->reported_at ?: $attempt->created_at;
            $base = ['order_id' => $delivery->order_id, 'order_number' => (string) $delivery->order_id, 'stage' => 'delivery', 'stage_label' => 'Entrega fallida', 'severity' => 'high', 'severity_rank' => 3, 'reason_code' => 'DELIVERY_FAILED', 'reason' => 'Existe un intento de entrega fallido pendiente.', 'stage_started_at' => $at?->toIso8601String(), 'age_seconds' => $at ? $at->diffInSeconds($now) : null, 'age_label' => $at ? $at->diffForHumans($now, true) : 'Sin fecha disponible', 'delivery_type' => 'delivery', 'action_label' => 'Gestionar entrega', 'action_route' => '/admin/orders/'.$delivery->order_id, 'deadline_at' => null, 'is_overdue' => false, 'attempt_count' => $attempts->count()];
            $items[] = $base;
            $rescheduled = DB::table('order_delivery_histories')->where('order_delivery_id', $delivery->id)->where('event_type', 'delivery_rescheduled')->where('created_at', '>', $at)->exists();
            if (! $rescheduled) {
                $items[] = array_merge($base, ['reason_code' => 'DELIVERY_REPROGRAM_REQUIRED', 'reason' => 'La entrega fallida requiere reprogramación.']);
            }
        }
        $items = collect($items)->unique(fn ($i) => $i['order_id'].'|'.$i['reason_code'])->sortBy([['severity_rank', 'desc'], ['age_seconds', 'desc'], ['order_id', 'asc']])->values()->all();

        return $items;
    }

    private function cycleMetric(string $key, string $label, $rows, string $from, string $to, Carbon $start, Carbon $end, string $source): array
    {
        $values = [];
        $missing = 0;
        $invalid = 0;
        foreach ($rows as $row) {
            if (! $row->{$from} || ! $row->{$to}) {
                $missing++;

                continue;
            }
            if ($row->{$to} < $row->{$from}) {
                $invalid++;

                continue;
            }
            if ($row->{$to} < $start || $row->{$to} > $end) {
                continue;
            }
            $values[] = $row->{$from}->diffInSeconds($row->{$to}) / 60;
        }
        sort($values);
        $n = count($values);

        return ['key' => $key, 'label' => $label, 'available' => $n > 0, 'sample_count' => $n, 'missing_count' => $missing, 'invalid_count' => $invalid, 'average_minutes' => $n ? round(array_sum($values) / $n, 2) : null, 'median_minutes' => $n ? round($n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2, 2) : null, 'minimum_minutes' => $n ? round(min($values), 2) : null, 'maximum_minutes' => $n ? round(max($values), 2) : null, 'source' => $source, 'reason' => $n ? '' : 'No hay muestras completas en el periodo.'];
    }

    private function alerts(array $filters): array
    {
        $alerts = [];
        $zero = WarehouseInventory::whereRaw('quantity - reserved_quantity <= 0')->count();
        if ($zero) {
            $alerts[] = ['severity' => 'high', 'title' => 'Productos sin disponibilidad', 'count' => $zero, 'route' => '/admin/inventory'];
        }
        $deficit = WarehouseInventory::whereColumn('reserved_quantity', '>', 'quantity')->count();
        if ($deficit) {
            $alerts[] = ['severity' => 'critical', 'title' => 'Reservas superiores al físico', 'count' => $deficit, 'explanation' => 'Requiere revisión manual; no se modifica inventario.', 'route' => '/admin/inventory', 'calculated_at' => now()->toIso8601String()];
        }
        $expiring = InventoryReservation::where('status', InventoryReservation::ACTIVE)->whereBetween('expires_at', [now(), now()->addHours(2)])->count();
        if ($expiring) {
            $alerts[] = ['severity' => 'high', 'title' => 'Reservas próximas a vencer', 'count' => $expiring, 'route' => '/admin/inventory'];
        }
        $orphaned = Schema::hasTable('payment_preferences')
            ? PaymentPreference::whereIn('state', [PaymentPreference::FAILED, PaymentPreference::ORPHANED])->count()
            : 0;
        if ($orphaned) {
            $alerts[] = ['severity' => 'high', 'title' => 'Preferencias de pago requieren revisión', 'count' => $orphaned, 'route' => '/admin/payment-settings'];
        }

        return $alerts;
    }

    private function metric(float|int $value, float|int $previous, string $unit = ''): array
    {
        $delta = $value - $previous;
        $variation = $previous == 0 ? null : ($delta / abs($previous)) * 100;

        return ['value' => $value, 'previous' => $previous, 'delta' => $delta, 'variation' => $variation, 'unit' => $unit, 'comparison' => $previous == 0 ? 'Sin base de comparación' : ($delta >= 0 ? 'up' : 'down')];
    }
}
