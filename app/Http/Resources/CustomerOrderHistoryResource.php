<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerOrderHistoryResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        return [
            'id' => (int) $order->id,
            'created_at' => $order->created_at?->toIso8601String(),
            'status' => $order->status,
            'tracking_status' => $order->tracking_status,
            'fulfillment_status' => $order->effectiveFulfillmentStatus(),
            'delivery_type' => $order->delivery_type,
            'pickup_branch_name_snapshot' => $order->pickup_branch_name_snapshot,
            'pickup_district_snapshot' => $order->pickup_district_snapshot,
            'pickup_deadline_at' => $order->pickup_deadline_at?->toIso8601String(),
            'pickup_deadline_status' => $order->pickupDeadlineStatus(),
            'shipping_district_snapshot' => $order->shipping_district_snapshot,
            'shipping_zone_name_snapshot' => $order->shipping_zone_name_snapshot,
            'shipping_amount' => self::decimalAmount($order->shipping_amount),
            'total' => self::decimalAmount($order->total),
            'items' => $order->items->map(static function (OrderItem $item): array {
                $product = $item->product;

                return [
                    'quantity' => (int) $item->quantity,
                    'price' => self::decimalAmount($item->price),
                    'subtotal' => self::decimalAmount($item->subtotal),
                    'product' => $product ? [
                        'name' => $product->name,
                        'image_url' => $product->image_url,
                    ] : null,
                ];
            })->values()->all(),
        ];
    }

    private static function decimalAmount(mixed $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 2, '.', '');
    }
}
