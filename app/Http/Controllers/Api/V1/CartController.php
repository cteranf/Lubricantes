<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\InventoryService;
use App\Services\ProductPricingService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private InventoryService $inventory, private ProductPricingService $pricing) {}

    // Simple endpoint to sync cart or validate stock if needed
    // For now, valid for checking if items are available
    public function store(Request $request)
    {
        $data = $request->validate(['items'=>['required','array'],'items.*.product_id'=>['required','exists:products,id'],'items.*.quantity'=>['required','integer','min:1']]);
        $productIds = collect($data['items'])->pluck('product_id')->unique()->values();
        $products = Product::query()->with('activePromotion')->whereIn('id', $productIds)->get()->keyBy('id');
        abort_unless($products->count() === $productIds->count(), 404);
        $availability = [];
        foreach ($data['items'] as $item) {
            $product = $products->get((int) $item['product_id']);
            $available = $this->inventory->sellableStock($product);
            $quote = $this->pricing->quote($product);
            $availability[] = [
                'product_id' => (int) $product->id,
                'available_quantity' => $available,
                'requested_quantity' => $item['quantity'],
                'available' => $item['quantity'] <= $available,
                'regular_price' => $quote['regular_price'],
                'applied_price' => $quote['price'],
            ];
        }
        return response()->json(['items'=>$availability,'valid'=>collect($availability)->every('available')]);
    }
}
