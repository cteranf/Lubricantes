<?php

namespace App\Http\Resources;

use App\Services\NewsImageService;
use Illuminate\Http\Resources\Json\JsonResource;

class NewsPublicResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'summary' => $this->summary,
            'image_url' => app(NewsImageService::class)->publicUrl($this->image_path),
            'published_at' => $this->published_at?->toAtomString(),
        ];
    }
}
