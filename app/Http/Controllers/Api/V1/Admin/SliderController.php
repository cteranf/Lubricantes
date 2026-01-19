<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Slider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SliderController extends Controller
{
    public function index()
    {
        return Slider::orderBy('sort_order', 'asc')->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string',
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:2048',
            'sort_order' => 'integer',
        ]);

        $path = null;
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
            // Store in storage/app/public/sliders
            $path = $file->storeAs('sliders', $filename, 'public');
        }

        $slider = Slider::create([
            'title' => $request->title,
            'description' => $request->description,
            'image_path' => $path ? '/storage/' . $path : null,
            'is_active' => $request->boolean('is_active', true),
            'sort_order' => $request->sort_order ?? 0,
        ]);

        return response()->json($slider, 201);
    }

    public function update(Request $request, Slider $slider)
    {
        $request->validate([
            'title' => 'string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'sort_order' => 'integer',
        ]);

        if ($request->hasFile('image')) {
            // Delete old image if needed
            // Storage::disk('public')->delete(str_replace('/storage/', '', $slider->image_path));

            $file = $request->file('image');
            $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('sliders', $filename, 'public');
            $slider->image_path = '/storage/' . $path;
        }

        $slider->fill($request->except(['image']));
        if ($request->has('is_active')) {
            $slider->is_active = $request->boolean('is_active');
        }
        $slider->save();

        return response()->json($slider);
    }

    public function destroy(Slider $slider)
    {
        $slider->delete();
        return response()->json(['message' => 'Slider deleted']);
    }
}
