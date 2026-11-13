<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Http\Resources\AdminCategoryResource;
use App\Models\Category;
use App\Services\CategoryImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $paginator = Category::withCount('products')->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search')->trim().'%'))->orderBy('name')->paginate(15)->withQueryString();
        $paginator->setCollection($paginator->getCollection()->map(fn ($category) => (new AdminCategoryResource($category))->resolve($request)));

        return $paginator;
    }

    public function options()
    {
        $q = Category::query();
        if (Schema::hasColumn('categories', 'is_active')) {
            $q->where('is_active', true);
        }

return $q->orderBy('name')->get(['id', 'name']);
    }

    public function show(Category $category)
    {
        return response()->json((new AdminCategoryResource($category->loadCount('products')))->resolve(request()));
    }

    public function store(StoreCategoryRequest $request, CategoryImageService $images)
    {
        $data = $request->validated();
        unset($data['image']);
        $data['slug'] = $this->uniqueSlug($data['name']);
        $category = DB::transaction(function () use ($data, $request, $images) {
            $category = Category::create($data);
            if ($request->hasFile('image')) {
                $path = $images->store($request->file('image'));
                $category->forceFill(['image' => $path])->save();
            }

return $category;
        });

        return response()->json((new AdminCategoryResource($category->loadCount('products')))->resolve($request), 201);
    }

    public function update(UpdateCategoryRequest $request, Category $category, CategoryImageService $images)
    {
        $data = $request->validated();
        unset($data['image']);
        $category->update($data);
        if ($request->hasFile('image')) {
            $images->replace($category, $request->file('image'));
        }

return response()->json((new AdminCategoryResource($category->refresh()->loadCount('products')))->resolve($request));
    }

    public function removeImage(Category $category, CategoryImageService $images)
    {
        $images->remove($category);

        return response()->json((new AdminCategoryResource($category->refresh()->loadCount('products')))->resolve(request()));
    }

    public function status(Request $request, Category $category)
    {
        abort_unless(Schema::hasColumn('categories', 'is_active'), 409, 'Se requiere la migracion de estados.');
        $category->update($request->validate(['is_active' => ['required', 'boolean']]));

        return $category->refresh()->loadCount('products');
    }

    public function destroy(Category $category)
    {
        abort_unless(Schema::hasColumn('categories', 'is_active'), 409, 'Se requiere la migracion de estados.');
        $category->update(['is_active' => false]);

        return response()->json(['message' => 'Categoria desactivada; se conservaron sus relaciones.']);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'categoria';
        $slug = $base;
        $n = 2;
        while (Category::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

return $slug;
    }
}
