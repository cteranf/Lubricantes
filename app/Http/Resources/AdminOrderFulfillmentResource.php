<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class AdminOrderFulfillmentResource extends JsonResource
{
    public function toArray($request): array
    {
        $payload = (array) $this->resource;
        $order = $payload['order'] ?? null;
        $fulfillment = is_array($payload['fulfillment'] ?? null) ? $payload['fulfillment'] : [];

        return [
            'order' => $this->order($order),
            'fulfillment' => $this->fulfillment($fulfillment),
        ];
    }

    private function order(mixed $order): array
    {
        if (! $order instanceof Model) {
            return [];
        }

        $items = $this->loadedRelation($order, 'items');
        $user = $this->person($this->loadedRelation($order, 'user'));
        $shippingInfo = $order->getAttribute('shipping_info');

        return [
            'id' => $order->getAttribute('id'),
            'user' => $user,
            'items' => $this->models($items)->map(fn (Model $item) => [
                'id' => $item->getAttribute('id'),
                'product' => $this->product($item),
                'warehouse' => $this->namedRelation($item, 'warehouse'),
                'reservation' => $this->statusRelation($item, 'reservation'),
                'quantity' => $item->getAttribute('quantity'),
                'price' => $item->getAttribute('price'),
            ])->values()->all(),
            'payment_method' => $order->getAttribute('payment_method'),
            'payment_status' => $order->getAttribute('payment_status'),
            'delivery_type' => $order->getAttribute('delivery_type'),
            'has_payment_submission' => (bool) $order->getAttribute('has_payment_submission'),
            'payment_submission_status' => $order->getAttribute('payment_submission_status'),
            'pickup_branch_name_snapshot' => $order->getAttribute('pickup_branch_name_snapshot'),
            'pickup_address_snapshot' => $order->getAttribute('pickup_address_snapshot'),
            'pickup_district_snapshot' => $order->getAttribute('pickup_district_snapshot'),
            'pickup_business_hours_snapshot' => $order->getAttribute('pickup_business_hours_snapshot'),
            'pickup_instructions_snapshot' => $order->getAttribute('pickup_instructions_snapshot'),
            'shipping_info' => is_array($shippingInfo) && array_key_exists('address', $shippingInfo)
                ? ['address' => $shippingInfo['address']]
                : null,
            'shipping_district_snapshot' => $order->getAttribute('shipping_district_snapshot'),
            'shipping_zone_name_snapshot' => $order->getAttribute('shipping_zone_name_snapshot'),
            'shipping_amount' => $order->getAttribute('shipping_amount'),
            'shipping_estimated_days_min_snapshot' => $order->getAttribute('shipping_estimated_days_min_snapshot'),
            'shipping_estimated_days_max_snapshot' => $order->getAttribute('shipping_estimated_days_max_snapshot'),
        ];
    }

    private function fulfillment(array $fulfillment): array
    {
        $actions = is_array($fulfillment['actions'] ?? null) ? $fulfillment['actions'] : [];
        $projectedActions = [];
        foreach ([
            'approve_payment', 'start_preparation', 'mark_ready', 'mark_ready_for_pickup',
            'mark_picked_up', 'mark_delivered', 'manage_delivery', 'cancel',
        ] as $key) {
            $projectedActions[$key] = (bool) ($actions[$key] ?? false);
        }

        return [
            'status' => $fulfillment['status'] ?? null,
            'payment_status' => $fulfillment['payment_status'] ?? null,
            'label' => $fulfillment['label'] ?? null,
            'actions' => $projectedActions,
            'preparing_at' => $fulfillment['preparing_at'] ?? null,
            'ready_at' => $fulfillment['ready_at'] ?? null,
            'delivered_at' => $fulfillment['delivered_at'] ?? null,
            'ready_for_pickup_at' => $fulfillment['ready_for_pickup_at'] ?? null,
            'pickup_deadline_at' => $fulfillment['pickup_deadline_at'] ?? null,
            'picked_up_at' => $fulfillment['picked_up_at'] ?? null,
            'pickup_deadline_status' => $fulfillment['pickup_deadline_status'] ?? null,
            'prepared_by' => $this->person($fulfillment['prepared_by'] ?? null),
            'ready_by' => $this->person($fulfillment['ready_by'] ?? null),
            'delivered_by' => $this->person($fulfillment['delivered_by'] ?? null),
            'reservation_status' => $fulfillment['reservation_status'] ?? null,
            'warehouse' => $this->named($fulfillment['warehouse'] ?? null),
            'expires_at' => $fulfillment['expires_at'] ?? null,
            'history' => $this->fulfillmentHistory($fulfillment['history'] ?? null),
            'picking_packing' => $this->handling($fulfillment['picking_packing'] ?? null),
        ];
    }

    private function handling(mixed $summary): ?array
    {
        if (! is_array($summary)) {
            return null;
        }

        return [
            'available' => (bool) ($summary['available'] ?? false),
            'legacy' => (bool) ($summary['legacy'] ?? false),
            'fulfillment_status' => $summary['fulfillment_status'] ?? null,
            'message' => $summary['message'] ?? null,
            'picking' => $this->operation($summary['picking'] ?? null),
            'packing' => $this->operation($summary['packing'] ?? null),
            'actions' => $this->handlingActions($summary['actions'] ?? null),
            'items' => $this->handlingItems($summary['items'] ?? null),
            'incidents' => $this->incidents($summary['incidents'] ?? null),
            'history' => $this->handlingHistory($summary['history'] ?? null),
            'canceled_at' => $summary['canceled_at'] ?? null,
        ];
    }

    private function operation(mixed $operation): ?array
    {
        if (! is_array($operation)) {
            return null;
        }

        return [
            'status' => $operation['status'] ?? null,
            'started_at' => $operation['started_at'] ?? null,
            'completed_at' => $operation['completed_at'] ?? null,
            'started_by' => $this->person($operation['started_by'] ?? null),
            'completed_by' => $this->person($operation['completed_by'] ?? null),
            'observation' => $operation['observation'] ?? null,
            'progress' => $operation['progress'] ?? 0,
        ];
    }

    private function handlingActions(mixed $actions): array
    {
        $actions = is_array($actions) ? $actions : [];
        $allowed = [
            'start_picking', 'update_picking', 'complete_picking', 'start_packing',
            'update_packing', 'complete_packing', 'report_incident', 'mark_ready',
        ];

        return array_combine($allowed, array_map(fn (string $key) => (bool) ($actions[$key] ?? false), $allowed));
    }

    private function handlingItems(mixed $items): array
    {
        return $this->models($items)->map(function (mixed $item): ?array {
            if (! $item instanceof Model) {
                return null;
            }

            return [
                'id' => $this->read($item, 'id'),
                'order_item_id' => $this->read($item, 'order_item_id'),
                'product_name' => $this->read($item, 'product_name'),
                'product_sku' => $this->read($item, 'product_sku'),
                'product_presentation' => $this->read($item, 'product_presentation'),
                'image_url' => $this->read($item, 'image_url'),
                'warehouse' => $this->handlingWarehouse($this->read($item, 'warehouse')),
                'ordered_quantity' => $this->read($item, 'ordered_quantity'),
                'picked_quantity' => $this->read($item, 'picked_quantity'),
                'packed_quantity' => $this->read($item, 'packed_quantity'),
                'pending_picking' => $this->read($item, 'pending_picking'),
                'pending_packing' => $this->read($item, 'pending_packing'),
                'confirmation_method' => $this->read($item, 'confirmation_method'),
                'last_operated_by' => $this->person($this->read($item, 'last_operated_by')),
                'last_operated_at' => $this->read($item, 'last_operated_at'),
                'observation' => $this->read($item, 'observation'),
            ];
        })->filter()->values()->all();
    }

    private function incidents(mixed $incidents): array
    {
        return $this->models($incidents)->map(function (mixed $incident): ?array {
            if (! $incident instanceof Model) {
                return null;
            }

            return [
                'id' => $incident->getAttribute('id'),
                'type' => $incident->getAttribute('type'),
                'affected_quantity' => $incident->getAttribute('affected_quantity'),
                'description' => $incident->getAttribute('description'),
                'status' => $incident->getAttribute('status'),
                'reported_at' => $incident->getAttribute('reported_at'),
                'reporter' => $this->person($this->loadedRelation($incident, 'reporter')),
            ];
        })->filter()->values()->all();
    }

    private function fulfillmentHistory(mixed $history): array
    {
        return $this->models($history)->map(function (mixed $entry): ?array {
            if (! $entry instanceof Model) {
                return null;
            }

            return [
                'id' => $entry->getAttribute('id'),
                'from_status' => $entry->getAttribute('from_status'),
                'to_status' => $entry->getAttribute('to_status'),
                'user' => $this->person($this->loadedRelation($entry, 'user')),
                'observation' => $entry->getAttribute('observation'),
                'created_at' => $entry->getAttribute('created_at'),
            ];
        })->values()->all();
    }

    private function handlingHistory(mixed $history): array
    {
        return $this->models($history)->map(function (mixed $entry): ?array {
            if (! $entry instanceof Model) {
                return null;
            }

            return [
                'id' => $entry->getAttribute('id'),
                'event_type' => $entry->getAttribute('event_type'),
                'user' => $this->person($this->loadedRelation($entry, 'user')),
                'confirmation_method' => $entry->getAttribute('confirmation_method'),
                'observation' => $entry->getAttribute('observation'),
                'created_at' => $entry->getAttribute('created_at'),
            ];
        })->filter()->values()->all();
    }

    private function handlingWarehouse(mixed $warehouse): ?array
    {
        if ($warehouse instanceof Model) {
            $branch = $this->loadedRelation($warehouse, 'branch');

            return [
                'name' => $warehouse->getAttribute('name'),
                'branch' => $this->named($branch),
            ];
        }
        if (is_array($warehouse)) {
            return [
                'name' => $warehouse['name'] ?? null,
                'branch' => $this->named($warehouse['branch'] ?? null),
            ];
        }

        return null;
    }

    private function person(mixed $person): ?array
    {
        if (! $person instanceof Model) {
            return null;
        }

        return ['name' => $person->getAttribute('name')];
    }

    private function namedRelation(Model $model, string $relation): ?array
    {
        return $this->named($this->loadedRelation($model, $relation));
    }

    private function product(Model $item): array
    {
        $product = $this->loadedRelation($item, 'product');

        return ['name' => $product instanceof Model
            ? $product->getAttribute('name')
            : ($item->getAttribute('product_name') ?: 'Producto no disponible')];
    }

    private function statusRelation(Model $model, string $relation): ?array
    {
        $related = $this->loadedRelation($model, $relation);
        if (! $related instanceof Model) {
            return null;
        }

        return ['status' => $related->getAttribute('status')];
    }

    private function named(mixed $value): ?array
    {
        if ($value instanceof Model) {
            return ['name' => $value->getAttribute('name')];
        }
        if (is_array($value) && array_key_exists('name', $value)) {
            return ['name' => $value['name']];
        }

        return null;
    }

    private function loadedRelation(Model $model, string $relation): mixed
    {
        return $model->relationLoaded($relation) ? $model->getRelation($relation) : null;
    }

    private function models(mixed $items): Collection
    {
        if ($items instanceof Collection) {
            return $items;
        }
        if (is_array($items)) {
            return collect($items);
        }

        return collect();
    }

    private function read(mixed $source, string $key): mixed
    {
        if (is_array($source)) {
            return $source[$key] ?? null;
        }
        if ($source instanceof Model) {
            return $source->getAttribute($key);
        }

        return null;
    }
}
