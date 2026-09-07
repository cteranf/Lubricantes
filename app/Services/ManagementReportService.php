<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\DeliveryDriver;
use App\Models\DeliveryVehicle;
use App\Models\Department;
use App\Models\District;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Province;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ManagementReportService
{
    public function __construct(private ManagementDashboardService $dashboard) {}

    public function build(string $section, array $filters, ?int $limit = null): array
    {
        return match ($section) {
            'sales' => $this->sales($filters, $limit),
            'inventory' => $this->inventory($filters, $limit),
            'inventory_movements' => $this->inventoryMovements($filters, $limit),
            'operations' => $this->operations($filters, $limit),
            'cycle_times' => $this->cycleTimes($filters),
            'catalogs' => $this->catalogs($filters, $limit),
            default => $this->empty($section, $filters),
        };
    }

    private function sales(array $filters, ?int $limit): array
    {
        [$start, $end] = $this->dateRange($filters);
        $limit ??= (int) config('management_reports.query_limit', 1000);
        $base = $this->salesItems($filters, $start, $end);
        $orderIds = (clone $base)->select('order_items.order_id')->distinct();
        $rowCount = (clone $base)->count();
        $totals = [
            'sales' => (float) Order::whereIn('id', $orderIds)->sum('total'),
            'recognized_merchandise_sales' => (float) (clone $base)->sum('order_items.subtotal'),
            'recognized_shipping_revenue' => (float) Order::whereIn('id', (clone $orderIds))->sum('shipping_amount'),
            'orders' => (int) (clone $base)->distinct('order_items.order_id')->count('order_items.order_id'),
            'units' => (int) (clone $base)->sum('order_items.quantity'),
            'line_subtotal' => (float) (clone $base)->sum('order_items.subtotal'),
        ];
        $totals['average_ticket'] = $totals['orders'] > 0 ? $totals['sales'] / $totals['orders'] : null;
        $items = (clone $base)->with(['order', 'product.category', 'product.brand'])
            ->select(['order_items.id', 'order_items.order_id', 'order_items.product_id', 'order_items.quantity', 'order_items.price', 'order_items.subtotal'])
            ->orderByRaw('COALESCE(orders.paid_at, orders.created_at) ASC')->orderBy('order_items.id')->limit($limit)->get();
        $rows = $items->map(function (OrderItem $item) {
            $recognizedAt = $item->order->paid_at ?: $item->order->created_at;

            return ['recognized_at' => $recognizedAt?->toIso8601String(), 'order_number' => (string) $item->order_id, 'delivery_type' => $item->order->delivery_type, 'payment_method' => $item->order->payment_method, 'payment_status' => $item->order->payment_status, 'product_name' => $item->product?->name, 'sku' => $item->product?->sku, 'category_name' => $item->product?->category?->name ?: 'Sin clasificar', 'brand_name' => $item->product?->brand?->name ?: 'Sin clasificar', 'quantity' => (int) $item->quantity, 'historical_unit_price' => (float) $item->price, 'line_subtotal' => (float) $item->subtotal, 'order_total' => (float) $item->order->total];
        })->values()->all();

        return $this->env('sales', $filters, $rows, $totals, $rowCount, $limit, ['data_freshness' => 'recognized_sales', 'recognized_at_expression' => 'COALESCE(orders.paid_at, orders.created_at)']);
    }

    private function salesItems(array $filters, Carbon $start, Carbon $end): Builder
    {
        return OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')->where('orders.payment_status', 'approved')->whereBetween(DB::raw('COALESCE(orders.paid_at, orders.created_at)'), [$start, $end])->when($filters['delivery_type'] ?? null, fn ($q, $v) => $q->where('orders.delivery_type', $v))->when($filters['payment_method'] ?? null, fn ($q, $v) => $q->where('orders.payment_method', $v))->when($filters['category_id'] ?? null, fn ($q, $v) => $q->whereHas('product', fn ($p) => $p->where('category_id', $v)))->when($filters['brand_id'] ?? null, fn ($q, $v) => $q->whereHas('product', fn ($p) => $p->where('brand_id', $v)));
    }

    private function dateRange(array $filters): array
    {
        $now = Carbon::now(config('app.timezone', 'America/Lima'));
        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            return [Carbon::parse($filters['date_from'] ?: $now->toDateString())->startOfDay(), Carbon::parse($filters['date_to'] ?: $now->toDateString())->endOfDay()];
        }

        return match ($filters['period'] ?? 'this_year') {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()], 'last_7_days' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()], 'last_30_days' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()], 'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()], 'previous_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()], default => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
        };
    }

    private function inventory(array $f, ?int $requestedLimit = null): array
    {
        $query = WarehouseInventory::query()->with(['warehouse', 'product.category', 'product.brand'])->when($f['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))->when($f['category_id'] ?? null, fn ($q, $v) => $q->whereHas('product', fn ($p) => $p->where('category_id', $v)))->when($f['brand_id'] ?? null, fn ($q, $v) => $q->whereHas('product', fn ($p) => $p->where('brand_id', $v)));
        $driver = WarehouseInventory::query()->getConnection()->getDriverName();
        $q = $driver === 'mysql' ? 'CAST(quantity AS SIGNED)' : 'CAST(quantity AS INTEGER)';
        $r = $driver === 'mysql' ? 'CAST(reserved_quantity AS SIGNED)' : 'CAST(reserved_quantity AS INTEGER)';
        $raw = "($q - $r)";
        $sellable = "CASE WHEN $raw > 0 THEN $raw ELSE 0 END";
        $deficit = "CASE WHEN ($r - $q) > 0 THEN ($r - $q) ELSE 0 END";
        $rowCount = (clone $query)->count();
        $limit = $requestedLimit ?? (int) config('management_reports.query_limit', 1000);
        $aggregate = (clone $query)->selectRaw("SUM($q) physical, SUM($r) reserved, SUM($raw) raw_available, SUM($sellable) sellable_available, SUM($deficit) reservation_deficit, SUM(CASE WHEN $r > $q THEN 1 ELSE 0 END) inconsistent_count, COUNT(DISTINCT warehouse_id) warehouse_count, COUNT(DISTINCT product_id) product_count")->first();
        $items = (clone $query)->select(['id', 'warehouse_id', 'product_id', 'quantity', 'reserved_quantity', 'updated_at'])->limit($limit)->get();
        $snapshotAt = now()->toIso8601String();
        $rows = $items->map(fn ($i) => ['warehouse_name' => $i->warehouse?->name, 'product_name' => $i->product?->name, 'sku' => $i->product?->sku, 'category_name' => $i->product?->category?->name ?: 'Sin clasificar', 'brand_name' => $i->product?->brand?->name ?: 'Sin clasificar', 'physical' => (int) $i->quantity, 'reserved' => (int) $i->reserved_quantity, 'raw_available' => (int) $i->quantity - (int) $i->reserved_quantity, 'sellable_available' => max((int) $i->quantity - (int) $i->reserved_quantity, 0), 'reservation_deficit' => max((int) $i->reserved_quantity - (int) $i->quantity, 0), 'has_inconsistency' => (int) $i->reserved_quantity > (int) $i->quantity, 'snapshot_at' => $snapshotAt])->all();

        return $this->env('inventory', $f, $rows, ['physical' => (int) ($aggregate->physical ?? 0), 'reserved' => (int) ($aggregate->reserved ?? 0), 'raw_available' => (int) ($aggregate->raw_available ?? 0), 'sellable_available' => (int) ($aggregate->sellable_available ?? 0), 'reservation_deficit' => (int) ($aggregate->reservation_deficit ?? 0), 'inconsistent_count' => (int) ($aggregate->inconsistent_count ?? 0), 'warehouse_count' => (int) ($aggregate->warehouse_count ?? 0), 'product_count' => (int) ($aggregate->product_count ?? 0)], $rowCount, $limit, ['snapshot' => true, 'snapshot_at' => $snapshotAt, 'data_freshness' => 'current_inventory']);
    }

    private function inventoryMovements(array $f, ?int $requestedLimit = null): array
    {
        [$start, $end] = $this->dateRange($f);
        $limit = $requestedLimit ?? (int) config('management_reports.query_limit', 1000);
        $query = InventoryMovement::query()->with(['warehouse', 'product.category', 'product.brand'])->whereBetween('created_at', [$start, $end])
            ->when($f['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($f['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($f['movement_type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['category_id'] ?? null, fn ($q, $v) => $q->whereHas('product', fn ($p) => $p->where('category_id', $v)))
            ->when($f['brand_id'] ?? null, fn ($q, $v) => $q->whereHas('product', fn ($p) => $p->where('brand_id', $v)));
        $typesIn = implode(',', array_map(fn ($v) => "'{$v}'", InventoryMovement::INCOMING));
        $typesOut = implode(',', array_map(fn ($v) => "'{$v}'", InventoryMovement::OUTGOING));
        $directionSql = "CASE WHEN type IN ($typesIn) OR (type = 'correction' AND quantity_after > quantity_before) THEN 'in' WHEN type IN ($typesOut) OR (type = 'correction' AND quantity_after < quantity_before) THEN 'out' ELSE 'neutral' END";
        if (! empty($f['movement_direction'])) {
            $query->whereRaw("$directionSql = ?", [$f['movement_direction']]);
        }
        $rowCount = (clone $query)->count();
        $aggregate = (clone $query)->selectRaw("COUNT(*) movements, SUM(CASE WHEN $directionSql = 'in' THEN CASE WHEN type = 'correction' THEN quantity_after - quantity_before ELSE quantity END ELSE 0 END) units_in, SUM(CASE WHEN $directionSql = 'out' THEN CASE WHEN type = 'correction' THEN quantity_before - quantity_after ELSE quantity END ELSE 0 END) units_out, SUM(CASE WHEN $directionSql = 'in' THEN CASE WHEN type = 'correction' THEN quantity_after - quantity_before ELSE quantity END WHEN $directionSql = 'out' THEN CASE WHEN type = 'correction' THEN quantity_after - quantity_before ELSE -quantity END ELSE 0 END) net_units, COUNT(DISTINCT warehouse_id) warehouses, COUNT(DISTINCT product_id) products")->first();
        $byType = (clone $query)->selectRaw('type, COUNT(*) movements, SUM(quantity) units')->groupBy('type')->orderBy('type')->get()->map(fn ($r) => ['type' => in_array($r->type, InventoryMovement::TYPES, true) ? $r->type : 'unknown', 'movements' => (int) $r->movements, 'units' => (int) $r->units])->values()->all();
        $items = (clone $query)->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
        $rows = $items->map(function ($m) {
            $direction = in_array($m->type, InventoryMovement::INCOMING, true) || ($m->type === InventoryMovement::CORRECTION && $m->quantity_after > $m->quantity_before) ? 'in' : (in_array($m->type, InventoryMovement::OUTGOING, true) || ($m->type === InventoryMovement::CORRECTION && $m->quantity_after < $m->quantity_before) ? 'out' : 'neutral');
            $safeNote = mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $m->reason), 0, 500);
            $safeNote = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', '[redactado]', $safeNote);
            $safeNote = preg_replace('/(?<!\d)(?:\+?\d[\d\s-]{7,}\d)(?!\d)/', '[redactado]', $safeNote);
            $reference = $m->reference_id && preg_match('/^[A-Za-z0-9_-]{1,100}$/', (string) $m->reference_id) ? (string) $m->reference_id : null;

            $signed = $m->type === InventoryMovement::CORRECTION ? (int) $m->quantity_after - (int) $m->quantity_before : ($direction === 'out' ? -((int) $m->quantity) : ($direction === 'in' ? (int) $m->quantity : 0));

            return ['occurred_at' => $m->created_at?->toIso8601String(), 'warehouse_name' => $m->warehouse?->name, 'product_name' => $m->product?->name, 'sku' => $m->product?->sku, 'category_name' => $m->product?->category?->name ?: 'Sin clasificar', 'brand_name' => $m->product?->brand?->name ?: 'Sin clasificar', 'movement_type' => in_array($m->type, InventoryMovement::TYPES, true) ? $m->type : 'unknown', 'movement_direction' => $direction, 'quantity' => (int) $m->quantity, 'signed_quantity' => $signed, 'reference_type' => $m->reference_type, 'reference_code' => $reference, 'safe_note' => $safeNote];
        })->values()->all();

        return $this->env('inventory_movements', $f, $rows, ['movements' => (int) ($aggregate->movements ?? 0), 'units_in' => (int) ($aggregate->units_in ?? 0), 'units_out' => (int) ($aggregate->units_out ?? 0), 'net_units' => (int) ($aggregate->net_units ?? 0), 'warehouses' => (int) ($aggregate->warehouses ?? 0), 'products' => (int) ($aggregate->products ?? 0), 'by_type' => $byType], $rowCount, $limit, ['data_freshness' => 'inventory_movement_history']);
    }

    private function operations(array $f, ?int $requestedLimit = null): array
    {
        $dashboard = $this->dashboard->reportOperations($f);
        $items = collect($dashboard['attention_queue'] ?? []);
        $items = $items->when($f['severity'] ?? null, fn ($c, $v) => $c->where('severity', $v))->when($f['reason_code'] ?? null, fn ($c, $v) => $c->where('reason_code', $v))->when($f['stage'] ?? null, fn ($c, $v) => $c->where('stage', $v))->when($f['delivery_type'] ?? null, fn ($c, $v) => $c->where('delivery_type', $v))->when(isset($f['overdue']) && $f['overdue'] !== null && $f['overdue'] !== '', fn ($c) => $c->where('is_overdue', filter_var($f['overdue'], FILTER_VALIDATE_BOOLEAN)));
        $all = $items->values();
        $limit = $requestedLimit ?? (int) config('management_reports.query_limit', 1000);
        $rows = $all->take($limit)->map(fn ($i) => ['order_number' => (string) $i['order_number'], 'stage' => $i['stage'], 'delivery_type' => $i['delivery_type'], 'order_status' => null, 'logistics_status' => null, 'severity' => $i['severity'], 'severity_rank' => $i['severity_rank'], 'reason_code' => $i['reason_code'], 'managerial_description' => $i['reason'], 'relevant_at' => $i['stage_started_at'], 'age_seconds' => $i['age_seconds'], 'age_label' => $i['age_label'], 'deadline_at' => $i['deadline_at'] ?? null, 'seconds_remaining' => $i['seconds_remaining'] ?? null, 'overdue_seconds' => $i['overdue_seconds'] ?? null, 'deadline_label' => $i['deadline_label'] ?? null, 'is_overdue' => (bool) $i['is_overdue'], 'admin_route' => in_array($i['action_route'] ?? '', ['/admin/inventory', '/admin/orders/'.$i['order_id']], true) ? $i['action_route'] : null])->all();
        $byReason = $all->groupBy('reason_code')->map(fn ($g, $k) => ['reason_code' => $k, 'alerts' => $g->count()])->values()->all();

        return $this->env('operations', $f, $rows, ['alerts' => $all->count(), 'shown' => count($rows), 'has_more' => $all->count() > $limit, 'critical' => $all->where('severity', 'critical')->count(), 'high' => $all->where('severity', 'high')->count(), 'medium' => $all->where('severity', 'medium')->count(), 'low' => $all->whereIn('severity', ['low', 'information'])->count(), 'overdue' => $all->where('is_overdue', true)->count(), 'affected_orders' => $all->pluck('order_id')->unique()->count(), 'by_reason' => $byReason, 'by_stage' => $all->groupBy('stage')->map(fn ($g, $k) => ['stage' => $k, 'alerts' => $g->count()])->values()->all(), 'by_delivery_type' => $all->groupBy('delivery_type')->map(fn ($g, $k) => ['delivery_type' => $k, 'alerts' => $g->count()])->values()->all()], $all->count(), $limit, ['snapshot' => true, 'snapshot_at' => now()->toIso8601String(), 'data_freshness' => 'current_operational_backlog']);
    }

    private function cycleTimes(array $f): array
    {
        $ops = $this->dashboard->reportOperations($f)['cycle_times'] ?? [];
        $allowed = ['picking_duration', 'packing_duration', 'assignment_to_dispatch', 'dispatch_to_delivery', 'ready_to_delivery', 'total_delivery_cycle', 'ready_to_pickup'];
        $metrics = collect($ops)->only($f['metric'] ?? $allowed)->values()->all();

        return ['report' => 'cycle_times', 'generated_at' => now()->toIso8601String(), 'applied_filters' => $f, 'metadata' => ['timezone' => config('app.timezone', 'America/Lima'), 'data_freshness' => 'recognized_operations'], 'metrics' => $metrics, 'available_metrics' => collect($metrics)->where('available', true)->pluck('key')->values()->all(), 'unavailable_metrics' => collect($metrics)->where('available', false)->pluck('key')->values()->all(), 'total_samples' => collect($metrics)->sum('sample_count'), 'total_missing' => collect($metrics)->sum('missing_count'), 'total_invalid' => collect($metrics)->sum('invalid_count')];
    }

    private function catalogs(array $f, ?int $requestedLimit = null): array
    {
        $catalog = $f['catalog'] ?? null;
        $allowed = ['products', 'categories', 'brands', 'warehouses', 'branches', 'drivers', 'vehicles', 'departments', 'provinces', 'districts', 'shipping_zones', 'shipping_rates'];
        abort_unless(in_array($catalog, $allowed, true), 422);
        $limit = $requestedLimit ?? (int) config('management_reports.query_limit', 1000);
        $definitions = [
            'products' => [Product::class, ['name', 'sku', 'price', 'is_active'], fn ($q) => $q->with(['category:id,name', 'brand:id,name'])->orderBy('name'), fn ($m) => ['name' => $m->name, 'sku' => $m->sku, 'category_name' => $m->category?->name, 'brand_name' => $m->brand?->name, 'price' => (float) $m->price, 'is_active' => (bool) $m->is_active]],
            'categories' => [Category::class, ['name', 'is_active'], fn ($q) => $q->orderBy('name'), fn ($m) => ['name' => $m->name, 'is_active' => (bool) $m->is_active]],
            'brands' => [Brand::class, ['name', 'is_active'], fn ($q) => $q->orderBy('name'), fn ($m) => ['name' => $m->name, 'is_active' => (bool) $m->is_active]],
            'warehouses' => [Warehouse::class, ['code', 'name', 'branch_id', 'is_active'], fn ($q) => $q->with('branch:id,name')->orderBy('name'), fn ($m) => ['code' => $m->code, 'name' => $m->name, 'branch_name' => $m->branch?->name, 'is_active' => (bool) $m->is_active]],
            'branches' => [Branch::class, ['code', 'name', 'district_id', 'allows_pickup', 'is_active'], fn ($q) => $q->with('districtRelation:id,name,ubigeo')->orderBy('name'), fn ($m) => ['code' => $m->code, 'name' => $m->name, 'district_name' => $m->districtRelation?->name, 'ubigeo' => $m->districtRelation?->ubigeo, 'allows_pickup' => (bool) $m->allows_pickup, 'is_active' => (bool) $m->is_active]],
            'drivers' => [DeliveryDriver::class, ['code', 'first_name', 'last_name', 'is_active', 'is_available'], fn ($q) => $q->orderBy('code'), fn ($m) => ['code' => $m->code, 'display_name' => trim($m->first_name.' '.$m->last_name), 'is_active' => (bool) $m->is_active, 'is_available' => (bool) $m->is_available]],
            'vehicles' => [DeliveryVehicle::class, ['code', 'vehicle_type', 'brand', 'model', 'is_active', 'is_available'], fn ($q) => $q->orderBy('code'), fn ($m) => ['code' => $m->code, 'type' => $m->vehicle_type, 'description' => trim($m->brand.' '.$m->model), 'is_active' => (bool) $m->is_active, 'is_available' => (bool) $m->is_available]],
            'departments' => [Department::class, ['code', 'name', 'is_active'], fn ($q) => $q->orderBy('code'), fn ($m) => ['code' => $m->code, 'name' => $m->name, 'is_active' => (bool) $m->is_active]],
            'provinces' => [Province::class, ['department_id', 'code', 'name', 'is_active'], fn ($q) => $q->with('department:id,code,name')->orderBy('code'), fn ($m) => ['department_code' => $m->department?->code, 'department_name' => $m->department?->name, 'code' => $m->code, 'name' => $m->name, 'is_active' => (bool) $m->is_active]],
            'districts' => [District::class, ['province_id', 'code', 'name', 'ubigeo', 'is_active'], fn ($q) => $q->with('province.department')->orderBy('ubigeo'), fn ($m) => ['department_code' => $m->province?->department?->code, 'department_name' => $m->province?->department?->name, 'province_code' => $m->province?->code, 'province_name' => $m->province?->name, 'code' => $m->code, 'name' => $m->name, 'ubigeo' => $m->ubigeo, 'is_active' => (bool) $m->is_active]],
            'shipping_zones' => [ShippingZone::class, ['code', 'name', 'estimated_days_min', 'estimated_days_max', 'is_active'], fn ($q) => $q->orderBy('code'), fn ($m) => ['code' => $m->code, 'name' => $m->name, 'minimum_days' => $m->estimated_days_min, 'maximum_days' => $m->estimated_days_max, 'is_active' => (bool) $m->is_active]],
            'shipping_rates' => [ShippingRate::class, ['shipping_zone_id', 'district_id', 'amount', 'estimated_days_min', 'estimated_days_max', 'is_active'], fn ($q) => $q->with(['zone:id,code,name', 'districtRelation:id,name,ubigeo'])->orderBy('district'), fn ($m) => ['zone_code' => $m->zone?->code, 'zone_name' => $m->zone?->name, 'district_name' => $m->districtRelation?->name, 'ubigeo' => $m->districtRelation?->ubigeo, 'amount' => (float) $m->amount, 'minimum_days' => $m->estimated_days_min, 'maximum_days' => $m->estimated_days_max, 'is_active' => (bool) $m->is_active]],
        ];
        [$model, $columns, $builder, $mapper] = $definitions[$catalog];
        $query = $builder($model::query()->select($columns));
        if (isset($f['search']) && $f['search'] !== '') {
            $search = addcslashes((string) $f['search'], '\\%_');
            $columns = match ($catalog) {
                'products' => ['name', 'sku'], 'drivers' => ['first_name', 'last_name', 'code'], 'vehicles' => ['code', 'vehicle_type'], 'shipping_rates' => ['district', 'ubigeo'], default => ['name', 'code']
            };
            $query->where(function ($q) use ($columns, $search) {
                foreach ($columns as $index => $column) {
                    $sql = $column." LIKE ? ESCAPE '\\'";
                    $index === 0 ? $q->whereRaw($sql, ['%'.$search.'%']) : $q->orWhereRaw($sql, ['%'.$search.'%']);
                }
            });
        }
        if (isset($f['is_active']) && $f['is_active'] !== '') {
            $query->where('is_active', filter_var($f['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if ($catalog === 'products') {
            $query->when($f['category_id'] ?? null, fn ($q, $v) => $q->where('category_id', $v))->when($f['brand_id'] ?? null, fn ($q, $v) => $q->where('brand_id', $v));
        }
        if ($catalog === 'warehouses') {
            $query->when($f['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v));
        }
        if ($catalog === 'provinces') {
            $query->when($f['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v));
        }
        if ($catalog === 'districts') {
            $query->when($f['province_id'] ?? null, fn ($q, $v) => $q->where('province_id', $v))->when($f['department_id'] ?? null, fn ($q, $v) => $q->whereHas('province', fn ($p) => $p->where('department_id', $v)));
        }
        if ($catalog === 'shipping_rates') {
            $query->when($f['zone_id'] ?? null, fn ($q, $v) => $q->where('shipping_zone_id', $v))->when($f['district_id'] ?? null, fn ($q, $v) => $q->where('district_id', $v));
        }
        if (in_array($catalog, ['drivers', 'vehicles'], true)) {
            $query->when($f['is_available'] ?? null, fn ($q, $v) => $q->where('is_available', filter_var($v, FILTER_VALIDATE_BOOLEAN)));
        }
        if ($catalog === 'vehicles') {
            $query->when($f['type'] ?? null, fn ($q, $v) => $q->where('vehicle_type', $v));
        }
        if ($catalog === 'branches') {
            $query->when($f['allows_pickup'] ?? null, fn ($q, $v) => $q->where('allows_pickup', filter_var($v, FILTER_VALIDATE_BOOLEAN)));
        }
        $count = (clone $query)->count();
        $active = (clone $query)->where('is_active', true)->count();
        $rows = (clone $query)->limit($limit)->get()->map($mapper)->all();

        return $this->env('catalogs', $f, $rows, ['records' => $count, 'active' => $active, 'inactive' => $count - $active], $count, $limit, ['catalog' => $catalog, 'data_freshness' => 'current_catalog']);
    }

    private function env(string $report, array $filters, array $rows, array $totals, int $count, int $limit, array $meta = []): array
    {
        return ['report' => $report, 'generated_at' => now()->toIso8601String(), 'applied_filters' => $filters, 'metadata' => array_merge(['currency' => 'PEN', 'timezone' => config('app.timezone', 'America/Lima'), 'row_count' => $count, 'returned_count' => count($rows), 'truncated' => $count > $limit, 'limit' => $limit, 'data_freshness' => now()->toIso8601String()], $meta), 'totals' => $totals, 'rows' => $rows];
    }

    private function empty(string $report, array $filters): array
    {
        return $this->env($report, $filters, [], [], 0, (int) config('management_reports.query_limit', 1000));
    }
}
