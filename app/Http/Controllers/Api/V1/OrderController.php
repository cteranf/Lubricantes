<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Exceptions\InventoryException;
use App\Models\Order;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function index(Request $request)
    {
        return $request->user()->orders()->with(['items.product','items.warehouse','items.reservation'])->latest()->get();
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shipping_info' => 'required|array',
            'shipping_info.address' => 'required|string',
            'shipping_info.city' => 'required|string',
            'payment_method' => 'required|string',
            'delivery_type' => 'required|in:delivery,pickup',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $order = DB::transaction(function () use ($request) {
                $total = 0;
                $itemsToCreate = [];
                $warehouse = $this->inventory->defaultWarehouse();
                $reservedUntil = now()->addMinutes(max(1, (int) config('inventory.reservation_minutes', 30)));
                foreach ($request->items as $item) {
                    $product = Product::findOrFail($item['product_id']);
                    $price = $product->sale_price ?? $product->price;
                    $subtotal = $price * $item['quantity'];
                    $total += $subtotal;
                    $itemsToCreate[] = ['product'=>$product,'quantity'=>$item['quantity'],'price'=>$price,'subtotal'=>$subtotal];
                }
                $order = Order::create(['user_id'=>$request->user()->id,'status'=>'pending','total'=>$total,'shipping_info'=>$request->shipping_info,'payment_method'=>$request->payment_method,'delivery_type'=>$request->delivery_type,'tracking_status'=>'pending','fulfillment_status'=>Order::FULFILLMENT_RESERVED,'delivery_flow_version'=>1,'reserved_until'=>$reservedUntil]);
                foreach ($itemsToCreate as $data) {
                    $item = $order->items()->create(['product_id'=>$data['product']->id,'warehouse_id'=>$warehouse->id,'quantity'=>$data['quantity'],'price'=>$data['price'],'subtotal'=>$data['subtotal']]);
                    $item->setRelation('product', $data['product']);
                    $item->setRelation('warehouse', $warehouse);
                    $this->inventory->reserveForOrder($item, $reservedUntil);
                }
                return $order;
            });

            return response()->json($order->load(['items.warehouse', 'items.reservation']), 201);

        } catch (ValidationException $e) {
            throw $e;
        } catch (InventoryException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        } catch (\Throwable $e) {
            Log::error('Order creation failed', [
                'user_id' => $request->user()->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo crear el pedido. Inténtalo nuevamente.',
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $order = $request->user()->orders()->with(['items.product','items.warehouse','items.reservation'])->findOrFail($id);

        return response()->json($order);
    }
}
