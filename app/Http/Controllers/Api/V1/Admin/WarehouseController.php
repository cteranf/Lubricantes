<?php
namespace App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class WarehouseController extends Controller
{
    public function options()
    {
        return Warehouse::with('branch:id,name,is_active')
            ->where('is_active', true)
            ->whereHas('branch', fn ($query) => $query->where('is_active', true))
            ->orderByRaw('CASE WHEN code = ? THEN 0 WHEN is_default = ? THEN 1 ELSE 2 END', [Warehouse::INITIAL_CODE, true])
            ->orderBy('name')
            ->get(['id', 'branch_id', 'code', 'name', 'is_default']);
    }

    public function index(Request $request)
    {
        return Warehouse::with('branch')->withSum('inventories as total_stock','quantity')->withCount('inventories')
            ->when($request->branch_id,fn($q,$v)=>$q->where('branch_id',$v))
            ->when($request->search,fn($q,$v)=>$q->where(fn($s)=>$s->where('name','like',"%$v%")->orWhere('code','like',"%$v%")))->latest()->paginate(20);
    }
    public function store(Request $request)
    {
        $request->merge(['code'=>$this->code((string)$request->code)]); $data=$this->validateData($request);
        $warehouse=DB::transaction(function()use($data){Branch::whereKey($data['branch_id'])->lockForUpdate()->firstOrFail();if($data['is_default'])Warehouse::where('branch_id',$data['branch_id'])->update(['is_default'=>false]);return Warehouse::create($data);});
        return response()->json($warehouse->load('branch'),201);
    }
    public function update(Request $request, Warehouse $warehouse)
    {
        $request->merge(['code'=>$this->code((string)$request->code)]); $data=$this->validateData($request,$warehouse);
        DB::transaction(function()use($data,$warehouse){Branch::whereIn('id',array_unique([$warehouse->branch_id,$data['branch_id']]))->orderBy('id')->lockForUpdate()->get();if($data['is_default'])Warehouse::where('branch_id',$data['branch_id'])->whereKeyNot($warehouse->id)->update(['is_default'=>false]);$warehouse->update($data);});
        return $warehouse->refresh()->load('branch');
    }
    public function status(Request $request, Warehouse $warehouse)
    {
        $data=$request->validate(['is_active'=>'required|boolean']);
        if(!$data['is_active'] && $warehouse->inventories()->where('quantity','>',0)->exists())return response()->json(['message'=>'No se puede desactivar un almacén que aún contiene stock.'],422);
        $warehouse->update($data); return $warehouse;
    }
    private function validateData(Request $request,?Warehouse $warehouse=null):array
    {
        return $request->validate(['branch_id'=>['required',Rule::exists('branches','id')->where(fn($q)=>$q->where('is_active',true))],'code'=>['required','string','max:50',Rule::unique('warehouses')->ignore($warehouse)],'name'=>'required|string|max:255','description'=>'nullable|string','address'=>'nullable|string|max:255','is_default'=>'required|boolean','is_active'=>'required|boolean']);
    }
    private function code(string $code):string{return strtoupper(preg_replace('/[^A-Za-z0-9_-]+/','-',trim($code)));}
}
