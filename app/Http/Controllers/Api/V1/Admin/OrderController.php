<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminOrdersRequest;
use App\Http\Resources\AdminOrderListResource;
use App\Models\Order;
use App\Services\InventoryService;
use App\Services\MoneyService;
use App\Services\OrderFulfillmentService;
use App\Services\OrderStateService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(private OrderStateService $orderStateService, private InventoryService $inventoryService, private OrderFulfillmentService $fulfillment) {}

    public function index(ListAdminOrdersRequest $request)
    {
        $perPage = $request->pageSize();
        $requestedPage = $request->pageNumber();
        $sort = $request->sortColumn();
        $direction = $request->sortDirection();
        $filters = $request->filters();

        $query = Order::query();
        $this->applyListingFilters($query, $filters);
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $currentPage = min($requestedPage, $lastPage);

        $orders = $query
            ->with([
                'user:id,name',
                'items' => fn ($query) => $query
                    ->select(['order_items.id', 'order_items.order_id', 'order_items.warehouse_id'])
                    ->orderBy('order_items.id'),
                'items.warehouse:id,name',
                'items.currentReservation' => fn ($query) => $query->select([
                    'inventory_reservations.id',
                    'inventory_reservations.order_item_id',
                    'inventory_reservations.status',
                ]),
                'paymentSubmission:id,order_id,status',
            ])
            ->withExists(['paymentSubmission as has_payment_submission'])
            ->orderBy($sort, $direction)
            ->when($sort !== 'id', fn ($query) => $query->orderBy('id', $direction))
            ->forPage($currentPage, $perPage)
            ->get();

        return response()->json([
            'data' => AdminOrderListResource::collection($orders)->resolve($request),
            'meta' => [
                'current_page' => $currentPage,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0 ? null : (($currentPage - 1) * $perPage) + 1,
                'to' => $total === 0 ? null : min($currentPage * $perPage, $total),
            ],
            'links' => [
                'first' => $currentPage > 1 ? 1 : null,
                'previous' => $currentPage > 1 ? $currentPage - 1 : null,
                'next' => $currentPage < $lastPage ? $currentPage + 1 : null,
                'last' => $currentPage < $lastPage ? $lastPage : null,
            ],
        ]);
    }

    private function applyListingFilters(\Illuminate\Database\Eloquent\Builder $query, array $filters): void
    {
        if (isset($filters['order_id'])) {
            $query->where('orders.id', $filters['order_id']);
        }

        if (isset($filters['customer'])) {
            $this->whereCustomerNameContains($query, $filters['customer']);
        }

        if (isset($filters['search'])) {
            $rawSearch = $filters['search'];
            $textSearch = trim($rawSearch);
            $query->where(function ($nested) use ($rawSearch, $textSearch): void {
                if (preg_match('/\A[1-9][0-9]*\z/D', $rawSearch) === 1) {
                    $nested->where('orders.id', $rawSearch);
                    if ($textSearch !== '') {
                        $nested->orWhereHas('user', fn ($user) => $user->where('users.name', 'like', '%'.$textSearch.'%'));
                    }
                } else {
                    $nested->whereHas('user', fn ($user) => $user->where('users.name', 'like', '%'.$textSearch.'%'));
                }
            });
        }

        if (isset($filters['total_min']) || isset($filters['total_max'])) {
            $money = app(MoneyService::class);
            if (isset($filters['total_min'])) {
                $query->where('orders.total', '>=', $money->fromMinor($money->toMinor($filters['total_min'])));
            }
            if (isset($filters['total_max'])) {
                $query->where('orders.total', '<=', $money->fromMinor($money->toMinor($filters['total_max'])));
            }
        }

        if (isset($filters['fulfillment_status'])) {
            $this->whereEffectiveFulfillmentStatus($query, $filters['fulfillment_status']);
        }

        foreach (['payment_method', 'payment_status', 'delivery_type'] as $column) {
            if (isset($filters[$column])) {
                $query->where('orders.'.$column, $filters[$column]);
            }
        }

        if (isset($filters['warehouse_id'])) {
            $warehouseId = $filters['warehouse_id'];
            $query->whereHas('items', function ($items) use ($warehouseId): void {
                $items->whereRaw('order_items.id = (SELECT MIN(first_order_items.id) FROM order_items AS first_order_items WHERE first_order_items.order_id = order_items.order_id)')
                    ->where('order_items.warehouse_id', $warehouseId);
            });
        }

        if (isset($filters['reservation_status'])) {
            $status = $filters['reservation_status'];
            $query->whereHas('items', function ($items) use ($status): void {
                $items->whereRaw('order_items.id = (SELECT MIN(first_order_items.id) FROM order_items AS first_order_items WHERE first_order_items.order_id = order_items.order_id)')
                    ->whereHas('currentReservation', fn ($reservation) => $reservation->where('inventory_reservations.status', $status));
            });
        }

        if (isset($filters['date_from'])) {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_from'], config('app.timezone'))->startOfDay();
            $query->where('orders.created_at', '>=', $from->format('Y-m-d H:i:s'));
        }

        if (isset($filters['date_to'])) {
            $exclusiveEnd = CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_to'], config('app.timezone'))
                ->addDay()->startOfDay();
            $query->where('orders.created_at', '<', $exclusiveEnd->format('Y-m-d H:i:s'));
        }
    }

    private function whereCustomerNameContains(\Illuminate\Database\Eloquent\Builder $query, string $customer): void
    {
        // LIKE follows the configured database collation and remains parameter-bound.
        $query->whereHas('user', fn ($user) => $user->where('users.name', 'like', '%'.$customer.'%'));
    }

    private function whereEffectiveFulfillmentStatus(\Illuminate\Database\Eloquent\Builder $query, string $status): void
    {
        $query->where(function ($effective) use ($status): void {
            $effective->where('orders.fulfillment_status', $status);

            $effective->orWhere(function ($legacy) use ($status): void {
                $legacy->where(function ($unset): void {
                    $unset->whereNull('orders.fulfillment_status')->orWhere('orders.fulfillment_status', '');
                });

                match ($status) {
                    Order::FULFILLMENT_CANCELED => $legacy->whereIn('orders.status', ['canceled', 'rejected']),
                    Order::FULFILLMENT_DELIVERED => $legacy->where('orders.status', 'delivered'),
                    Order::FULFILLMENT_RESERVED => $legacy->whereNotIn('orders.status', ['canceled', 'rejected', 'delivered']),
                    default => $legacy->whereRaw('1 = 0'),
                };
            });
        });
    }

    public function update(Request $request, Order $order)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,confirmed,shipped,delivered,canceled,rejected',
        ]);
        $target = $validated['status'];
        if ($target === 'rejected' && $order->payment_status === 'approved') {
            throw \Illuminate\Validation\ValidationException::withMessages(['status' => ['Un pago aprobado no puede marcarse como rechazado. Use la cancelacion operativa.']]);
        }
        if (in_array($target, ['canceled', 'rejected'], true)) {
            return response()->json($this->fulfillment->cancelFulfillment($order, $request->user(), $target === 'rejected' ? 'Pedido rechazado por administracion' : 'Pedido cancelado por administracion')['order']);
        }
        if ($target === 'shipped') {
            return response()->json($this->fulfillment->markAsReady($order, $request->user(), 'Compatibilidad con estado enviado')['order']);
        }
        if ($target === 'delivered') {
            return response()->json($this->fulfillment->markAsDelivered($order, $request->user(), 'Compatibilidad con estado entregado')['order']);
        }
        if ($target === 'confirmed' && $order->payment_method === 'contra_entrega') {
            throw \Illuminate\Validation\ValidationException::withMessages(['status' => ['Contraentrega se confirma al entregar; use Iniciar preparacion.']]);
        }

        $updatedOrder = DB::transaction(function () use ($order, $validated, $request) {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $oldStatus = $lockedOrder->status;
            $newStatus = $validated['status'];
            $usesReservations = $this->inventoryService->orderUsesReservationFlow($lockedOrder);

            $updatedOrder = $this->orderStateService->transitionCommercial($lockedOrder, $newStatus);

            if ($newStatus === 'confirmed' && $oldStatus === 'pending') {
                if ($usesReservations) {
                    $this->inventoryService->consumeOrderReservation($updatedOrder, $request->user());
                }
                $updatedOrder->update(['payment_status' => 'approved', 'paid_at' => $updatedOrder->paid_at ?: now()]);
            }

            if (in_array($newStatus, ['canceled', 'rejected'], true)
                && ! in_array($oldStatus, ['canceled', 'rejected'], true)) {
                $this->cancelOrderInventory($updatedOrder, $request->user());
            }

            return $updatedOrder;
        });

        return response()->json($updatedOrder->load(['user', 'items.product', 'items.warehouse', 'items.reservation']));
    }

    /**
     * Update tracking information for an order
     */
    public function updateTracking(Request $request, $id)
    {
        $validated = $request->validate([
            'tracking_status' => 'required|string',
            'tracking_notes' => 'nullable|string',
            'estimated_delivery_date' => 'nullable|date',
        ]);

        $order = Order::findOrFail($id);
        $target = $validated['tracking_status'];
        $note = $validated['tracking_notes'] ?? null;
        if ($target === 'canceled') {
            $result = $this->fulfillment->cancelFulfillment($order, $request->user(), $note ?: 'Pedido cancelado desde tracking');

            return response()->json(['message' => 'Seguimiento actualizado correctamente.', 'order' => $result['order']]);
        }
        if ($target === 'processing') {
            $result = $this->fulfillment->startPreparation($order, $request->user(), $note);

            return response()->json(['message' => 'Seguimiento actualizado correctamente.', 'order' => $result['order']]);
        }
        if (in_array($target, ['shipped', 'ready_for_pickup'], true)) {
            $result = $this->fulfillment->markAsReady($order, $request->user(), $note);

            return response()->json(['message' => 'Seguimiento actualizado correctamente.', 'order' => $result['order']]);
        }
        if (in_array($target, ['delivered', 'picked_up'], true)) {
            $result = $this->fulfillment->markAsDelivered($order, $request->user(), $note);

            return response()->json(['message' => 'Seguimiento actualizado correctamente.', 'order' => $result['order']]);
        }
        if ($target === 'confirmed' && $order->payment_method === 'contra_entrega') {
            throw \Illuminate\Validation\ValidationException::withMessages(['tracking_status' => ['Contraentrega se confirma al entregar; use Iniciar preparacion.']]);
        }

        $order = DB::transaction(function () use ($id, $validated, $request) {
            $lockedOrder = Order::whereKey($id)->lockForUpdate()->firstOrFail();
            $oldStatus = $lockedOrder->status;
            $usesReservations = $this->inventoryService->orderUsesReservationFlow($lockedOrder);

            $updatedOrder = $this->orderStateService->transitionTracking(
                $lockedOrder,
                $validated['tracking_status'],
                $validated
            );

            if ($validated['tracking_status'] === 'confirmed' && $oldStatus === 'pending') {
                if ($usesReservations) {
                    $this->inventoryService->consumeOrderReservation($updatedOrder, $request->user());
                }
                $updatedOrder->update(['payment_status' => 'approved', 'paid_at' => $updatedOrder->paid_at ?: now()]);
            }

            if ($validated['tracking_status'] === 'canceled'
                && ! in_array($oldStatus, ['canceled', 'rejected'], true)) {
                $this->cancelOrderInventory($updatedOrder, $request->user());
            }

            return $updatedOrder;
        });

        return response()->json([
            'message' => 'Seguimiento actualizado correctamente.',
            'order' => $order->load(['user', 'items.product', 'items.warehouse', 'items.reservation']),
        ]);
    }
}
