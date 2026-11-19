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
            'presentation' => $this->presentation,
            'description' => $this->description,
            'price' => (string) $this->price,
            'tax_affectation' => $this->tax_affectation,
            'is_active' => (bool) $this->is_active,
            'allow_reviews' => (bool) $this->allow_reviews,
            'product_model_id' => $this->product_model_id === null ? null : (int) $this->product_model_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => (int) $this->category->id,
                'name' => (string) $this->category->name,
            ] : null),
            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? [
                'id' => (int) $this->brand->id,
                'name' => (string) $this->brand->name,
            ] : null),
            'product_model' => $this->whenLoaded('productModel', fn () => $this->productModel ? [
                'id' => (int) $this->productModel->id,
                'name' => (string) $this->productModel->name,
                'is_active' => (bool) $this->productModel->is_active,
            ] : null),
            'physical_stock' => (int) ($this->inventory_stock ?? $this->stock ?? 0),
            'image_url' => $this->image_url,
            'image_variants' => $this->image_variants,
            'image_srcset' => $this->image_srcset,
            'image_dimensions' => $this->image_dimensions,
            'images' => $this->whenLoaded('images', fn () => AdminProductImageResource::collection($this->images)->resolve($request)),
            'specifications' => $this->when($this->resource->relationLoaded('specifications'), fn () => ProductSpecificationResource::collection($this->resource->getRelation('specifications'))->resolve($request)),
            'barcodes' => $this->when($this->resource->relationLoaded('barcodes'), fn () => $this->resource->getRelation('barcodes')->map(fn ($barcode) => [
                'id' => (int) $barcode->id,
                'code' => (string) $barcode->code,
                'format' => (string) $barcode->format,
                'is_primary' => (bool) $barcode->is_primary,
            ])->values()->all()),
        ];
    }
}
