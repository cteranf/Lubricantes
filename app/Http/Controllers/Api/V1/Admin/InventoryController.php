<?php
namespace App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\InventoryService;
use Illuminate\Http\Request;
class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventory){}
    public function index(Request $request)
    {
        return WarehouseInventory::with(['warehouse.branch','product'=>fn($q)=>$q->withSum('inventories as total_stock','quantity')])
            ->when($request->warehouse_id,fn($q,$v)=>$q->where('warehouse_id',$v))
            ->when($request->branch_id,fn($q,$v)=>$q->whereHas('warehouse',fn($w)=>$w->where('branch_id',$v)))
            ->when($request->search,fn($q,$v)=>$q->whereHas('product',fn($p)=>$p->where('name','like',"%$v%")->orWhere('sku','like',"%$v%")))
            ->orderByDesc('updated_at')->paginate(20);
    }
    public function show(Product $product)
    {
        return $product->load(['inventories.warehouse.branch'])->loadSum('inventories as total_stock','quantity');
    }
    public function adjustment(Request $request)
    {
        $data=$request->validate(['product_id'=>'required|exists:products,id','warehouse_id'=>'required|exists:warehouses,id','action'=>'required|in:manual_in,manual_out,correction','quantity'=>'required|integer|min:0','reason'=>'required|string|max:255','notes'=>'nullable|string|max:1000']);
        if ($data['action'] !== 'correction' && $data['quantity'] < 1) return response()->json(['message'=>'La cantidad debe ser un entero positivo.'],422);
        $product=Product::findOrFail($data['product_id']); $warehouse=Warehouse::findOrFail($data['warehouse_id']);
        $movement=match($data['action']){'manual_in'=>$this->inventory->manualIn($product,$warehouse,$data['quantity'],$data['reason'],$request->user(),$data['notes']??null),'manual_out'=>$this->inventory->manualOut($product,$warehouse,$data['quantity'],$data['reason'],$request->user(),$data['notes']??null),'correction'=>$this->inventory->adjust($product,$warehouse,$data['quantity'],$data['reason'],$request->user(),$data['notes']??null)};
        return response()->json($movement->load(['product','warehouse.branch','user']),201);
    }
    public function transfer(Request $request)
    {
        $data=$request->validate(['product_id'=>'required|exists:products,id','source_warehouse_id'=>'required|different:destination_warehouse_id|exists:warehouses,id','destination_warehouse_id'=>'required|exists:warehouses,id','quantity'=>'required|integer|min:1','reason'=>'required|string|max:255','notes'=>'nullable|string|max:1000']);
        [$out,$in]=$this->inventory->transfer(Product::findOrFail($data['product_id']),Warehouse::findOrFail($data['source_warehouse_id']),Warehouse::findOrFail($data['destination_warehouse_id']),$data['quantity'],$data['reason'],$request->user(),$data['notes']??null);
        return response()->json(['transfer_id'=>$out->reference_id,'movements'=>[$out,$in]],201);
    }
}
