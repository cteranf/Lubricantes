<?php
namespace App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use Illuminate\Http\Request;
class InventoryMovementController extends Controller
{
    public function index(Request $request)
    {
        return InventoryMovement::with(['product:id,name,sku','warehouse.branch:id,code,name','user:id,name'])
            ->when($request->product_id,fn($q,$v)=>$q->where('product_id',$v))->when($request->warehouse_id,fn($q,$v)=>$q->where('warehouse_id',$v))
            ->when($request->branch_id,fn($q,$v)=>$q->whereHas('warehouse',fn($w)=>$w->where('branch_id',$v)))->when($request->type,fn($q,$v)=>$q->where('type',$v))
            ->latest('created_at')->paginate(25);
    }
}
