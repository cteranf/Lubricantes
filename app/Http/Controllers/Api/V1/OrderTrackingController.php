<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class OrderTrackingController extends Controller
{
    /**
     * Get tracking information for a specific order
     */
    public function show(Request $request, $id)
    {
        $order = Order::with(['items.product', 'items.warehouse', 'items.reservation', 'user'])->findOrFail($id);

        $user = $request->user();

        // Hide the existence of another customer's order while allowing admins.
        if (! $user->isAdmin() && $user->id !== $order->user_id) {
            abort(404);
        }

        return response()->json([
            'order' => [
                'id' => $order->id,
                'order_number' => '#'.str_pad($order->id, 6, '0', STR_PAD_LEFT),
                'status' => $order->status,
                'delivery_type' => $order->delivery_type,
                'tracking_status' => $order->tracking_status,
                'tracking_notes' => $order->tracking_notes,
                'estimated_delivery_date' => $order->estimated_delivery_date?->format('Y-m-d'),
                'delivered_at' => $order->delivered_at?->format('Y-m-d H:i'),
                'created_at' => $order->created_at->format('Y-m-d H:i'),
                'total' => $order->total,
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status,
                'shipping_info' => $order->shipping_info,
                'items' => $order->items->map(function ($item) {
                    return [
                        'name' => $item->product?->name ?? 'Producto no disponible',
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                        'image' => $item->product?->image_path,
                        'warehouse' => $item->warehouse ? ['id'=>$item->warehouse->id,'name'=>$item->warehouse->name] : null,
                        'reservation' => $item->reservation ? [
                            'status'=>$item->reservation->status,
                            'quantity'=>$item->reservation->quantity,
                            'expires_at'=>$item->reservation->expires_at?->toIso8601String(),
                            'consumed_at'=>$item->reservation->consumed_at?->toIso8601String(),
                            'released_at'=>$item->reservation->released_at?->toIso8601String(),
                        ] : null,
                    ];
                }),
            ],
            'timeline' => $order->getTrackingTimeline(),
        ]);
    }
}
