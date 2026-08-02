<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use App\Services\OrderDeliveryService;

class OrderTrackingController extends Controller
{
    public function __construct(private OrderDeliveryService $delivery) {}
    /**
     * Get tracking information for a specific order
     */
    public function show(Request $request, $id)
    {
        $order = Order::with(['items.product.images', 'items.warehouse', 'items.reservation', 'user'])->findOrFail($id);

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
                'fulfillment_status' => $order->effectiveFulfillmentStatus(),
                'tracking_notes' => $order->tracking_notes,
                'estimated_delivery_date' => $order->estimated_delivery_date?->format('Y-m-d'),
                'delivered_at' => $this->iso($order->delivered_at),
                'created_at' => $this->iso($order->created_at),
                'total' => $order->total,
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status,
                'shipping_info' => $order->shipping_info,
                'items' => $order->items->map(function ($item) {
                    return [
                        'name' => $item->product?->name ?? 'Producto no disponible',
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                        'image_url' => $item->product?->image_url,
                        'warehouse' => $item->warehouse ? ['id'=>$item->warehouse->id,'name'=>$item->warehouse->name] : null,
                        'reservation' => $item->reservation ? [
                            'status'=>$item->reservation->status,
                            'quantity'=>$item->reservation->quantity,
                            'expires_at'=>$this->iso($item->reservation->expires_at),
                            'consumed_at'=>$this->iso($item->reservation->consumed_at),
                            'released_at'=>$this->iso($item->reservation->released_at),
                        ] : null,
                    ];
                }),
            ],
            'timeline' => $this->fulfillmentTimeline($order),
            'delivery' => $this->delivery->getCustomerTracking($order),
        ]);
    }

    private function fulfillmentTimeline(Order $order): array
    {
        $state=$order->effectiveFulfillmentStatus();
        if ($state===Order::FULFILLMENT_CANCELED) return [
            ['status'=>'received','label'=>'Pedido recibido','completed'=>true,'active'=>false,'icon'=>'pi pi-check','date'=>$this->iso($order->created_at)],
            ['status'=>'canceled','label'=>'Cancelado','completed'=>false,'active'=>true,'icon'=>'pi pi-times-circle','date'=>null],
        ];
        $rank=['reserved'=>0,'preparing'=>1,'ready'=>2,'delivered'=>3]; $current=$rank[$state]??0;
        $steps=[['status'=>'received','label'=>'Pedido recibido','rank'=>0,'icon'=>'pi pi-shopping-cart','date'=>$this->iso($order->created_at)]];
        if ($order->payment_method!=='contra_entrega') $steps[]=['status'=>'payment','label'=>'Pago confirmado','rank'=>0,'icon'=>'pi pi-credit-card','date'=>$this->iso($order->paid_at),'payment'=>true];
        $steps[]=['status'=>'preparing','label'=>'En preparación','rank'=>1,'icon'=>'pi pi-cog','date'=>$this->iso($order->preparing_at)];
        $steps[]=['status'=>'ready','label'=>'Listo para entrega','rank'=>2,'icon'=>'pi pi-box','date'=>$this->iso($order->ready_at)];
        $delivery=$this->delivery->getCustomerTracking($order);
        if($delivery){
            $deliverySteps=$this->deliverySteps($delivery);
            foreach($deliverySteps as $step)$steps[]=$step;
        }
        $steps[]=['status'=>'delivered','label'=>'Entregado','rank'=>3,'icon'=>'pi pi-check-circle','date'=>$this->iso($order->delivered_at)];
        if ($order->payment_method==='contra_entrega' && $order->payment_status==='approved') $steps[]=['status'=>'payment','label'=>'Pago confirmado','rank'=>3,'icon'=>'pi pi-wallet','date'=>$this->iso($order->paid_at),'payment'=>true];
        return collect($steps)->map(function($step)use($current,$state,$order,$delivery){
            $payment=$step['payment']??false;
            if($step['delivery_step']??false){$flow=$delivery['method']==='store_pickup'?['awaiting_pickup','delivered']:['dispatched','out_for_delivery','failed_attempt','rescheduled','delivered'];$now=array_search($delivery['status'],$flow,true);$at=array_search($step['status'],$flow,true);$step['completed']=$now!==false&&$at!==false&&$now>$at;$step['active']=$step['status']===$delivery['status'];}
            else{$step['completed']=$payment?$order->payment_status==='approved':$current>$step['rank'];$step['active']=$payment?$state==='reserved'&&$order->payment_status==='approved':$step['status']===$state||($step['status']==='received'&&$state==='reserved'&&$order->payment_status!=='approved');}
            unset($step['rank'],$step['payment'],$step['delivery_step']);return $step;
        })->all();
    }

    private function deliverySteps(array $delivery): array
    {
        $status=$delivery['status'];$steps=[];
        if($delivery['method']==='store_pickup'&&in_array($status,['awaiting_pickup','delivered'],true))$steps[]=['status'=>'awaiting_pickup','label'=>'Esperando recojo','rank'=>2,'delivery_step'=>true,'icon'=>'pi pi-shopping-bag','date'=>$delivery['scheduled_at']];
        if($delivery['method']!=='store_pickup'&&in_array($status,['dispatched','out_for_delivery','failed_attempt','rescheduled','delivered'],true))$steps[]=['status'=>'dispatched','label'=>'Despachado','rank'=>2,'delivery_step'=>true,'icon'=>'pi pi-truck','date'=>null];
        if(in_array($status,['out_for_delivery','failed_attempt','rescheduled','delivered'],true))$steps[]=['status'=>'out_for_delivery','label'=>'En camino','rank'=>2,'delivery_step'=>true,'icon'=>'pi pi-send','date'=>null];
        if($status==='failed_attempt')$steps[]=['status'=>'failed_attempt','label'=>'Intento de entrega no completado','rank'=>2,'delivery_step'=>true,'icon'=>'pi pi-exclamation-triangle','date'=>null];
        if($status==='rescheduled')$steps[]=['status'=>'rescheduled','label'=>'Entrega reprogramada','rank'=>2,'delivery_step'=>true,'icon'=>'pi pi-calendar','date'=>$delivery['scheduled_at']];
        return $steps;
    }

    private function iso($date): ?string
    {
        return $date?->copy()->utc()->toIso8601String();
    }
}
