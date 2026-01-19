<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index()
    {
        return Order::with(['user', 'items.product'])->latest()->paginate(20);
    }

    public function update(Request $request, Order $order)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,confirmed,shipped,delivered,canceled,rejected'
        ]);

        $oldStatus = $order->status;
        $newStatus = $validated['status'];

        // Logic for Stock Restoration
        // If moving TO canceled/rejected FROM a status that held stock (pending, confirmed)
        if (in_array($newStatus, ['canceled', 'rejected']) && !in_array($oldStatus, ['canceled', 'rejected'])) {
            // Restore stock
            foreach ($order->items as $item) {
                $item->product->increment('stock', $item->quantity);
            }
        }

        // Logic for Stock Deduction (Re-deduct if moving FROM canceled/rejected TO active)
        // Only if we allow reviving canceled orders
        if (!in_array($newStatus, ['canceled', 'rejected']) && in_array($oldStatus, ['canceled', 'rejected'])) {
            foreach ($order->items as $item) {
                if ($item->product->stock < $item->quantity) {
                    return response()->json(['message' => "No hay suficiente stock para reactivar el pedido para el producto: {$item->product->name}"], 400);
                }
                $item->product->decrement('stock', $item->quantity);
            }
        }

        $order->update(['status' => $newStatus]);
        return response()->json($order);
    }

    /**
     * Update tracking information for an order
     */
    public function updateTracking(Request $request, $id)
    {
        $order = Order::findOrFail($id);

        $validated = $request->validate([
            'tracking_status' => 'required|string',
            'tracking_notes' => 'nullable|string',
            'estimated_delivery_date' => 'nullable|date',
        ]);

        // Validate status transitions
        $validTransitions = $this->getValidTransitions($order->delivery_type);
        if (!in_array($validated['tracking_status'], $validTransitions)) {
            return response()->json([
                'message' => 'Invalid tracking status for this delivery type'
            ], 422);
        }

        // Update tracking fields
        $order->update($validated);

        // Auto-update delivered_at when status changes to delivered/picked_up
        if (in_array($validated['tracking_status'], ['delivered', 'picked_up']) && !$order->delivered_at) {
            $order->update(['delivered_at' => now()]);
        }

        return response()->json([
            'message' => 'Tracking updated successfully',
            'order' => $order
        ]);
    }

    private function getValidTransitions($deliveryType)
    {
        return $deliveryType === 'delivery'
            ? ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'canceled']
            : ['pending', 'confirmed', 'ready_for_pickup', 'picked_up', 'canceled'];
    }
}
