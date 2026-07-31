<?php
namespace App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBrandRequest;
use App\Http\Requests\Admin\UpdateBrandRequest;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
class BrandController extends Controller
{
    public function index(Request $request) { return Brand::withCount('products')->when($request->filled('search'), fn($q)=>$q->where('name','like','%'.$request->string('search')->trim().'%'))->orderBy('name')->paginate(15)->withQueryString(); }
    public function options() { $q=Brand::query(); if(Schema::hasColumn('brands','is_active')) $q->where('is_active',true); return $q->orderBy('name')->get(['id','name']); }
    public function show(Brand $brand) { return $brand->loadCount('products'); }
    public function store(StoreBrandRequest $request) { $data=$request->validated(); $data['slug']=$this->uniqueSlug($data['name']); return response()->json(Brand::create($data)->loadCount('products'),201); }
    public function update(UpdateBrandRequest $request, Brand $brand) { $brand->update($request->validated()); return $brand->refresh()->loadCount('products'); }
    public function status(Request $request, Brand $brand) { abort_unless(Schema::hasColumn('brands','is_active'),409,'Se requiere la migracion de estados.'); $brand->update($request->validate(['is_active'=>['required','boolean']])); return $brand->refresh()->loadCount('products'); }
    public function destroy(Brand $brand) { abort_unless(Schema::hasColumn('brands','is_active'),409,'Se requiere la migracion de estados.'); $brand->update(['is_active'=>false]); return response()->json(['message'=>'Marca desactivada; se conservaron sus relaciones.']); }
    private function uniqueSlug(string $name): string { $base=Str::slug($name)?:'marca'; $slug=$base; $n=2; while(Brand::where('slug',$slug)->exists()) $slug=$base.'-'.$n++; return $slug; }
}
