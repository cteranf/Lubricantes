<?php

namespace App\Services;

use App\Models\Product;

class ProductPricingService
{
    public function quote(Product $product): array
    {
        $regularPrice = (string) $product->price;
        $promotion = $product->relationLoaded('activePromotion')
            ? $product->getRelation('activePromotion')
            : null;

        if ($promotion && $this->money($promotion->promotion_price) > 0 && $this->money($promotion->promotion_price) < $this->money($regularPrice)) {
            return [
                'regular_price' => $regularPrice,
                'price' => (string) $promotion->promotion_price,
                'promotion_id' => (int) $promotion->id,
            ];
        }

        $legacySale = $product->sale_price;
        if ($legacySale !== null && $this->money($legacySale) > 0 && $this->money($legacySale) < $this->money($regularPrice)) {
            return ['regular_price' => $regularPrice, 'price' => (string) $legacySale, 'promotion_id' => null];
        }

        return ['regular_price' => $regularPrice, 'price' => $regularPrice, 'promotion_id' => null];
    }

    private function money(string|int|float $value): int
    {
        return app(MoneyService::class)->toMinor((string) $value);
    }
}
