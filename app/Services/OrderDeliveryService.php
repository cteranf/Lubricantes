<?php

namespace App\Services;

use App\Events\OrderDeliveryEvent;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderDeliveryAttempt;
use App\Models\OrderDeliveryHistory;
use App\Models\OrderHandlingIncident;
use App\Models\OrderHandlingProcess;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderDeliveryService
{
    private const FAILURES=['recipient_absent','incorrect_address','recipient_rejected','unable_to_contact','access_restricted','damaged_package','courier_issue','weather_or_traffic','other'];

    public function __construct(private OrderPaymentService $payments, private OrderFulfillmentService $fulfillment) {}

    public function initializeForOrder(Order $order, User $user): array
    {
        return DB::transaction(function()use($order,$user){
            $order=$this->lockOrder($order);
            if($existing=OrderDelivery::where('order_id',$order->id)->lockForUpdate()->first())return $this->summary($order,$existing);
            if($order->effectiveFulfillmentStatus()!==Order::FULFILLMENT_READY)$this->invalid('El despacho solo puede inicializarse para un pedido listo.');
            $this->assertReady($order);
            $info=$order->shipping_info??[];
            $method=$order->delivery_type==='pickup'?OrderDelivery::STORE_PICKUP:OrderDelivery::OWN_DELIVERY;
            $delivery=OrderDelivery::create([
                'order_id'=>$order->id,'method'=>$method,'status'=>OrderDelivery::PENDING,'created_by'=>$user->id,'updated_by'=>$user->id,
                'recipient_snapshot_name'=>$order->user?->name,'recipient_snapshot_phone'=>$info['phone']??$order->user?->phone,
                'recipient_snapshot_email'=>$order->user?->email,'destination_address'=>$info['address']??null,
                'destination_reference'=>$info['reference']??null,'destination_district'=>$info['district']??($info['city']??null),
                'destination_province'=>$info['province']??null,'destination_department'=>$info['department']??null,
                'destination_postal_code'=>$info['postal_code']??null,'destination_metadata'=>$info,
            ]);
            if(!$order->delivery_flow_version)$order->update(['delivery_flow_version'=>1]);
            $this->record($order,$delivery,'delivery_initialized',null,OrderDelivery::PENDING,$user,'delivery-order-'.$order->id.'-initialized');
            return $this->summary($order->refresh(),$delivery);
        });
    }

    public function selectMethod(Order $order, User $user, string $method): array
    {
        return $this->write($order,$user,function($order,$d)use($method,$user){
            if(!in_array($method,OrderDelivery::METHODS,true))$this->invalid('La modalidad de entrega no es válida.');
            if(!in_array($d->status,[OrderDelivery::PENDING,OrderDelivery::SCHEDULED],true)||$d->attempts()->exists()||$d->assigned_at)$this->invalid('La modalidad ya no puede cambiarse porque el despacho tiene actividad.');
            if($d->method===$method)return;
            $from=$d->method;
            $d->update(['method'=>$method,'status'=>OrderDelivery::PENDING,'pickup_warehouse_id'=>null,'pickup_location_name'=>null,'pickup_location_address'=>null,'delivery_user_id'=>null,'delivery_user_name'=>null,'delivery_user_phone'=>null,'assigned_at'=>null,'assigned_by'=>null,'courier_code'=>null,'courier_name'=>null,'courier_service'=>null,'tracking_number'=>null,'tracking_url'=>null,'courier_reference'=>null,'updated_by'=>$user->id]);
            $order->update(['delivery_type'=>$method===OrderDelivery::STORE_PICKUP?'pickup':'delivery']);
            $sequence=OrderDeliveryHistory::where('order_delivery_id',$d->id)->where('event_type','delivery_method_selected')->count()+1;
            $this->record($order,$d,'delivery_method_selected',$d->status,$d->status,$user,'delivery-order-'.$order->id.'-method-change-'.$sequence,null,null,['from_method'=>$from,'to_method'=>$method]);
        });
    }

    public function schedulePickup(Order $order, User $user, Warehouse $warehouse, ?string $scheduledAt, ?string $window, ?string $authorizedPerson, ?string $authorizedDocument): array
    {
        return $this->write($order,$user,function($order,$d)use($warehouse,$scheduledAt,$window,$authorizedPerson,$authorizedDocument,$user){
            if($d->method!==OrderDelivery::STORE_PICKUP)$this->invalid('El despacho no corresponde a recojo en tienda.');
            $warehouse=Warehouse::with('branch')->whereKey($warehouse->id)->where('is_active',true)->whereHas('branch',fn($q)=>$q->where('is_active',true))->first();
            if(!$warehouse)$this->invalid('El punto de recojo no está activo.');
            $address=$warehouse->address?:$warehouse->branch?->address;
            if(!$address)$this->invalid('El punto de recojo no tiene dirección configurada.');
            $from=$d->status;
            if(!in_array($from,[OrderDelivery::PENDING,OrderDelivery::SCHEDULED,OrderDelivery::AWAITING_PICKUP],true))$this->invalid('El recojo no puede programarse desde el estado actual.');
            $d->update(['pickup_warehouse_id'=>$warehouse->id,'pickup_location_name'=>$warehouse->name.' — '.$warehouse->branch->name,'pickup_location_address'=>$address,'pickup_authorized_person'=>$authorizedPerson,'pickup_authorized_document'=>$authorizedDocument,'scheduled_at'=>$scheduledAt,'time_window'=>$window,'status'=>OrderDelivery::AWAITING_PICKUP,'started_at'=>$d->started_at?:now(),'updated_by'=>$user->id]);
            $this->record($order,$d,'pickup_scheduled',$from,OrderDelivery::AWAITING_PICKUP,$user,'delivery-order-'.$order->id.'-pickup-scheduled-'.($scheduledAt?:'now'));
        });
    }

    public function assignOwnDriver(Order $order, User $user, User $driver, ?string $scheduledAt, ?string $vehiclePlate): array
    {
        return $this->write($order,$user,function($order,$d)use($driver,$scheduledAt,$vehiclePlate,$user){
            if($d->method!==OrderDelivery::OWN_DELIVERY)$this->invalid('El despacho no corresponde a reparto propio.');
            $driver=User::whereKey($driver->id)->where('role','admin')->where('can_deliver',true)->first();
            if(!$driver)$this->invalid('El usuario no está habilitado como repartidor.');
            if(!in_array($d->status,[OrderDelivery::PENDING,OrderDelivery::SCHEDULED,OrderDelivery::RESCHEDULED,OrderDelivery::FAILED_ATTEMPT,OrderDelivery::ASSIGNED],true))$this->invalid('No se puede asignar un repartidor en el estado actual.');
            $from=$d->status;$d->update(['delivery_user_id'=>$driver->id,'delivery_user_name'=>$driver->name,'delivery_user_phone'=>$driver->phone,'vehicle_plate'=>$vehiclePlate,'scheduled_at'=>$scheduledAt?:$d->scheduled_at,'assigned_at'=>$d->assigned_at?:now(),'assigned_by'=>$user->id,'status'=>OrderDelivery::ASSIGNED,'updated_by'=>$user->id]);
            $this->record($order,$d,'driver_assigned',$from,OrderDelivery::ASSIGNED,$user,'delivery-order-'.$order->id.'-driver-'.$driver->id.'-'.($d->attempts()->count()+1));
        });
    }

    public function assignExternalCourier(Order $order, User $user, array $data): array
    {
        return $this->write($order,$user,function($order,$d)use($data,$user){
            if($d->method!==OrderDelivery::EXTERNAL_COURIER)$this->invalid('El despacho no corresponde a courier externo.');
            if(!in_array($d->status,[OrderDelivery::PENDING,OrderDelivery::SCHEDULED,OrderDelivery::RESCHEDULED,OrderDelivery::FAILED_ATTEMPT,OrderDelivery::ASSIGNED],true))$this->invalid('No se puede asignar courier en el estado actual.');
            $from=$d->status;$d->update(['courier_code'=>$data['courier_code']??null,'courier_name'=>trim($data['courier_name']),'courier_service'=>$data['courier_service']??null,'tracking_number'=>$data['tracking_number']??null,'tracking_url'=>$data['tracking_url']??null,'courier_reference'=>$data['courier_reference']??null,'status'=>OrderDelivery::ASSIGNED,'assigned_at'=>$d->assigned_at?:now(),'assigned_by'=>$user->id,'updated_by'=>$user->id]);
            $this->record($order,$d,'courier_assigned',$from,OrderDelivery::ASSIGNED,$user,'delivery-order-'.$order->id.'-courier-'.Str::slug($d->courier_name).'-'.($d->attempts()->count()+1));
        });
    }

    public function updateCourierTracking(Order $order, User $user, array $data): array
    {
        return $this->write($order,$user,function($order,$d)use($data,$user){
            if($d->method!==OrderDelivery::EXTERNAL_COURIER)$this->invalid('El tracking solo corresponde al courier externo.');
            if(in_array($d->status,[OrderDelivery::DELIVERED,OrderDelivery::CANCELED],true))$this->invalid('El despacho finalizado no puede modificarse.');
            $d->update(array_filter(['tracking_number'=>$data['tracking_number']??null,'tracking_url'=>$data['tracking_url']??null,'courier_reference'=>$data['courier_reference']??null,'external_status'=>$data['external_status']??null,'updated_by'=>$user->id],fn($v)=>$v!==null));
            $this->record($order,$d,'tracking_registered',$d->status,$d->status,$user,'delivery-order-'.$order->id.'-tracking-'.sha1(json_encode($data)),null,null,$data);
        });
    }

    public function dispatch(Order $order, User $user): array
    {
        return $this->write($order,$user,function($order,$d)use($user){
            if($d->method===OrderDelivery::STORE_PICKUP)$this->invalid('El recojo en tienda no utiliza despacho a reparto.');
            if($d->status===OrderDelivery::DISPATCHED)return;
            if($d->status!==OrderDelivery::ASSIGNED)$this->invalid('Debe existir una asignación antes del despacho.');
            if($d->method===OrderDelivery::OWN_DELIVERY&&!$d->delivery_user_id)$this->invalid('Debe asignar un repartidor.');
            if($d->method===OrderDelivery::EXTERNAL_COURIER&&(!$d->courier_name||!$d->tracking_number))$this->invalid('Courier y número de guía son obligatorios antes del despacho.');
            $d->update(['status'=>OrderDelivery::DISPATCHED,'dispatched_at'=>$d->dispatched_at?:now(),'handed_to_courier_at'=>$d->method===OrderDelivery::EXTERNAL_COURIER?($d->handed_to_courier_at?:now()):$d->handed_to_courier_at,'updated_by'=>$user->id]);
            $this->record($order,$d,'dispatched',OrderDelivery::ASSIGNED,OrderDelivery::DISPATCHED,$user,'delivery-order-'.$order->id.'-dispatched');
        });
    }

    public function startAttempt(Order $order, User $user, ?string $scheduledAt=null): array
    {
        return $this->write($order,$user,function($order,$d)use($scheduledAt,$user){
            if($d->method===OrderDelivery::STORE_PICKUP)$this->invalid('El recojo en tienda no crea intentos de reparto.');
            if(!in_array($d->status,[OrderDelivery::DISPATCHED,OrderDelivery::RESCHEDULED],true))$this->invalid('No se puede iniciar un intento desde el estado actual.');
            if($d->attempts()->where('status',OrderDeliveryAttempt::IN_PROGRESS)->exists())return;
            $attempt=$d->attempts()->where('status',OrderDeliveryAttempt::SCHEDULED)->latest('attempt_number')->lockForUpdate()->first();
            if($attempt){$attempt->update(['status'=>OrderDeliveryAttempt::IN_PROGRESS,'started_at'=>now(),'responsible_user_id'=>$d->delivery_user_id,'reported_by'=>$user->id,'reported_at'=>now()]);$number=$attempt->attempt_number;}
            else{$number=(int)$d->attempts()->max('attempt_number')+1;$attempt=OrderDeliveryAttempt::create(['order_delivery_id'=>$d->id,'attempt_number'=>$number,'status'=>OrderDeliveryAttempt::IN_PROGRESS,'scheduled_at'=>$scheduledAt?:$d->scheduled_at,'started_at'=>now(),'responsible_user_id'=>$d->delivery_user_id,'reported_by'=>$user->id,'reported_at'=>now()]);}
            $this->record($order,$d,'delivery_attempt_started',$d->status,$d->status,$user,'delivery-order-'.$order->id.'-attempt-'.$number.'-started',$attempt);
        });
    }

    public function markOutForDelivery(Order $order, User $user): array
    {
        return $this->write($order,$user,function($order,$d)use($user){
            if($d->status===OrderDelivery::OUT_FOR_DELIVERY)return;
            if($d->status!==OrderDelivery::DISPATCHED&&$d->status!==OrderDelivery::RESCHEDULED)$this->invalid('El pedido debe estar despachado antes de salir a reparto.');
            if(!$d->attempts()->where('status',OrderDeliveryAttempt::IN_PROGRESS)->exists())$this->invalid('Debe iniciar un intento de entrega.');
            $from=$d->status;$d->update(['status'=>OrderDelivery::OUT_FOR_DELIVERY,'out_for_delivery_at'=>now(),'updated_by'=>$user->id]);
            $this->record($order,$d,'out_for_delivery',$from,OrderDelivery::OUT_FOR_DELIVERY,$user,'delivery-order-'.$order->id.'-out-'.$d->attempts()->max('attempt_number'));
        });
    }

    public function failAttempt(Order $order, OrderDeliveryAttempt $attempt, User $user, string $reason, string $description, ?string $location=null): array
    {
        return $this->write($order,$user,function($order,$d)use($attempt,$reason,$description,$location,$user){
            if(!in_array($reason,self::FAILURES,true))$this->invalid('El motivo del intento fallido no es válido.');
            $attempt=OrderDeliveryAttempt::whereKey($attempt->id)->where('order_delivery_id',$d->id)->lockForUpdate()->firstOrFail();
            if($attempt->status===OrderDeliveryAttempt::FAILED)return;
            if($attempt->status!==OrderDeliveryAttempt::IN_PROGRESS||$d->status!==OrderDelivery::OUT_FOR_DELIVERY)$this->invalid('Solo un intento activo y en reparto puede fallar.');
            $attempt->update(['status'=>OrderDeliveryAttempt::FAILED,'finished_at'=>now(),'failure_reason'=>$reason,'failure_description'=>trim($description),'location_reference'=>$location,'reported_by'=>$user->id,'reported_at'=>now()]);
            $d->update(['status'=>OrderDelivery::FAILED_ATTEMPT,'updated_by'=>$user->id]);
            $this->record($order,$d,'delivery_attempt_failed',OrderDelivery::OUT_FOR_DELIVERY,OrderDelivery::FAILED_ATTEMPT,$user,'delivery-order-'.$order->id.'-attempt-'.$attempt->attempt_number.'-failed',$attempt,$description,['reason'=>$reason]);
        });
    }

    public function reschedule(Order $order, User $user, string $scheduledAt, ?string $observation=null): array
    {
        return $this->write($order,$user,function($order,$d)use($scheduledAt,$observation,$user){
            if($d->status!==OrderDelivery::FAILED_ATTEMPT)$this->invalid('Solo un intento fallido puede reprogramarse.');
            $number=(int)$d->attempts()->max('attempt_number')+1;
            $attempt=OrderDeliveryAttempt::create(['order_delivery_id'=>$d->id,'attempt_number'=>$number,'status'=>OrderDeliveryAttempt::SCHEDULED,'scheduled_at'=>$scheduledAt,'responsible_user_id'=>$d->delivery_user_id,'reported_by'=>$user->id,'reported_at'=>now()]);
            $d->update(['status'=>OrderDelivery::RESCHEDULED,'scheduled_at'=>$scheduledAt,'updated_by'=>$user->id]);
            $this->record($order,$d,'delivery_rescheduled',OrderDelivery::FAILED_ATTEMPT,OrderDelivery::RESCHEDULED,$user,'delivery-order-'.$order->id.'-attempt-'.$number.'-rescheduled',$attempt,$observation);
        });
    }

    public function confirmDelivery(Order $order, User $user, array $data, array $files=[]): array
    {
        $stored=[];
        try{return DB::transaction(function()use($order,$user,$data,$files,&$stored){
            $order=$this->lockOrder($order);$d=$this->lockDelivery($order);
            if($d->status===OrderDelivery::DELIVERED)return $this->summary($order,$d);
            $valid=$d->method===OrderDelivery::STORE_PICKUP?$d->status===OrderDelivery::AWAITING_PICKUP:$d->status===OrderDelivery::OUT_FOR_DELIVERY;
            if(!$valid)$this->invalid('La entrega no puede confirmarse desde el estado actual.');
            if($d->method!==OrderDelivery::STORE_PICKUP&&!$d->attempts()->where('status',OrderDeliveryAttempt::IN_PROGRESS)->exists())$this->invalid('No existe un intento activo para confirmar.');
            if(empty($data['recipient_name'])||empty($data['recipient_document_type'])||empty($data['recipient_document_number']))$this->invalid('Nombre, tipo y número de documento del receptor son obligatorios.');
            $method=$data['confirmation_method']??'manual';
            if($method==='confirmation_code'&&empty($data['confirmation_code']))$this->invalid('El código de confirmación es obligatorio para este método.');
            if($method==='signature'&&!isset($files['recipient_signature'])&&!$d->recipient_signature_path)$this->invalid('La firma es obligatoria para este método.');
            if($method==='photo'&&!isset($files['delivery_photo'])&&!$d->delivery_photo_path)$this->invalid('La fotografía es obligatoria para este método.');
            if($order->payment_method==='contra_entrega'){
                if(empty($data['money_received']))$this->invalid('Debe confirmar que el cobro contraentrega fue recibido.');
                $this->payments->confirmCashOnDeliveryCollection($order,$user,$data['collection_method']??'cash',$data['manual_reference']??null,$data['collected_at']??null,$user);
                if(!$this->payments->approvedCashOnDeliveryFor($order))$this->invalid('No existe un cobro contraentrega aprobado.');
            }
            foreach(['delivery_photo'=>'delivery_photo_path','recipient_signature'=>'recipient_signature_path','delivery_constancy'=>'delivery_constancy_path'] as $input=>$column)if(isset($files[$input])&&$files[$input] instanceof UploadedFile){$path=$files[$input]->store('delivery-evidence/'.$order->id,'local');if(!$path)throw new \RuntimeException('No se pudo almacenar la evidencia.');$stored[]=$path;$d->{$column}=$path;}
            $from=$d->status;$at=now();$key='delivery-confirm-order-'.$order->id;
            $d->fill(['status'=>OrderDelivery::DELIVERED,'delivered_at'=>$at,'picked_up_at'=>$d->method===OrderDelivery::STORE_PICKUP?$at:null,'recipient_name'=>trim($data['recipient_name']),'recipient_document_type'=>$data['recipient_document_type'],'recipient_document_number'=>trim($data['recipient_document_number']),'relationship_to_customer'=>$data['relationship_to_customer']??null,'confirmation_code'=>$data['confirmation_code']??null,'delivery_notes'=>$data['delivery_notes']??null,'confirmation_method'=>$data['confirmation_method']??'manual','confirmed_by'=>$user->id,'confirmed_at'=>$at,'confirmation_idempotency_key'=>$key,'updated_by'=>$user->id])->save();
            if($attempt=$d->attempts()->where('status',OrderDeliveryAttempt::IN_PROGRESS)->lockForUpdate()->first())$attempt->update(['status'=>OrderDeliveryAttempt::DELIVERED,'finished_at'=>$at,'reported_by'=>$user->id,'reported_at'=>$at]);
            $this->record($order,$d,'delivery_completed',$from,OrderDelivery::DELIVERED,$user,$key,$attempt??null,$data['delivery_notes']??null,['confirmation_method'=>$d->confirmation_method,'evidence'=>array_keys($files)]);
            $this->fulfillment->finalizeDeliveredFromDelivery($order,$user,$data['delivery_notes']??null,$at);
            return $this->summary($order->refresh(),$d->refresh());
        });}catch(\Throwable $e){foreach($stored as $path)Storage::disk('local')->delete($path);throw $e;}
    }

    public function cancelDelivery(Order $order, User $user, string $reason): array
    {
        return $this->write($order,$user,function($order,$d)use($reason,$user){
            if(in_array($d->status,[OrderDelivery::DISPATCHED,OrderDelivery::OUT_FOR_DELIVERY,OrderDelivery::FAILED_ATTEMPT,OrderDelivery::RESCHEDULED,OrderDelivery::DELIVERED],true))$this->invalid('El paquete ya salió; se requiere un flujo futuro de retorno o devolución.');
            if($d->status===OrderDelivery::CANCELED)return;
            $from=$d->status;$d->update(['status'=>OrderDelivery::CANCELED,'canceled_at'=>now(),'updated_by'=>$user->id]);
            $this->record($order,$d,'delivery_canceled',$from,OrderDelivery::CANCELED,$user,'delivery-order-'.$order->id.'-canceled',null,$reason);
            $this->fulfillment->cancelFulfillment($order,$user,$reason);
        });
    }

    public function getOperationalSummary(Order $order): array
    {
        if(!$order->delivery_flow_version&&$order->effectiveFulfillmentStatus()===Order::FULFILLMENT_DELIVERED)return ['available'=>false,'legacy'=>true,'status'=>'delivered','message'=>'Pedido entregado antes del flujo de despacho.'];
        $d=OrderDelivery::where('order_id',$order->id)->first();
        return $d?$this->summary($order,$d):['available'=>false,'legacy'=>!$order->delivery_flow_version,'can_initialize'=>$order->effectiveFulfillmentStatus()===Order::FULFILLMENT_READY];
    }

    public function getCustomerTracking(Order $order): ?array
    {
        $d=OrderDelivery::where('order_id',$order->id)->first();if(!$d)return null;
        return ['method'=>$d->method,'status'=>$d->status,'scheduled_at'=>$this->iso($d->scheduled_at),'time_window'=>$d->time_window,'pickup'=>$d->method===OrderDelivery::STORE_PICKUP?['name'=>$d->pickup_location_name,'address'=>$d->pickup_location_address]:null,'courier'=>$d->method===OrderDelivery::EXTERNAL_COURIER?['name'=>$d->courier_name,'tracking_number'=>$d->tracking_number,'tracking_url'=>$d->tracking_url]:null,'last_failure'=>$d->attempts()->where('status',OrderDeliveryAttempt::FAILED)->latest('attempt_number')->first(['failure_reason','finished_at']),'delivered_at'=>$this->iso($d->delivered_at)];
    }

    private function write(Order $order,User $user,callable $callback):array{return DB::transaction(function()use($order,$user,$callback){$order=$this->lockOrder($order);$d=$this->lockDelivery($order);$callback($order,$d);return $this->summary($order->refresh(),$d->refresh());});}
    private function lockOrder(Order $order):Order{return Order::with('user')->whereKey($order->id)->lockForUpdate()->firstOrFail();}
    private function lockDelivery(Order $order):OrderDelivery{$d=OrderDelivery::where('order_id',$order->id)->lockForUpdate()->first();if(!$d)$this->invalid('Inicialice el despacho antes de continuar.');return $d;}
    private function assertReady(Order $order):void{$process=OrderHandlingProcess::where('order_id',$order->id)->first();if($process&&($process->picking_status!==OrderHandlingProcess::COMPLETED||$process->packing_status!==OrderHandlingProcess::COMPLETED||$process->canceled_at))$this->invalid('Picking y packing deben estar completos.');if(OrderHandlingIncident::where('order_id',$order->id)->where('status','open')->exists())$this->invalid('Existen incidencias operativas abiertas.');if($order->reservations()->where('status','!=',InventoryReservation::CONSUMED)->exists())$this->invalid('El inventario debe estar consumido antes del despacho.');}
    private function record(Order $o,OrderDelivery $d,string $event,?string $from,?string $to,User $u,string $key,?OrderDeliveryAttempt $attempt=null,?string $observation=null,array $metadata=[]):void{$history=OrderDeliveryHistory::firstOrCreate(['idempotency_key'=>$key],['order_id'=>$o->id,'order_delivery_id'=>$d->id,'order_delivery_attempt_id'=>$attempt?->id,'event_type'=>$event,'from_status'=>$from,'to_status'=>$to,'user_id'=>$u->id,'observation'=>$observation,'metadata'=>$metadata,'created_at'=>now()]);if($history->wasRecentlyCreated)$this->afterCommit($o,$event);}
    private function afterCommit(Order $o,string $event):void{DB::afterCommit(function()use($o,$event){try{event(new OrderDeliveryEvent($o->id,$event));}catch(\Throwable $e){\Illuminate\Support\Facades\Log::warning('Delivery notification hook failed',['order_id'=>$o->id,'event'=>$event,'message'=>$e->getMessage()]);}});}
    private function summary(Order $o,OrderDelivery $d):array{$d->load(['pickupWarehouse.branch','deliveryUser:id,name,phone','creator:id,name','updater:id,name','assigner:id,name','confirmer:id,name','attempts.responsible:id,name','attempts.reporter:id,name','history.user:id,name']);$payment=$this->payments->approvedCashOnDeliveryFor($o);$s=$d->status;$mutable=in_array($s,[OrderDelivery::PENDING,OrderDelivery::SCHEDULED],true)&&!$d->attempts->count()&&!$d->assigned_at;return ['available'=>true,'legacy'=>false,'order'=>['id'=>$o->id,'fulfillment_status'=>$o->effectiveFulfillmentStatus(),'payment_method'=>$o->payment_method,'payment_status'=>$o->payment_status],'delivery'=>$d,'payment_transaction'=>$this->paymentSummary($payment),'actions'=>['change_method'=>$mutable,'schedule_pickup'=>$d->method===OrderDelivery::STORE_PICKUP&&in_array($s,[OrderDelivery::PENDING,OrderDelivery::SCHEDULED,OrderDelivery::AWAITING_PICKUP],true),'assign_driver'=>$d->method===OrderDelivery::OWN_DELIVERY&&in_array($s,[OrderDelivery::PENDING,OrderDelivery::SCHEDULED,OrderDelivery::FAILED_ATTEMPT,OrderDelivery::RESCHEDULED,OrderDelivery::ASSIGNED],true),'assign_courier'=>$d->method===OrderDelivery::EXTERNAL_COURIER&&in_array($s,[OrderDelivery::PENDING,OrderDelivery::SCHEDULED,OrderDelivery::FAILED_ATTEMPT,OrderDelivery::RESCHEDULED,OrderDelivery::ASSIGNED],true),'dispatch'=>$s===OrderDelivery::ASSIGNED,'start_attempt'=>in_array($s,[OrderDelivery::DISPATCHED,OrderDelivery::RESCHEDULED],true)&&!$d->attempts->contains('status',OrderDeliveryAttempt::IN_PROGRESS),'out_for_delivery'=>in_array($s,[OrderDelivery::DISPATCHED,OrderDelivery::RESCHEDULED],true)&&$d->attempts->contains('status',OrderDeliveryAttempt::IN_PROGRESS),'fail_attempt'=>$s===OrderDelivery::OUT_FOR_DELIVERY,'reschedule'=>$s===OrderDelivery::FAILED_ATTEMPT,'confirm_delivery'=>($d->method===OrderDelivery::STORE_PICKUP&&$s===OrderDelivery::AWAITING_PICKUP)||($d->method!==OrderDelivery::STORE_PICKUP&&$s===OrderDelivery::OUT_FOR_DELIVERY),'cancel'=>in_array($s,[OrderDelivery::PENDING,OrderDelivery::SCHEDULED,OrderDelivery::ASSIGNED,OrderDelivery::AWAITING_PICKUP],true)]];}
    private function paymentSummary($payment):?array{if(!$payment)return null;$payment->loadMissing(['collector:id,name','confirmer:id,name']);return ['id'=>$payment->id,'status'=>$payment->status,'amount'=>$payment->amount,'currency'=>$payment->currency,'collection_method'=>$payment->collection_method,'manual_reference'=>$payment->manual_reference,'collected_at'=>$this->iso($payment->collected_at),'collector'=>$payment->collector,'confirmed_at'=>$this->iso($payment->confirmed_at),'confirmer'=>$payment->confirmer];}
    private function iso($date):?string{return $date?->copy()->utc()->toIso8601String();}
    private function invalid(string $message):never{throw ValidationException::withMessages(['delivery'=>[$message]]);}
}
