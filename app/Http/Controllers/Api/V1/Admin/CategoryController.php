<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index()
    {
        return Category::withCount('products')->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'image' => 'nullable|image|max:2048',
        ]);

        $path = null;
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('categories', 'public');
        }

        $category = Category::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'image' => $path ? '/storage/' . $path : null,
        ]);

        return response()->json($category, 201);
    }

    public function update(Request $request, Category $category)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'image' => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('categories', 'public');
            $category->image = '/storage/' . $path;
        }

        $category->name = $request->name;
        // Optional: Update slug if name changes? Usually better to keep stable slugs or handle redirects.
        // $category->slug = Str::slug($request->name); 
        $category->save();

        return response()->json($category);
    }

    public function destroy(Category $category)
    {
        // Prevent delete if has products?
        if ($category->products()->count() > 0) {
            return response()->json(['message' => 'Cannot delete category with products'], 409);
        }

        $category->delete();
        return response()->json(['message' => 'Category deleted']);
    }
}
