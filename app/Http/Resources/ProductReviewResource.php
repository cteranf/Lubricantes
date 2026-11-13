<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductReviewResource extends JsonResource
{
    public function toArray($request): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $this->user?->name));
        $parts = $name === '' ? [] : preg_split('/\s+/u', $name);
        $displayName = 'Cliente verificado';
        if (is_array($parts) && isset($parts[0]) && $parts[0] !== '') {
            $displayName = $parts[0].(isset($parts[1]) ? ' '.mb_substr($parts[1], 0, 1).'.' : '');
        }

        return [
            'id' => (int) $this->id,
            'display_name' => $displayName,
            'rating' => (int) $this->rating,
            'title' => $this->title,
            'comment' => $this->comment,
            'verified_purchase' => $this->verified_purchase_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
