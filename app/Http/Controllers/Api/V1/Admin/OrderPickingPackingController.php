<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderHandlingIncident;
use App\Models\OrderItem;
use App\Services\OrderPickingPackingService;
use Illuminate\Http\Request;

class OrderPickingPackingController extends Controller
{
    public function __construct(private OrderPickingPackingService $handling) {}

    public function show(Order $order) { return response()->json($this->handling->getOperationalSummary($order)); }
    public function startPicking(Request $request, Order $order) { $data=$this->note($request); return response()->json($this->handling->startPicking($order,$request->user(),$data['observation']??null)); }
    public function updatePicked(Request $request, Order $order, OrderItem $orderItem) { $data=$this->quantity($request,'picked_quantity'); return response()->json($this->handling->updatePickedQuantity($order,$orderItem,$data['picked_quantity'],$request->user(),$data['observation']??null)); }
    public function completePicking(Request $request, Order $order) { $data=$this->note($request); return response()->json($this->handling->completePicking($order,$request->user(),$data['observation']??null)); }
    public function startPacking(Request $request, Order $order) { $data=$this->note($request); return response()->json($this->handling->startPacking($order,$request->user(),$data['observation']??null)); }
    public function updatePacked(Request $request, Order $order, OrderItem $orderItem) { $data=$this->quantity($request,'packed_quantity'); return response()->json($this->handling->updatePackedQuantity($order,$orderItem,$data['packed_quantity'],$request->user(),$data['observation']??null)); }
    public function completePacking(Request $request, Order $order) { $data=$this->note($request); return response()->json($this->handling->completePacking($order,$request->user(),$data['observation']??null)); }

    public function reportIncident(Request $request, Order $order)
    {
        $data=$request->validate([
            'order_item_id'=>['nullable','integer','exists:order_items,id'],
            'type'=>['required','in:missing,damaged,quantity_mismatch,wrong_product,other'],
            'affected_quantity'=>['nullable','integer','min:0'],
            'description'=>['required','string','max:1000'],
            'idempotency_key'=>['nullable','string','max:255'],
        ]);
        $item=isset($data['order_item_id'])?OrderItem::findOrFail($data['order_item_id']):null;
        return response()->json($this->handling->reportIncident($order,$request->user(),$data['type'],$data['description'],$item,$data['affected_quantity']??null,$data['idempotency_key']??null),201);
    }

    public function resolveIncident(Request $request, Order $order, OrderHandlingIncident $incident)
    {
        $data=$request->validate(['observation'=>['required','string','max:1000']]);
        return response()->json($this->handling->resolveIncident($order,$incident,$request->user(),$data['observation']));
    }

    private function note(Request $request): array { return $request->validate(['observation'=>['nullable','string','max:500']]); }
    private function quantity(Request $request,string $field): array { return $request->validate([$field=>['required','integer','min:0'],'observation'=>['nullable','string','max:500']]); }
}
