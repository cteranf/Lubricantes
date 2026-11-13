<?php

namespace App\Http\Resources;

use App\Services\CategoryImageService;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCategoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'image_url' => app(CategoryImageService::class)->publicUrl($this->image),
            'products_count' => (int) ($this->products_count ?? 0),
            'is_active' => (bool) ($this->is_active ?? true),
        ];
    }
}
