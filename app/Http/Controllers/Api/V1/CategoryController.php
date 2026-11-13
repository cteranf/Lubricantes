<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Support\Facades\Schema;

class CategoryController extends Controller
{
    public function index()
    {
        $activeColumn = Schema::hasColumn('categories', 'is_active');
        $categories = Category::query()
            ->whereNull('parent_id')
            ->when($activeColumn, fn ($query) => $query->where('is_active', true))
            ->withCount('products')
            ->with(['children' => function ($query) use ($activeColumn) {
                $query->when($activeColumn, fn ($children) => $children->where('is_active', true))
                    ->withCount('products')
                    ->orderBy('name');
            }])
            ->orderBy('name')
            ->get();

        return response()->json(CategoryResource::collection($categories)->resolve(request()));
    }

    public function show($slug)
    {
        $activeColumn = Schema::hasColumn('categories', 'is_active');
        $category = Category::query()
            ->when($activeColumn, fn ($query) => $query->where('is_active', true))
            ->with(['children' => fn ($query) => $query->when($activeColumn, fn ($children) => $children->where('is_active', true))->withCount('products')->orderBy('name')])
            ->withCount('products')->where('slug', $slug)->firstOrFail();

        return response()->json((new CategoryResource($category))->resolve(request()));
    }
}
