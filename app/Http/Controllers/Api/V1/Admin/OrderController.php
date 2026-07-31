<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\InventoryService;
use App\Services\OrderStateService;
use App\Services\OrderFulfillmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(private OrderStateService $orderStateService, private InventoryService $inventoryService, private OrderFulfillmentService $fulfillment) {}

    public function index()
    {
        return Order::with(['user', 'items.product', 'items.warehouse', 'items.reservation'])->latest()->paginate(20);
    }

    public function update(Request $request, Order $order)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,confirmed,shipped,delivered,canceled,rejected',
        ]);
        $target=$validated['status'];
        if ($target==='rejected' && $order->payment_status==='approved') throw \Illuminate\Validation\ValidationException::withMessages(['status'=>['Un pago aprobado no puede marcarse como rechazado. Use la cancelacion operativa.']]);
        if (in_array($target,['canceled','rejected'],true)) return response()->json($this->fulfillment->cancelFulfillment($order,$request->user(),$target==='rejected'?'Pedido rechazado por administracion':'Pedido cancelado por administracion')['order']);
        if ($target==='shipped') return response()->json($this->fulfillment->markAsReady($order,$request->user(),'Compatibilidad con estado enviado')['order']);
        if ($target==='delivered') return response()->json($this->fulfillment->markAsDelivered($order,$request->user(),'Compatibilidad con estado entregado')['order']);
        if ($target==='confirmed' && $order->payment_method==='contra_entrega') throw \Illuminate\Validation\ValidationException::withMessages(['status'=>['Contraentrega se confirma al entregar; use Iniciar preparacion.']]);

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

        $order=Order::findOrFail($id); $target=$validated['tracking_status']; $note=$validated['tracking_notes']??null;
        if ($target==='canceled') { $result=$this->fulfillment->cancelFulfillment($order,$request->user(),$note ?: 'Pedido cancelado desde tracking'); return response()->json(['message'=>'Seguimiento actualizado correctamente.','order'=>$result['order']]); }
        if ($target==='processing') { $result=$this->fulfillment->startPreparation($order,$request->user(),$note); return response()->json(['message'=>'Seguimiento actualizado correctamente.','order'=>$result['order']]); }
        if (in_array($target,['shipped','ready_for_pickup'],true)) { $result=$this->fulfillment->markAsReady($order,$request->user(),$note); return response()->json(['message'=>'Seguimiento actualizado correctamente.','order'=>$result['order']]); }
        if (in_array($target,['delivered','picked_up'],true)) { $result=$this->fulfillment->markAsDelivered($order,$request->user(),$note); return response()->json(['message'=>'Seguimiento actualizado correctamente.','order'=>$result['order']]); }
        if ($target==='confirmed' && $order->payment_method==='contra_entrega') throw \Illuminate\Validation\ValidationException::withMessages(['tracking_status'=>['Contraentrega se confirma al entregar; use Iniciar preparacion.']]);

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

}
