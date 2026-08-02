<?php

namespace App\Services;

use App\Exceptions\InventoryException;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderFulfillmentHistory;
use App\Models\OrderDelivery;
use App\Models\OrderDeliveryHistory;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderFulfillmentService
{
    public function __construct(private InventoryService $inventory, private OrderPickingPackingService $handling) {}

    public function startPreparation(Order $order, User $user, ?string $observation = null): array
    {
        return DB::transaction(function () use ($order,$user,$observation) {
            $order=$this->lock($order); $from=$order->effectiveFulfillmentStatus();
            if ($from === Order::FULFILLMENT_PREPARING) { $this->handling->initializeForOrder($order); return $this->present($order); }
            $this->requireState($from, Order::FULFILLMENT_RESERVED, 'Solo un pedido reservado puede iniciar preparacion.');
            if (in_array($order->status,['canceled','rejected','delivered'],true)) $this->invalid('El pedido finalizado no puede prepararse.');

            if ($this->inventory->orderUsesReservationFlow($order)) {
                $reservations=$order->reservations()->lockForUpdate()->get();
                if ($reservations->count() !== $order->items()->count()) $this->invalid('El pedido no tiene reservas validas para preparar.');
                if ($reservations->contains('status',InventoryReservation::EXPIRED) || $reservations->contains('status',InventoryReservation::RELEASED)) $this->invalid('La reserva vencio o fue liberada; el pedido no puede prepararse.');
                if ($reservations->contains('status',InventoryReservation::ACTIVE)) {
                    if ($order->payment_method !== 'contra_entrega' && $order->payment_status !== 'approved') $this->invalid('El pago debe estar aprobado antes de preparar este pedido.');
                    try { $this->inventory->consumeOrderReservation($order,$user); }
                    catch (InventoryException $e) { $this->invalid($e->getMessage()); }
                }
                if ($order->reservations()->where('status','!=',InventoryReservation::CONSUMED)->exists()) $this->invalid('El inventario del pedido no esta completamente consumido.');
            }

            $order->update(['fulfillment_status'=>Order::FULFILLMENT_PREPARING,'preparing_at'=>$order->preparing_at ?: now(),'prepared_by'=>$order->prepared_by ?: $user->id,'status'=>'confirmed','tracking_status'=>'processing']);
            $this->handling->initializeForOrder($order);
            $this->record($order,$from,Order::FULFILLMENT_PREPARING,$user,$observation,['payment_method'=>$order->payment_method]);
            return $this->present($order->refresh());
        });
    }

    public function approveTransfer(Order $order, User $user): array
    {
        return DB::transaction(function () use ($order,$user) {
            $order=$this->lock($order);
            if ($order->payment_method!=='transferencia') $this->invalid('Solo los pedidos por transferencia se aprueban manualmente.');
            if ($order->payment_status==='approved') return $this->present($order);
            if ($order->effectiveFulfillmentStatus()!==Order::FULFILLMENT_RESERVED) $this->invalid('La transferencia debe aprobarse antes de iniciar preparacion.');
            if ($this->inventory->orderUsesReservationFlow($order)) {
                try { $this->inventory->consumeOrderReservation($order,$user); }
                catch (InventoryException $e) { $this->invalid($e->getMessage()); }
            }
            $order->update(['payment_status'=>'approved','paid_at'=>$order->paid_at ?: now(),'status'=>'confirmed','tracking_status'=>'confirmed']);
            return $this->present($order->refresh());
        });
    }

    public function markAsReady(Order $order, User $user, ?string $observation = null): array
    {
        return DB::transaction(function () use ($order,$user,$observation) {
            $order=$this->lock($order); $from=$order->effectiveFulfillmentStatus();
            if ($from === Order::FULFILLMENT_READY) return $this->present($order);
            $this->requireState($from,Order::FULFILLMENT_PREPARING,'Solo un pedido en preparacion puede marcarse como listo.');
            $this->handling->assertReadyForFulfillment($order);
            $this->assertInventoryConsumed($order);
            $order->update(['fulfillment_status'=>Order::FULFILLMENT_READY,'ready_at'=>$order->ready_at ?: now(),'ready_by'=>$order->ready_by ?: $user->id,'status'=>$order->delivery_type === 'pickup' ? 'confirmed' : 'shipped','tracking_status'=>$order->delivery_type === 'pickup' ? 'ready_for_pickup' : 'shipped']);
            $this->record($order,$from,Order::FULFILLMENT_READY,$user,$observation);
            return $this->present($order->refresh());
        });
    }

    public function markAsDelivered(Order $order, User $user, ?string $observation = null): array
    {
        return DB::transaction(function () use ($order,$user,$observation) {
            $order=$this->lock($order); $from=$order->effectiveFulfillmentStatus();
            if ($from === Order::FULFILLMENT_DELIVERED) return $this->present($order);
            if ($order->delivery_flow_version) {
                $delivery=OrderDelivery::where('order_id',$order->id)->lockForUpdate()->first();
                if (!$delivery || $delivery->status!==OrderDelivery::DELIVERED) $this->invalid('Los pedidos del nuevo flujo deben confirmarse desde Despacho y entrega.');
                return $this->finalizeLockedDelivery($order,$user,$observation,$delivery->delivered_at ?: now());
            }
            $this->requireState($from,Order::FULFILLMENT_READY,'Solo un pedido listo puede marcarse como entregado.');
            $this->assertInventoryConsumed($order);
            $deliveredAt = $order->delivered_at ?: now();
            $updates=['fulfillment_status'=>Order::FULFILLMENT_DELIVERED,'delivered_at'=>$deliveredAt,'delivered_by'=>$order->delivered_by ?: $user->id,'status'=>'delivered','tracking_status'=>$order->delivery_type === 'pickup' ? 'picked_up' : 'delivered'];
            if ($order->payment_method === 'contra_entrega') { $updates['payment_status']='approved'; $updates['paid_at']=$order->paid_at ?: $deliveredAt; }
            $order->update($updates);
            $this->record($order,$from,Order::FULFILLMENT_DELIVERED,$user,$observation,['payment_confirmed_on_delivery'=>$order->payment_method === 'contra_entrega']);
            return $this->present($order->refresh());
        });
    }

    public function finalizeDeliveredFromDelivery(Order $order, User $user, ?string $observation = null, $deliveredAt = null): array
    {
        return DB::transaction(function () use ($order,$user,$observation,$deliveredAt) {
            $order=$this->lock($order);
            if ($order->effectiveFulfillmentStatus()===Order::FULFILLMENT_DELIVERED) return $this->present($order);
            if (!$order->delivery_flow_version) $this->invalid('El pedido no pertenece al flujo de despacho vigente.');
            $delivery=OrderDelivery::where('order_id',$order->id)->lockForUpdate()->first();
            if (!$delivery || $delivery->status!==OrderDelivery::DELIVERED || !$delivery->confirmed_at || !$delivery->recipient_name || !$delivery->recipient_document_type || !$delivery->recipient_document_number) $this->invalid('La entrega logística y sus datos mínimos deben estar confirmados.');
            return $this->finalizeLockedDelivery($order,$user,$observation,$deliveredAt ?: $delivery->delivered_at ?: now());
        });
    }

    public function cancelFulfillment(Order $order, User $user, string $reason): array
    {
        return DB::transaction(function () use ($order,$user,$reason) {
            $order=$this->lock($order); $from=$order->effectiveFulfillmentStatus();
            if ($from === Order::FULFILLMENT_CANCELED) return $this->present($order);
            if ($from === Order::FULFILLMENT_DELIVERED) $this->invalid('Un pedido entregado no puede cancelarse desde este flujo.');
            $delivery=OrderDelivery::where('order_id',$order->id)->lockForUpdate()->first();
            if ($delivery && in_array($delivery->status,[OrderDelivery::DISPATCHED,OrderDelivery::OUT_FOR_DELIVERY,OrderDelivery::FAILED_ATTEMPT,OrderDelivery::RESCHEDULED,OrderDelivery::DELIVERED],true)) $this->invalid('El paquete ya salió; se requiere gestionar un retorno o devolución en una fase futura.');
            if ($delivery && $delivery->status!==OrderDelivery::CANCELED) {
                $deliveryFrom=$delivery->status;
                $delivery->update(['status'=>OrderDelivery::CANCELED,'canceled_at'=>now(),'updated_by'=>$user->id]);
                OrderDeliveryHistory::firstOrCreate(['idempotency_key'=>'delivery-order-'.$order->id.'-canceled'],['order_id'=>$order->id,'order_delivery_id'=>$delivery->id,'event_type'=>'delivery_canceled','from_status'=>$deliveryFrom,'to_status'=>OrderDelivery::CANCELED,'user_id'=>$user->id,'observation'=>$reason,'created_at'=>now()]);
            }

            if ($this->inventory->orderUsesReservationFlow($order)) {
                if ($order->reservations()->where('status',InventoryReservation::ACTIVE)->exists()) $this->inventory->releaseOrderReservation($order);
                $consumed=$order->reservations()->where('status',InventoryReservation::CONSUMED)->with(['orderItem.product','orderItem.warehouse'])->lockForUpdate()->get();
                foreach ($consumed as $reservation) $this->inventory->returnCancellation($reservation->orderItem,$user);
            } else {
                $order->items()->with(['product','warehouse'])->get()->each(fn($item)=>$this->inventory->returnCancellation($item,$user));
            }

            $this->handling->cancelForOrder($order,$user,$reason);
            $order->update(['fulfillment_status'=>Order::FULFILLMENT_CANCELED,'status'=>'canceled','tracking_status'=>'canceled']);
            $this->record($order,$from,Order::FULFILLMENT_CANCELED,$user,$reason,['inventory_was_consumed'=>$this->inventory->orderHasConsumedReservation($order)]);
            return $this->present($order->refresh());
        });
    }

    public function history(Order $order): array { return $this->present($order); }

    public function present(Order $order): array
    {
        $order->load(['user','items.product.images','items.warehouse','items.reservation','preparedBy:id,name','readyBy:id,name','deliveredBy:id,name','fulfillmentHistory.user:id,name']);
        $state=$order->effectiveFulfillmentStatus(); $legacy=!$this->inventory->orderUsesReservationFlow($order);
        $canStart=$state===Order::FULFILLMENT_RESERVED && ($legacy || $order->payment_method==='contra_entrega' || $order->payment_status==='approved');
        $handling=$this->handling->getOperationalSummary($order);
        $handlingAllowsReady=!($handling['available']??false)||($handling['actions']['mark_ready']??false);
        $hasDelivery=OrderDelivery::where('order_id',$order->id)->exists();
        $actions=['approve_payment'=>$state===Order::FULFILLMENT_RESERVED&&$order->payment_method==='transferencia'&&$order->payment_status!=='approved','start_preparation'=>$canStart,'mark_ready'=>$state===Order::FULFILLMENT_PREPARING&&$handlingAllowsReady,'mark_delivered'=>$state===Order::FULFILLMENT_READY&&!$order->delivery_flow_version,'manage_delivery'=>$state===Order::FULFILLMENT_READY&&!!$order->delivery_flow_version,'cancel'=>in_array($state,[Order::FULFILLMENT_RESERVED,Order::FULFILLMENT_PREPARING,Order::FULFILLMENT_READY],true)&&!$hasDelivery];
        $reservationStatuses=$order->items->pluck('reservation.status')->filter()->unique()->values();
        return ['order'=>$order,'fulfillment'=>[
            'status'=>$state,'label'=>$this->label($state),'actions'=>$actions,
            'preparing_at'=>$order->preparing_at?->toIso8601String(),'ready_at'=>$order->ready_at?->toIso8601String(),'delivered_at'=>$order->delivered_at?->toIso8601String(),
            'prepared_by'=>$order->preparedBy,'ready_by'=>$order->readyBy,'delivered_by'=>$order->deliveredBy,
            'payment_status'=>$order->payment_status,'reservation_status'=>$reservationStatuses->count()===1?$reservationStatuses->first():($reservationStatuses->isEmpty()?($legacy?'legacy':'none'):'mixed'),
            'warehouse'=>$order->items->first()?->warehouse,'expires_at'=>$order->reserved_until?->toIso8601String(),'history'=>$order->fulfillmentHistory,'picking_packing'=>$handling,
        ]];
    }

    private function lock(Order $order): Order { return Order::whereKey($order->id)->lockForUpdate()->firstOrFail(); }
    private function requireState(string $actual,string $expected,string $message): void { if($actual!==$expected)$this->invalid($message); }
    private function assertInventoryConsumed(Order $order): void { if($this->inventory->orderUsesReservationFlow($order)&&$order->reservations()->where('status','!=',InventoryReservation::CONSUMED)->exists())$this->invalid('El inventario debe estar consumido antes de continuar.'); }
    private function finalizeLockedDelivery(Order $order, User $user, ?string $observation, $deliveredAt): array
    {
        $from=$order->effectiveFulfillmentStatus();
        $this->requireState($from,Order::FULFILLMENT_READY,'Solo un pedido listo puede marcarse como entregado.');
        $this->assertInventoryConsumed($order);
        if($order->payment_method==='contra_entrega'){
            $payment=PaymentTransaction::where('order_id',$order->id)->where('payment_method','contra_entrega')->where('transaction_type',PaymentTransaction::PAYMENT)->where('status',PaymentTransaction::APPROVED)->first();
            if(!$payment)$this->invalid('El cobro contraentrega aprobado es obligatorio antes de finalizar la entrega.');
        }
        $order->update(['fulfillment_status'=>Order::FULFILLMENT_DELIVERED,'delivered_at'=>$deliveredAt,'delivered_by'=>$order->delivered_by ?: $user->id,'status'=>'delivered','tracking_status'=>$order->delivery_type === 'pickup' ? 'picked_up' : 'delivered']);
        $this->record($order,$from,Order::FULFILLMENT_DELIVERED,$user,$observation,['delivery_flow_version'=>$order->delivery_flow_version,'payment_confirmed_on_delivery'=>$order->payment_method==='contra_entrega']);
        return $this->present($order->refresh());
    }
    private function record(Order $order,string $from,string $to,User $user,?string $observation,array $metadata=[]): void { OrderFulfillmentHistory::firstOrCreate(['idempotency_key'=>'fulfillment-order-'.$order->id.'-'.$to],['order_id'=>$order->id,'from_status'=>$from,'to_status'=>$to,'user_id'=>$user->id,'observation'=>$observation?trim($observation):null,'metadata'=>$metadata,'created_at'=>now()]); }
    private function label(string $state): string { return match($state){'reserved'=>'Reservado','preparing'=>'En preparacion','ready'=>'Listo para entrega','delivered'=>'Entregado','canceled'=>'Cancelado',default=>$state}; }
    private function invalid(string $message): never { throw ValidationException::withMessages(['fulfillment'=>[$message]]); }
}
