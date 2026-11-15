<?php

namespace App\Http\Resources;

use App\Services\NewsContentRenderer;

class NewsDetailResource extends NewsPublicResource
{
    public function toArray($request): array
    {
        return parent::toArray($request) + [
            'content_html' => app(NewsContentRenderer::class)->toHtml($this->content),
        ];
    }
}
