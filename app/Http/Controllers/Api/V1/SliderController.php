<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Slider;

class SliderController extends Controller
{
    public function index()
    {
        return Slider::query()->where('is_active', true)
            ->with(['category:id,name,slug,is_active'])
            ->orderBy('sort_order', 'asc')
            ->get(['id', 'title', 'description', 'image_path', 'sort_order', 'category_id'])
            ->map(static function (Slider $slider): array {
                $category = $slider->category;

                return [
                    'id' => $slider->id,
                    'title' => $slider->title,
                    'description' => $slider->description,
                    'image_path' => $slider->image_path,
                    'sort_order' => $slider->sort_order,
                    'category' => $category && $category->is_active ? [
                        'id' => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                    ] : null,
                ];
            })->values();
    }
}
