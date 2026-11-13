<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductSpecificationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'name' => (string) $this->name,
            'value' => (string) $this->value,
        ];
    }
}
