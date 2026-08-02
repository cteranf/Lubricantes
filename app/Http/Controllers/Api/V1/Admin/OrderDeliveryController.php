<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderDeliveryAttempt;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class OrderDeliveryController extends Controller
{
    public function __construct(private OrderDeliveryService $delivery) {}
    public function show(Order $order){return response()->json($this->delivery->getOperationalSummary($order));}
    public function initialize(Request $request,Order $order){return response()->json($this->delivery->initializeForOrder($order,$request->user()),201);}
    public function method(Request $request,Order $order){$d=$request->validate(['method'=>['required',Rule::in(['store_pickup','own_delivery','external_courier'])]]);return response()->json($this->delivery->selectMethod($order,$request->user(),$d['method']));}
    public function schedulePickup(Request $request,Order $order){$d=$request->validate(['warehouse_id'=>['required','integer','exists:warehouses,id'],'scheduled_at'=>['nullable','date'],'time_window'=>['nullable','string','max:100'],'authorized_person'=>['nullable','string','max:255'],'authorized_document'=>['nullable','string','max:100']]);return response()->json($this->delivery->schedulePickup($order,$request->user(),Warehouse::findOrFail($d['warehouse_id']),$d['scheduled_at']??null,$d['time_window']??null,$d['authorized_person']??null,$d['authorized_document']??null));}
    public function assignDriver(Request $request,Order $order){$d=$request->validate(['delivery_user_id'=>['required','integer','exists:users,id'],'scheduled_at'=>['nullable','date'],'vehicle_plate'=>['nullable','string','max:40']]);return response()->json($this->delivery->assignOwnDriver($order,$request->user(),User::findOrFail($d['delivery_user_id']),$d['scheduled_at']??null,$d['vehicle_plate']??null));}
    public function assignCourier(Request $request,Order $order){$d=$request->validate(['courier_code'=>['nullable','string','max:60'],'courier_name'=>['required','string','max:255'],'courier_service'=>['nullable','string','max:255'],'tracking_number'=>['nullable','string','max:255'],'tracking_url'=>['nullable','url','max:2000'],'courier_reference'=>['nullable','string','max:255']]);return response()->json($this->delivery->assignExternalCourier($order,$request->user(),$d));}
    public function courierTracking(Request $request,Order $order){$d=$request->validate(['tracking_number'=>['nullable','string','max:255'],'tracking_url'=>['nullable','url','max:2000'],'courier_reference'=>['nullable','string','max:255'],'external_status'=>['nullable','string','max:255']]);return response()->json($this->delivery->updateCourierTracking($order,$request->user(),$d));}
    public function registerDispatch(Request $request,Order $order){return response()->json($this->delivery->dispatch($order,$request->user()));}
    public function startAttempt(Request $request,Order $order){$d=$request->validate(['scheduled_at'=>['nullable','date']]);return response()->json($this->delivery->startAttempt($order,$request->user(),$d['scheduled_at']??null),201);}
    public function outForDelivery(Request $request,Order $order){return response()->json($this->delivery->markOutForDelivery($order,$request->user()));}
    public function failAttempt(Request $request,Order $order,OrderDeliveryAttempt $attempt){$d=$request->validate(['failure_reason'=>['required',Rule::in(['recipient_absent','incorrect_address','recipient_rejected','unable_to_contact','access_restricted','damaged_package','courier_issue','weather_or_traffic','other'])],'failure_description'=>['required','string','max:1000'],'location_reference'=>['nullable','string','max:500']]);return response()->json($this->delivery->failAttempt($order,$attempt,$request->user(),$d['failure_reason'],$d['failure_description'],$d['location_reference']??null));}
    public function reschedule(Request $request,Order $order){$d=$request->validate(['scheduled_at'=>['required','date','after_or_equal:now'],'observation'=>['nullable','string','max:1000']]);return response()->json($this->delivery->reschedule($order,$request->user(),$d['scheduled_at'],$d['observation']??null),201);}
    public function confirm(Request $request,Order $order){$d=$request->validate(['recipient_name'=>['required','string','max:255'],'recipient_document_type'=>['required','string','max:30'],'recipient_document_number'=>['required','string','max:50'],'relationship_to_customer'=>['nullable','string','max:255'],'confirmation_code'=>['nullable','string','max:100'],'delivery_notes'=>['nullable','string','max:1000'],'confirmation_method'=>['required',Rule::in(['manual','confirmation_code','signature','photo'])],'money_received'=>['nullable','boolean'],'collection_method'=>['nullable',Rule::in(['cash','card_terminal','bank_transfer','other'])],'manual_reference'=>['nullable','string','max:255'],'collected_at'=>['nullable','date','before_or_equal:now'],'delivery_photo'=>['nullable','image','mimes:jpg,jpeg,png,webp','max:5120'],'recipient_signature'=>['nullable','image','mimes:jpg,jpeg,png,webp','max:3072'],'delivery_constancy'=>['nullable','mimes:jpg,jpeg,png,webp,pdf','max:5120']]);$files=array_filter(['delivery_photo'=>$request->file('delivery_photo'),'recipient_signature'=>$request->file('recipient_signature'),'delivery_constancy'=>$request->file('delivery_constancy')]);return response()->json($this->delivery->confirmDelivery($order,$request->user(),$d,$files));}
    public function cancel(Request $request,Order $order){$d=$request->validate(['reason'=>['required','string','max:1000']]);return response()->json($this->delivery->cancelDelivery($order,$request->user(),$d['reason']));}
    public function options(){return response()->json(['warehouses'=>Warehouse::with('branch:id,name')->where('is_active',true)->whereHas('branch',fn($q)=>$q->where('is_active',true))->orderBy('name')->get(['id','branch_id','name','address','is_default']),'drivers'=>User::where('role','admin')->where('can_deliver',true)->orderBy('name')->get(['id','name','phone'])]);}
    public function evidence(Order $order,string $type){$delivery=$order->delivery()->firstOrFail();$column=['photo'=>'delivery_photo_path','signature'=>'recipient_signature_path','constancy'=>'delivery_constancy_path'][$type]??abort(404);$path=$delivery->{$column};abort_unless($path&&Storage::disk('local')->exists($path),404);return Storage::disk('local')->download($path);}
}
