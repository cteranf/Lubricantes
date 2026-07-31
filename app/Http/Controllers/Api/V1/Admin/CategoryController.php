<?php
namespace App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
class CategoryController extends Controller
{
    public function index(Request $request) { return Category::withCount('products')->when($request->filled('search'), fn($q)=>$q->where('name','like','%'.$request->string('search')->trim().'%'))->orderBy('name')->paginate(15)->withQueryString(); }
    public function options() { $q=Category::query(); if(Schema::hasColumn('categories','is_active')) $q->where('is_active',true); return $q->orderBy('name')->get(['id','name']); }
    public function show(Category $category) { return $category->loadCount('products'); }
    public function store(StoreCategoryRequest $request) { $data=$request->validated(); $data['slug']=$this->uniqueSlug($data['name']); if($request->hasFile('image')) $data['image']='/storage/'.$request->file('image')->store('categories','public'); return response()->json(Category::create($data)->loadCount('products'),201); }
    public function update(UpdateCategoryRequest $request, Category $category) { $data=$request->validated(); if($request->hasFile('image')) $data['image']='/storage/'.$request->file('image')->store('categories','public'); $category->update($data); return $category->refresh()->loadCount('products'); }
    public function status(Request $request, Category $category) { abort_unless(Schema::hasColumn('categories','is_active'),409,'Se requiere la migracion de estados.'); $category->update($request->validate(['is_active'=>['required','boolean']])); return $category->refresh()->loadCount('products'); }
    public function destroy(Category $category) { abort_unless(Schema::hasColumn('categories','is_active'),409,'Se requiere la migracion de estados.'); $category->update(['is_active'=>false]); return response()->json(['message'=>'Categoria desactivada; se conservaron sus relaciones.']); }
    private function uniqueSlug(string $name): string { $base=Str::slug($name)?:'categoria'; $slug=$base; $n=2; while(Category::where('slug',$slug)->exists()) $slug=$base.'-'.$n++; return $slug; }
}
