<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\InventoryService;
use App\Services\OrderStateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(private OrderStateService $orderStateService, private InventoryService $inventoryService) {}

    public function index()
    {
        return Order::with(['user', 'items.product', 'items.warehouse', 'items.reservation'])->latest()->paginate(20);
    }

    public function update(Request $request, Order $order)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,confirmed,shipped,delivered,canceled,rejected',
        ]);

        $updatedOrder = DB::transaction(function () use ($order, $validated, $request) {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $oldStatus = $lockedOrder->status;
            $newStatus = $validated['status'];
            $usesReservations = $this->inventoryService->orderUsesReservationFlow($lockedOrder);

            $updatedOrder = $this->orderStateService->transitionCommercial($lockedOrder, $newStatus);

            if ($newStatus === 'confirmed' && $oldStatus === 'pending') {
                if ($usesReservations) $this->inventoryService->consumeOrderReservation($updatedOrder, $request->user());
                $updatedOrder->update(['payment_status'=>'approved','paid_at'=>$updatedOrder->paid_at ?: now()]);
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
                if ($usesReservations) $this->inventoryService->consumeOrderReservation($updatedOrder, $request->user());
                $updatedOrder->update(['payment_status'=>'approved','paid_at'=>$updatedOrder->paid_at ?: now()]);
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

    private function restoreOrderStock(Order $order, $user = null): void
    {
        $order->items()->with(['product', 'warehouse'])->get()
            ->each(fn ($item) => $this->inventoryService->returnCancellation($item, $user));
    }

    private function cancelOrderInventory(Order $order, $user = null): void
    {
        if (!$this->inventoryService->orderUsesReservationFlow($order) || $order->payment_status === 'approved' || $order->paid_at || $this->inventoryService->orderHasConsumedReservation($order)) {
            $this->restoreOrderStock($order, $user);
            return;
        }
        $this->inventoryService->releaseOrderReservation($order);
    }
}
