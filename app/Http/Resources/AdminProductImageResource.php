<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductImageResource extends JsonResource
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
            'processing_status' => in_array($this->processing_status, ['pending', 'processing', 'ready', 'failed'], true) ? $this->processing_status : 'failed',
            'can_regenerate_image' => $this->canRegenerate(),
        ];
    }
}
