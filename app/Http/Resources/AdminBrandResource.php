<?php

namespace App\Http\Resources;

use App\Services\BrandImageService;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminBrandResource extends JsonResource
{
    public function toArray($request): array
    {
        $imageUrl = app(BrandImageService::class)->publicUrl($this->logo);

        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'slug' => (string) $this->slug,
            'image_url' => $imageUrl,
            'products_count' => (int) ($this->products_count ?? 0),
            'is_active' => (bool) ($this->is_active ?? true),
        ];
    }
}
