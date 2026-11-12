<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductImageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'is_primary' => (bool) $this->is_primary,
            'sort_order' => (int) $this->sort_order,
            'url' => $this->publicUrl(),
            'variants' => $this->publicVariants(),
            'srcset' => $this->publicSrcset(),
            'width' => $this->publicDimensions()['width'] ?? null,
            'height' => $this->publicDimensions()['height'] ?? null,
        ];
    }
}
