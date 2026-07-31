<?php
namespace App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class BranchController extends Controller
{
    public function index(Request $request)
    {
        return Branch::withCount('warehouses')->when($request->search,fn($q,$v)=>$q->where(fn($s)=>$s->where('name','like',"%$v%")->orWhere('code','like',"%$v%")))->latest()->paginate(20);
    }
    public function store(Request $request)
    {
        $request->merge(['code'=>$this->code((string)$request->code)]); $data=$this->validateData($request);
        return response()->json(Branch::create($data),201);
    }
    public function update(Request $request, Branch $branch)
    {
        $request->merge(['code'=>$this->code((string)$request->code)]); $data=$this->validateData($request,$branch); $branch->update($data);
        return $branch->loadCount('warehouses');
    }
    public function status(Request $request, Branch $branch)
    {
        $data=$request->validate(['is_active'=>'required|boolean']);
        if (!$data['is_active'] && $branch->warehouses()->where('is_active',true)->exists()) return response()->json(['message'=>'Desactive primero los almacenes activos de esta sede.'],422);
        $branch->update($data); return $branch;
    }
    private function validateData(Request $request, ?Branch $branch=null): array
    {
        return $request->validate(['code'=>['required','string','max:50',Rule::unique('branches')->ignore($branch)],'name'=>'required|string|max:255','description'=>'nullable|string','address'=>'required|string|max:255','district'=>'nullable|string|max:100','province'=>'nullable|string|max:100','department'=>'nullable|string|max:100','phone'=>'nullable|string|max:30','email'=>'nullable|email|max:255','is_active'=>'required|boolean']);
    }
    private function code(string $code): string { return strtoupper(preg_replace('/[^A-Za-z0-9_-]+/','-',trim($code))); }
}
