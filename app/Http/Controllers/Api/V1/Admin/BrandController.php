<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBrandRequest;
use App\Http\Requests\Admin\UpdateBrandRequest;
use App\Http\Resources\AdminBrandResource;
use App\Models\Brand;
use App\Services\BrandImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BrandController extends Controller
{
    public function index(Request $request)
    {
        $paginator = Brand::withCount('products')->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search')->trim().'%'))->orderBy('name')->paginate(15)->withQueryString();
        $paginator->setCollection($paginator->getCollection()->map(fn ($brand) => (new AdminBrandResource($brand))->resolve($request)));

        return $paginator;
    }

    public function options()
    {
        $q = Brand::query();
        if (Schema::hasColumn('brands', 'is_active')) {
            $q->where('is_active', true);
        }

        return $q->orderBy('name')->get(['id', 'name']);
    }

    public function show(Brand $brand)
    {
        return response()->json((new AdminBrandResource($brand->loadCount('products')))->resolve(request()));
    }

    public function store(StoreBrandRequest $request, BrandImageService $images)
    {
        $data = $request->validated();
        $data['slug'] = $this->uniqueSlug($data['name']);
        $brand = $images->create($data, $request->file('image'));

        return response()->json((new AdminBrandResource($brand->loadCount('products')))->resolve($request), 201);
    }

    public function update(UpdateBrandRequest $request, Brand $brand, BrandImageService $images)
    {
        $brand = $images->update($brand, $request->validated(), $request->file('image'));

        return response()->json((new AdminBrandResource($brand->refresh()->loadCount('products')))->resolve($request));
    }

    public function removeImage(Brand $brand, BrandImageService $images)
    {
        $images->remove($brand);

        return response()->json((new AdminBrandResource($brand->refresh()->loadCount('products')))->resolve(request()));
    }

    public function status(Request $request, Brand $brand)
    {
        abort_unless(Schema::hasColumn('brands', 'is_active'), 409, 'Se requiere la migracion de estados.');
        $brand->update($request->validate(['is_active' => ['required', 'boolean']]));

        return response()->json((new AdminBrandResource($brand->refresh()->loadCount('products')))->resolve($request));
    }

    public function destroy(Brand $brand)
    {
        abort_unless(Schema::hasColumn('brands', 'is_active'), 409, 'Se requiere la migracion de estados.');
        $brand->update(['is_active' => false]);

        return response()->json(['message' => 'Marca desactivada; se conservaron sus relaciones.']);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'marca';
        $slug = $base;
        $n = 2;
        while (Brand::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
