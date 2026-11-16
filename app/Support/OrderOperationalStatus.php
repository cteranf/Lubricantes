<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * The canonical read-time classification for operational order states.
 * Keep the SQL expression and the in-memory resolver in this class so list
 * filtering and serialized presentation use the same precedence.
 */
final class OrderOperationalStatus
{
    private const VALID = [
        Order::FULFILLMENT_RESERVED,
        Order::FULFILLMENT_PREPARING,
        Order::FULFILLMENT_READY,
        Order::FULFILLMENT_DELIVERED,
        Order::FULFILLMENT_CANCELED,
    ];

    public static function resolve(Order $order): string
    {
        $fulfillment = $order->getAttribute('fulfillment_status');
        $status = $order->getAttribute('status');
        $tracking = $order->getAttribute('tracking_status');
        $deliveryStatus = $order->relationLoaded('delivery')
            ? $order->getRelation('delivery')?->getAttribute('status')
            : null;

        if ($fulfillment === Order::FULFILLMENT_DELIVERED
            || $status === 'delivered'
            || in_array($tracking, ['delivered', 'picked_up'], true)
            || $order->getAttribute('delivered_at') !== null
            || $order->getAttribute('picked_up_at') !== null
            || $deliveryStatus === 'delivered') {
            return Order::FULFILLMENT_DELIVERED;
        }

        if ($fulfillment === Order::FULFILLMENT_CANCELED
            || in_array($status, ['canceled', 'rejected'], true)
            || $tracking === 'canceled') {
            return Order::FULFILLMENT_CANCELED;
        }

        if (in_array($fulfillment, self::VALID, true)) {
            return $fulfillment;
        }

        if ($tracking === 'processing') {
            return Order::FULFILLMENT_PREPARING;
        }

        if (in_array($tracking, ['ready_for_pickup', 'shipped'], true)
            || in_array($deliveryStatus, ['dispatched', 'out_for_delivery', 'awaiting_pickup', 'failed_attempt', 'rescheduled'], true)
            || $status === 'shipped') {
            return Order::FULFILLMENT_READY;
        }

        return Order::FULFILLMENT_RESERVED;
    }

    public static function applyFilter(Builder $query, string $status): void
    {
        if (! in_array($status, self::VALID, true)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereRaw('('.self::sqlExpression().') = ?', [$status]);
    }

    private static function sqlExpression(): string
    {
        return <<<'SQL'
CASE
    WHEN orders.fulfillment_status = 'delivered'
        OR orders.status = 'delivered'
        OR orders.tracking_status IN ('delivered', 'picked_up')
        OR orders.delivered_at IS NOT NULL
        OR orders.picked_up_at IS NOT NULL
        OR EXISTS (
            SELECT 1 FROM order_deliveries AS operational_deliveries
            WHERE operational_deliveries.order_id = orders.id
              AND operational_deliveries.status = 'delivered'
        ) THEN 'delivered'
    WHEN orders.fulfillment_status = 'canceled'
        OR orders.status IN ('canceled', 'rejected')
        OR orders.tracking_status = 'canceled' THEN 'canceled'
    WHEN orders.fulfillment_status IN ('reserved', 'preparing', 'ready')
        THEN orders.fulfillment_status
    WHEN orders.tracking_status = 'processing' THEN 'preparing'
    WHEN orders.tracking_status IN ('ready_for_pickup', 'shipped')
        OR EXISTS (
            SELECT 1 FROM order_deliveries AS operational_delivery_progress
            WHERE operational_delivery_progress.order_id = orders.id
              AND operational_delivery_progress.status IN ('dispatched', 'out_for_delivery', 'awaiting_pickup', 'failed_attempt', 'rescheduled')
        )
        OR orders.status = 'shipped' THEN 'ready'
    ELSE 'reserved'
END
SQL;
    }
}
