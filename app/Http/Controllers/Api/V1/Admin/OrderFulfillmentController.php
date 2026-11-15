<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminOrderFulfillmentResource;
use App\Models\Order;
use App\Models\OrderHandlingItem;
use App\Services\OrderFulfillmentService;
use Illuminate\Http\Request;

class OrderFulfillmentController extends Controller
{
    public function __construct(private OrderFulfillmentService $fulfillment) {}

    public function show(Request $request, Order $order)
    {
        $presentation = $this->fulfillment->history($order);
        $presentation['order']->load([
            'user:id,name',
            'items' => fn ($items) => $items->select([
                'order_items.id',
                'order_items.order_id',
                'order_items.product_id',
                'order_items.product_name',
                'order_items.warehouse_id',
                'order_items.quantity',
                'order_items.price',
            ]),
            'items.product:id,name',
            'items.warehouse:id,name',
            'items.reservation:inventory_reservations.id,inventory_reservations.order_item_id,inventory_reservations.status',
        ]);
        $presentation['fulfillment']['picking_packing']['items'] = collect($presentation['fulfillment']['picking_packing']['items'] ?? [])
            ->map(fn ($item) => $this->handlingItemForResource($item))
            ->filter()
            ->values();

        return response()->json(AdminOrderFulfillmentResource::make($presentation)->resolve($request));
    }

    private function handlingItemForResource(mixed $item): ?OrderHandlingItem
    {
        if ($item instanceof OrderHandlingItem) {
            return $item;
        }
        if (! is_array($item)) {
            return null;
        }

        $allowed = [
            'order_item_id', 'product_id', 'warehouse_id', 'product_name', 'product_sku',
            'product_presentation', 'ordered_quantity', 'picked_quantity', 'packed_quantity',
            'pending_picking', 'pending_packing', 'confirmation_method', 'last_operated_at',
            'observation',
        ];
        $handlingItem = new OrderHandlingItem(array_intersect_key($item, array_flip($allowed)));
        $handlingItem->setAttribute('id', $item['id'] ?? null);
        $handlingItem->setAttribute('image_url', $item['image_url'] ?? null);
        $handlingItem->setAttribute('warehouse', $item['warehouse'] ?? null);
        $handlingItem->setAttribute('last_operated_by', $item['last_operated_by'] ?? null);

        return $handlingItem;
    }

    public function approveTransfer(Request $request, Order $order)
    {
        return response()->json($this->fulfillment->approveTransfer($order, $request->user()));
    }

    public function startPreparation(Request $request, Order $order)
    {
        $data = $this->note($request);

        return response()->json($this->fulfillment->startPreparation($order, $request->user(), $data['observation'] ?? null));
    }

    public function ready(Request $request, Order $order)
    {
        $data = $this->note($request);

        return response()->json($this->fulfillment->markAsReady($order, $request->user(), $data['observation'] ?? null));
    }

    public function readyForPickup(Request $request, Order $order)
    {
        if (! $order->isPickup()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['fulfillment' => ['Solo los pedidos con recojo en sede usan esta acción.']]);
        }$data = $this->note($request);

        return response()->json($this->fulfillment->markAsReady($order, $request->user(), $data['observation'] ?? null));
    }

    public function pickedUp(Request $request, Order $order)
    {
        $data = $request->validate(['observation' => ['nullable', 'string', 'max:500'], 'money_received' => ['nullable', 'boolean'], 'collection_method' => ['nullable', 'in:cash,card_terminal,bank_transfer,other']]);

        return response()->json($this->fulfillment->markAsPickedUp($order, $request->user(), $data['observation'] ?? null, (bool) ($data['money_received'] ?? false), $data['collection_method'] ?? null));
    }

    public function delivered(Request $request, Order $order)
    {
        $data = $this->note($request);

        return response()->json($this->fulfillment->markAsDelivered($order, $request->user(), $data['observation'] ?? null));
    }

    public function cancel(Request $request, Order $order)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->fulfillment->cancelFulfillment($order, $request->user(), $data['reason']));
    }

    private function note(Request $request): array
    {
        return $request->validate(['observation' => ['nullable', 'string', 'max:500']]);
    }
}
