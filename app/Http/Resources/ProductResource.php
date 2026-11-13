<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var Product $product */
        $product = $this->resource;
        $images = $product->relationLoaded('images') ? $product->images : collect();
        $publicImages = $images->filter(fn ($image) => $image->hasPublishedImage())->values();
        $primary = $product->primaryImageRecord();

        return [
            'id' => (int) $product->id,
            'category_id' => $product->category_id,
            'brand_id' => $product->brand_id,
            'name' => $product->name,
            'slug' => $product->slug,
            'sku' => $product->sku,
            'description' => $product->description,
            'specifications' => $this->when($product->relationLoaded('specifications'), fn () => ProductSpecificationResource::collection($product->getRelation('specifications'))->resolve($request)),
            'price' => $product->price,
            'sale_price' => $product->sale_price,
            'stock' => (int) $product->stock,
            'viscosity' => $product->viscosity,
            'type' => $product->type,
            'presentation' => $product->presentation,
            'image_url' => $primary?->publicUrl() ?? $product->image_url,
            'image_variants' => $primary?->publicVariants() ?? [],
            'image_srcset' => $primary?->publicSrcset(),
            'image_dimensions' => $primary?->publicDimensions(),
            'images' => ProductImageResource::collection($publicImages)->resolve($request),
            'is_active' => (bool) $product->is_active,
            'is_featured' => (bool) $product->is_featured,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => (int) $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ]),
            'brand' => $this->whenLoaded('brand', fn () => [
                'id' => (int) $product->brand->id,
                'name' => $product->brand->name,
            ]),
        ];
    }
}
