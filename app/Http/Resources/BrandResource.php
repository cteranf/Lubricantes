<?php

namespace App\Http\Resources;

use App\Services\BrandImageService;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'slug' => (string) $this->slug,
            'image_url' => app(BrandImageService::class)->publicUrl($this->logo),
            'is_active' => (bool) ($this->is_active ?? true),
        ];
    }
}
