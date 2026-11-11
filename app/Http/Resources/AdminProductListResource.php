<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductListResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'sku' => $this->sku,
            'description' => $this->description,
            'price' => (string) $this->price,
            'is_active' => (bool) $this->is_active,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => (int) $this->category->id,
                'name' => (string) $this->category->name,
            ] : null),
            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? [
                'id' => (int) $this->brand->id,
                'name' => (string) $this->brand->name,
            ] : null),
            'physical_stock' => (int) ($this->inventory_stock ?? $this->stock ?? 0),
            'image_url' => $this->image_url,
        ];
    }
}
