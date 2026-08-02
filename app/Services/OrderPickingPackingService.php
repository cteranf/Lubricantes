<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderHandlingHistory;
use App\Models\OrderHandlingIncident;
use App\Models\OrderHandlingItem;
use App\Models\OrderHandlingProcess;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderPickingPackingService
{
    public const MANUAL = 'manual';
    public const CONFIRMATION_METHODS = ['manual', 'barcode', 'sku', 'ean', 'upc', 'internal_code', 'physical_reader', 'camera'];

    public function isAvailable(): bool
    {
        return Schema::hasTable('order_handling_processes');
    }

    public function initializeForOrder(Order $order): ?OrderHandlingProcess
    {
        if (! $this->isAvailable()) return null;

        return DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            return $this->initializeLocked($order);
        });
    }

    public function startPicking(Order $order, User $user, ?string $observation = null): array
    {
        return DB::transaction(function () use ($order, $user, $observation) {
            [$order, $process] = $this->lockProcess($order);
            $this->assertOperable($order, $process);
            $this->requireFulfillment($order, Order::FULFILLMENT_PREPARING, 'El picking solo puede iniciar cuando el pedido está en preparación.');
            if ($process->picking_status === OrderHandlingProcess::COMPLETED || $process->picking_status === OrderHandlingProcess::IN_PROGRESS) return $this->summary($order, $process);

            $process->update([
                'picking_status'=>OrderHandlingProcess::IN_PROGRESS,
                'picking_started_at'=>$process->picking_started_at ?: now(),
                'picking_started_by'=>$process->picking_started_by ?: $user->id,
                'picking_observation'=>$this->clean($observation),
            ]);
            $this->record($order, 'picking_started', $user, 'handling-order-'.$order->id.'-picking-start', null, null, $observation);
            return $this->summary($order, $process->refresh());
        });
    }

    public function updatePickedQuantity(Order $order, OrderItem $orderItem, int $quantity, User $user, ?string $observation = null, string $confirmationMethod = self::MANUAL): array
    {
        return DB::transaction(function () use ($order, $orderItem, $quantity, $user, $observation, $confirmationMethod) {
            [$order, $process] = $this->lockProcess($order);
            $this->assertOperable($order, $process);
            $this->requireFulfillment($order, Order::FULFILLMENT_PREPARING, 'El pedido no está disponible para picking.');
            $method = $this->confirmationMethod($confirmationMethod);
            $item = $this->lockItem($process, $orderItem);
            if ($item->picked_quantity === $quantity) return $this->summary($order, $process);
            if ($process->picking_status !== OrderHandlingProcess::IN_PROGRESS) $this->invalid('Debe iniciar el picking antes de registrar cantidades.');
            if ($quantity < 0) $this->invalid('La cantidad recogida no puede ser negativa.', 'picked_quantity');
            if ($quantity > $item->ordered_quantity) $this->invalid('La cantidad recogida no puede superar la cantidad solicitada.', 'picked_quantity');
            if ($quantity < $item->packed_quantity) $this->invalid('La cantidad recogida no puede ser menor que la cantidad ya empacada.', 'picked_quantity');

            $before = $item->picked_quantity;
            $item->update([
                'picked_quantity'=>$quantity, 'confirmation_method'=>$method,
                'last_operated_by'=>$user->id, 'last_operated_at'=>now(), 'observation'=>$this->clean($observation),
            ]);
            $this->record($order, 'picked_quantity_updated', $user, "handling-order-{$order->id}-picking-item-{$item->order_item_id}-quantity-{$quantity}", $item->order_item_id, null, $observation, ['before'=>$before, 'after'=>$quantity], $method);
            return $this->summary($order, $process);
        });
    }

    public function completePicking(Order $order, User $user, ?string $observation = null): array
    {
        return DB::transaction(function () use ($order, $user, $observation) {
            [$order, $process] = $this->lockProcess($order);
            $this->assertOperable($order, $process);
            $this->requireFulfillment($order, Order::FULFILLMENT_PREPARING, 'El pedido no está disponible para completar picking.');
            if ($process->picking_status === OrderHandlingProcess::COMPLETED) return $this->summary($order, $process);
            if ($process->picking_status !== OrderHandlingProcess::IN_PROGRESS) $this->invalid('Debe iniciar el picking antes de completarlo.');
            $this->assertNoOpenIncidents($order);
            if ($process->items()->whereColumn('picked_quantity', '<', 'ordered_quantity')->exists()) $this->invalid('Existen productos pendientes de recoger.');

            $process->update([
                'picking_status'=>OrderHandlingProcess::COMPLETED, 'picking_completed_at'=>$process->picking_completed_at ?: now(),
                'picking_completed_by'=>$process->picking_completed_by ?: $user->id,
                'picking_observation'=>$this->clean($observation) ?: $process->picking_observation,
            ]);
            $this->record($order, 'picking_completed', $user, 'handling-order-'.$order->id.'-picking-complete', null, null, $observation);
            return $this->summary($order, $process->refresh());
        });
    }

    public function startPacking(Order $order, User $user, ?string $observation = null): array
    {
        return DB::transaction(function () use ($order, $user, $observation) {
            [$order, $process] = $this->lockProcess($order);
            $this->assertOperable($order, $process);
            $this->requireFulfillment($order, Order::FULFILLMENT_PREPARING, 'El packing solo puede iniciar cuando el pedido está en preparación.');
            if ($process->packing_status === OrderHandlingProcess::COMPLETED || $process->packing_status === OrderHandlingProcess::IN_PROGRESS) return $this->summary($order, $process);
            if ($process->picking_status !== OrderHandlingProcess::COMPLETED) $this->invalid('Debe completar el picking antes de iniciar el packing.');
            $this->assertNoOpenIncidents($order);

            $process->update([
                'packing_status'=>OrderHandlingProcess::IN_PROGRESS,
                'packing_started_at'=>$process->packing_started_at ?: now(),
                'packing_started_by'=>$process->packing_started_by ?: $user->id,
                'packing_observation'=>$this->clean($observation),
            ]);
            $this->record($order, 'packing_started', $user, 'handling-order-'.$order->id.'-packing-start', null, null, $observation);
            return $this->summary($order, $process->refresh());
        });
    }

    public function updatePackedQuantity(Order $order, OrderItem $orderItem, int $quantity, User $user, ?string $observation = null, string $confirmationMethod = self::MANUAL): array
    {
        return DB::transaction(function () use ($order, $orderItem, $quantity, $user, $observation, $confirmationMethod) {
            [$order, $process] = $this->lockProcess($order);
            $this->assertOperable($order, $process);
            $this->requireFulfillment($order, Order::FULFILLMENT_PREPARING, 'El pedido no está disponible para packing.');
            $method = $this->confirmationMethod($confirmationMethod);
            $item = $this->lockItem($process, $orderItem);
            if ($item->packed_quantity === $quantity) return $this->summary($order, $process);
            if ($process->packing_status !== OrderHandlingProcess::IN_PROGRESS) $this->invalid('Debe iniciar el packing antes de registrar cantidades.');
            if ($quantity < 0) $this->invalid('La cantidad empacada no puede ser negativa.', 'packed_quantity');
            if ($quantity > $item->picked_quantity) $this->invalid('La cantidad empacada no puede superar la cantidad recogida.', 'packed_quantity');
            if ($quantity > $item->ordered_quantity) $this->invalid('La cantidad empacada no puede superar la cantidad solicitada.', 'packed_quantity');

            $before = $item->packed_quantity;
            $item->update([
                'packed_quantity'=>$quantity, 'confirmation_method'=>$method,
                'last_operated_by'=>$user->id, 'last_operated_at'=>now(), 'observation'=>$this->clean($observation),
            ]);
            $this->record($order, 'packed_quantity_updated', $user, "handling-order-{$order->id}-packing-item-{$item->order_item_id}-quantity-{$quantity}", $item->order_item_id, null, $observation, ['before'=>$before, 'after'=>$quantity], $method);
            return $this->summary($order, $process);
        });
    }

    public function completePacking(Order $order, User $user, ?string $observation = null): array
    {
        return DB::transaction(function () use ($order, $user, $observation) {
            [$order, $process] = $this->lockProcess($order);
            $this->assertOperable($order, $process);
            $this->requireFulfillment($order, Order::FULFILLMENT_PREPARING, 'El pedido no está disponible para completar packing.');
            if ($process->packing_status === OrderHandlingProcess::COMPLETED) return $this->summary($order, $process);
            if ($process->picking_status !== OrderHandlingProcess::COMPLETED) $this->invalid('Debe completar el picking antes de completar el packing.');
            if ($process->packing_status !== OrderHandlingProcess::IN_PROGRESS) $this->invalid('Debe iniciar el packing antes de completarlo.');
            $this->assertNoOpenIncidents($order);
            if ($process->items()->whereColumn('packed_quantity', '<', 'ordered_quantity')->exists()) $this->invalid('Existen productos pendientes de empacar.');

            $process->update([
                'packing_status'=>OrderHandlingProcess::COMPLETED, 'packing_completed_at'=>$process->packing_completed_at ?: now(),
                'packing_completed_by'=>$process->packing_completed_by ?: $user->id,
                'packing_observation'=>$this->clean($observation) ?: $process->packing_observation,
            ]);
            $this->record($order, 'packing_completed', $user, 'handling-order-'.$order->id.'-packing-complete', null, null, $observation);
            return $this->summary($order, $process->refresh());
        });
    }

    public function reportIncident(Order $order, User $user, string $type, string $description, ?OrderItem $orderItem = null, ?int $affectedQuantity = null, ?string $idempotencyKey = null): array
    {
        return DB::transaction(function () use ($order, $user, $type, $description, $orderItem, $affectedQuantity, $idempotencyKey) {
            [$order, $process] = $this->lockProcess($order);
            $this->assertOperable($order, $process);
            $this->requireFulfillment($order, Order::FULFILLMENT_PREPARING, 'Solo se pueden reportar incidencias durante la preparación.');
            if (! in_array($type, OrderHandlingIncident::TYPES, true)) $this->invalid('El tipo de incidencia no es válido.', 'type');
            if ($orderItem) $this->lockItem($process, $orderItem);
            if ($affectedQuantity !== null && $affectedQuantity < 0) $this->invalid('La cantidad afectada no puede ser negativa.', 'affected_quantity');
            $key = $idempotencyKey ?: 'handling-incident-'.Str::uuid();
            if ($existing = OrderHandlingIncident::where('idempotency_key', $key)->first()) {
                if ($existing->order_id !== $order->id) $this->invalid('La clave de idempotencia pertenece a otro pedido.');
                return $this->summary($order, $process);
            }

            $incident = OrderHandlingIncident::create([
                'order_id'=>$order->id, 'order_item_id'=>$orderItem?->id, 'type'=>$type,
                'affected_quantity'=>$affectedQuantity, 'description'=>trim($description), 'status'=>OrderHandlingIncident::OPEN,
                'reported_by'=>$user->id, 'reported_at'=>now(), 'idempotency_key'=>$key,
            ]);
            $this->record($order, 'incident_reported', $user, 'handling-incident-'.$incident->id.'-reported', $orderItem?->id, $incident->id, $description, ['type'=>$type, 'affected_quantity'=>$affectedQuantity]);
            return $this->summary($order, $process);
        });
    }

    public function resolveIncident(Order $order, OrderHandlingIncident $incident, User $user, string $observation): array
    {
        return DB::transaction(function () use ($order, $incident, $user, $observation) {
            [$order, $process] = $this->lockProcess($order);
            $this->assertOperable($order, $process);
            $incident = OrderHandlingIncident::whereKey($incident->id)->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            if ($incident->status === OrderHandlingIncident::RESOLVED) return $this->summary($order, $process);
            if ($incident->status !== OrderHandlingIncident::OPEN) $this->invalid('La incidencia ya no está abierta.');
            $incident->update(['status'=>OrderHandlingIncident::RESOLVED, 'resolved_by'=>$user->id, 'resolved_at'=>now(), 'resolution_observation'=>trim($observation)]);
            $this->record($order, 'incident_resolved', $user, 'handling-incident-'.$incident->id.'-resolved', $incident->order_item_id, $incident->id, $observation, ['type'=>$incident->type]);
            return $this->summary($order, $process);
        });
    }

    public function cancelForOrder(Order $order, User $user, string $reason): void
    {
        if (! $this->isAvailable()) return;
        $process = OrderHandlingProcess::where('order_id', $order->id)->lockForUpdate()->first();
        if (! $process || $process->canceled_at) return;
        $process->update(['canceled_at'=>now()]);
        $this->record($order, 'operation_canceled', $user, 'handling-order-'.$order->id.'-canceled', null, null, $reason);
    }

    public function assertReadyForFulfillment(Order $order): void
    {
        if (! $this->isAvailable()) return;
        $process = OrderHandlingProcess::where('order_id', $order->id)->lockForUpdate()->first();
        if (! $process) $process = $this->initializeLocked($order);
        $this->assertOperable($order, $process);
        if ($process->picking_status !== OrderHandlingProcess::COMPLETED) $this->invalid('Debe completar el picking antes de marcar el pedido como listo.');
        if ($process->items()->whereColumn('picked_quantity', '<', 'ordered_quantity')->exists()) $this->invalid('Existen productos pendientes de recoger.');
        if ($process->packing_status !== OrderHandlingProcess::COMPLETED) $this->invalid('Debe completar el packing antes de marcar el pedido como listo.');
        if ($process->items()->whereColumn('packed_quantity', '<', 'ordered_quantity')->exists()) $this->invalid('Existen productos pendientes de empacar.');
        $this->assertNoOpenIncidents($order);
    }

    public function getOperationalSummary(Order $order): array
    {
        if (! $this->isAvailable()) return $this->unavailableSummary($order);
        if (! $order->handlingProcess()->exists() && in_array($order->effectiveFulfillmentStatus(), [Order::FULFILLMENT_RESERVED, Order::FULFILLMENT_PREPARING], true)) {
            $this->initializeForOrder($order);
        }
        $process = OrderHandlingProcess::where('order_id', $order->id)->first();
        return $process ? $this->summary($order->refresh(), $process) : $this->legacySummary($order);
    }

    private function initializeLocked(Order $order): OrderHandlingProcess
    {
        $process = OrderHandlingProcess::firstOrCreate(['order_id'=>$order->id], ['picking_status'=>OrderHandlingProcess::PENDING, 'packing_status'=>OrderHandlingProcess::PENDING]);
        $items = $order->items()->with(['product.images', 'warehouse'])->orderBy('id')->lockForUpdate()->get();
        foreach ($items as $item) {
            OrderHandlingItem::firstOrCreate(
                ['order_handling_process_id'=>$process->id, 'order_item_id'=>$item->id],
                [
                    'product_id'=>$item->product_id, 'warehouse_id'=>$item->warehouse_id,
                    'product_name'=>$item->product?->name ?? 'Producto no disponible',
                    'product_sku'=>$item->product?->sku, 'product_presentation'=>$item->product?->presentation,
                    'warehouse_name'=>$item->warehouse?->name, 'ordered_quantity'=>$item->quantity,
                    'picked_quantity'=>0, 'packed_quantity'=>0, 'confirmation_method'=>self::MANUAL,
                ]
            );
        }
        return $process->refresh();
    }

    private function lockProcess(Order $order): array
    {
        if (! $this->isAvailable()) $this->invalid('La estructura de picking y packing aún no está disponible. Ejecute las migraciones pendientes.');
        $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
        $process = OrderHandlingProcess::where('order_id', $order->id)->lockForUpdate()->first();
        if (! $process) $process = $this->initializeLocked($order);
        return [$order, $process];
    }

    private function lockItem(OrderHandlingProcess $process, OrderItem $orderItem): OrderHandlingItem
    {
        // A future BarcodeResolver should resolve a scanned code to an OrderItem before
        // entering this shared validation path; quantity and state rules remain unchanged.
        if ($orderItem->order_id !== $process->order_id) abort(404);
        return OrderHandlingItem::where('order_handling_process_id', $process->id)->where('order_item_id', $orderItem->id)->lockForUpdate()->firstOrFail();
    }

    private function assertOperable(Order $order, OrderHandlingProcess $process): void
    {
        if ($process->canceled_at || $order->effectiveFulfillmentStatus() === Order::FULFILLMENT_CANCELED) $this->invalid('El pedido está cancelado y no permite continuar picking o packing.');
        if (in_array($order->effectiveFulfillmentStatus(), [Order::FULFILLMENT_READY, Order::FULFILLMENT_DELIVERED], true)) $this->invalid('El pedido ya finalizó su preparación.');
    }

    private function assertNoOpenIncidents(Order $order): void
    {
        if (OrderHandlingIncident::where('order_id', $order->id)->where('status', OrderHandlingIncident::OPEN)->exists()) $this->invalid('El pedido tiene incidencias abiertas que deben resolverse.');
    }

    private function requireFulfillment(Order $order, string $status, string $message): void
    {
        if ($order->effectiveFulfillmentStatus() !== $status) $this->invalid($message);
    }

    private function confirmationMethod(string $method): string
    {
        if (! in_array($method, self::CONFIRMATION_METHODS, true)) $this->invalid('El método de confirmación no es válido.');
        return $method;
    }

    private function record(Order $order, string $event, User $user, string $key, ?int $orderItemId = null, ?int $incidentId = null, ?string $observation = null, array $metadata = [], string $method = self::MANUAL): void
    {
        OrderHandlingHistory::firstOrCreate(['idempotency_key'=>$key], [
            'order_id'=>$order->id, 'order_item_id'=>$orderItemId, 'incident_id'=>$incidentId,
            'event_type'=>$event, 'user_id'=>$user->id, 'confirmation_method'=>$method,
            'observation'=>$this->clean($observation), 'metadata'=>$metadata, 'created_at'=>now(),
        ]);
    }

    private function summary(Order $order, OrderHandlingProcess $process): array
    {
        $process->load([
            'items.product.images', 'items.warehouse.branch:id,name', 'items.lastOperatedBy:id,name',
            'pickingStartedBy:id,name', 'pickingCompletedBy:id,name', 'packingStartedBy:id,name', 'packingCompletedBy:id,name',
        ]);
        $incidents = OrderHandlingIncident::with(['orderItem:id,product_id', 'reporter:id,name', 'resolver:id,name'])->where('order_id', $order->id)->orderByDesc('reported_at')->get();
        $history = OrderHandlingHistory::with('user:id,name')->where('order_id', $order->id)->orderBy('created_at')->get();
        $ordered = (int) $process->items->sum('ordered_quantity');
        $picked = (int) $process->items->sum('picked_quantity');
        $packed = (int) $process->items->sum('packed_quantity');
        $openIncidents = $incidents->where('status', OrderHandlingIncident::OPEN)->count();
        $preparing = $order->effectiveFulfillmentStatus() === Order::FULFILLMENT_PREPARING && ! $process->canceled_at;

        return [
            'available'=>true, 'legacy'=>false, 'fulfillment_status'=>$order->effectiveFulfillmentStatus(),
            'picking'=>[
                'status'=>$process->picking_status, 'started_at'=>$this->iso($process->picking_started_at), 'completed_at'=>$this->iso($process->picking_completed_at),
                'started_by'=>$process->pickingStartedBy, 'completed_by'=>$process->pickingCompletedBy, 'observation'=>$process->picking_observation,
                'progress'=>$ordered ? (int) floor(($picked / $ordered) * 100) : 0,
            ],
            'packing'=>[
                'status'=>$process->packing_status, 'started_at'=>$this->iso($process->packing_started_at), 'completed_at'=>$this->iso($process->packing_completed_at),
                'started_by'=>$process->packingStartedBy, 'completed_by'=>$process->packingCompletedBy, 'observation'=>$process->packing_observation,
                'progress'=>$ordered ? (int) floor(($packed / $ordered) * 100) : 0,
            ],
            'totals'=>['ordered'=>$ordered, 'picked'=>$picked, 'packed'=>$packed, 'pending_picking'=>max(0, $ordered-$picked), 'pending_packing'=>max(0, $ordered-$packed)],
            'actions'=>[
                'start_picking'=>$preparing && $process->picking_status===OrderHandlingProcess::PENDING,
                'update_picking'=>$preparing && $process->picking_status===OrderHandlingProcess::IN_PROGRESS,
                'complete_picking'=>$preparing && $process->picking_status===OrderHandlingProcess::IN_PROGRESS && $picked===$ordered && $openIncidents===0,
                'start_packing'=>$preparing && $process->picking_status===OrderHandlingProcess::COMPLETED && $process->packing_status===OrderHandlingProcess::PENDING && $openIncidents===0,
                'update_packing'=>$preparing && $process->packing_status===OrderHandlingProcess::IN_PROGRESS,
                'complete_packing'=>$preparing && $process->packing_status===OrderHandlingProcess::IN_PROGRESS && $packed===$ordered && $openIncidents===0,
                'report_incident'=>$preparing,
                'mark_ready'=>$preparing && $process->picking_status===OrderHandlingProcess::COMPLETED && $process->packing_status===OrderHandlingProcess::COMPLETED && $picked===$ordered && $packed===$ordered && $openIncidents===0,
            ],
            'canceled_at'=>$this->iso($process->canceled_at),
            'items'=>$process->items->map(fn (OrderHandlingItem $item) => [
                'id'=>$item->id, 'order_item_id'=>$item->order_item_id, 'product_id'=>$item->product_id, 'warehouse_id'=>$item->warehouse_id,
                'product_name'=>$item->product_name, 'product_sku'=>$item->product_sku, 'product_presentation'=>$item->product_presentation,
                'image_url'=>$item->product?->image_url, 'warehouse'=>$item->warehouse ? ['id'=>$item->warehouse->id, 'name'=>$item->warehouse->name, 'branch'=>$item->warehouse->branch] : ['id'=>null, 'name'=>$item->warehouse_name, 'branch'=>null],
                'ordered_quantity'=>$item->ordered_quantity, 'picked_quantity'=>$item->picked_quantity, 'packed_quantity'=>$item->packed_quantity,
                'pending_picking'=>max(0, $item->ordered_quantity-$item->picked_quantity), 'pending_packing'=>max(0, $item->ordered_quantity-$item->packed_quantity),
                'confirmation_method'=>$item->confirmation_method, 'last_operated_by'=>$item->lastOperatedBy,
                'last_operated_at'=>$this->iso($item->last_operated_at), 'observation'=>$item->observation,
            ])->values(),
            'incidents'=>$incidents, 'history'=>$history,
        ];
    }

    private function unavailableSummary(Order $order): array
    {
        return ['available'=>false, 'legacy'=>true, 'fulfillment_status'=>$order->effectiveFulfillmentStatus(), 'message'=>'Las migraciones de picking y packing están pendientes.'];
    }

    private function legacySummary(Order $order): array
    {
        return ['available'=>true, 'legacy'=>true, 'fulfillment_status'=>$order->effectiveFulfillmentStatus(), 'picking'=>null, 'packing'=>null, 'items'=>[], 'incidents'=>[], 'history'=>[], 'actions'=>[]];
    }

    private function iso($date): ?string { return $date?->copy()->utc()->toIso8601String(); }
    private function clean(?string $value): ?string { return $value === null || trim($value) === '' ? null : trim($value); }
    private function invalid(string $message, string $key = 'handling'): never { throw ValidationException::withMessages([$key=>[$message]]); }
}
