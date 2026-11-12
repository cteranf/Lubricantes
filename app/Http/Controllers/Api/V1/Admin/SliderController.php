<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Slider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SliderController extends Controller
{
    public function index()
    {
        return Slider::with(['category:id,name,slug,is_active'])
            ->orderBy('sort_order', 'asc')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string',
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:2048',
            'sort_order' => 'integer',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
            'category_id' => ['bail', 'nullable', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('is_active', true))],
        ]);

        $path = null;
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
            // Store in storage/app/public/sliders
            $path = $file->storeAs('sliders', $filename, 'public');
        }

        $slider = Slider::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'image_path' => $path ? '/storage/'.$path : null,
            'category_id' => $data['category_id'] ?? null,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return response()->json($slider->load('category:id,name,slug,is_active'), 201);
    }

    public function update(Request $request, Slider $slider)
    {
        $data = $request->validate([
            'title' => 'string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'sort_order' => 'integer',
            'category_id' => [
                'bail', 'nullable', 'integer',
                Rule::exists('categories', 'id')->where(function ($query) use ($slider) {
                    $query->where('is_active', true);
                    if ($slider->category_id !== null) {
                        $query->orWhere('id', $slider->category_id);
                    }
                }),
            ],
            'is_active' => 'sometimes|boolean',
            'description' => 'nullable|string',
        ]);

        if ($request->hasFile('image')) {
            // Delete old image if needed
            // Storage::disk('public')->delete(str_replace('/storage/', '', $slider->image_path));

            $file = $request->file('image');
            $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
            $path = $file->storeAs('sliders', $filename, 'public');
            $slider->image_path = '/storage/'.$path;
        }

        unset($data['image']);
        $slider->fill($data);
        if (array_key_exists('is_active', $data)) {
            $slider->is_active = (bool) $data['is_active'];
        }
        $slider->save();

        return response()->json($slider->refresh()->load('category:id,name,slug,is_active'));
    }

    public function destroy(Slider $slider)
    {
        $slider->delete();

        return response()->json(['message' => 'Slider deleted']);
    }
}
