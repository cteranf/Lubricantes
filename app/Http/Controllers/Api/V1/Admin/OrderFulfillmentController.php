<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderFulfillmentService;
use Illuminate\Http\Request;

class OrderFulfillmentController extends Controller
{
    public function __construct(private OrderFulfillmentService $fulfillment) {}

    public function show(Order $order) { return response()->json($this->fulfillment->history($order)); }
    public function approveTransfer(Request $request, Order $order) { return response()->json($this->fulfillment->approveTransfer($order,$request->user())); }
    public function startPreparation(Request $request, Order $order) { $data=$this->note($request); return response()->json($this->fulfillment->startPreparation($order,$request->user(),$data['observation']??null)); }
    public function ready(Request $request, Order $order) { $data=$this->note($request); return response()->json($this->fulfillment->markAsReady($order,$request->user(),$data['observation']??null)); }
    public function delivered(Request $request, Order $order) { $data=$this->note($request); return response()->json($this->fulfillment->markAsDelivered($order,$request->user(),$data['observation']??null)); }
    public function cancel(Request $request, Order $order) { $data=$request->validate(['reason'=>['required','string','max:500']]); return response()->json($this->fulfillment->cancelFulfillment($order,$request->user(),$data['reason'])); }
    private function note(Request $request): array { return $request->validate(['observation'=>['nullable','string','max:500']]); }
}
