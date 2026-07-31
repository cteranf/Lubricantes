<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    // Simple endpoint to sync cart or validate stock if needed
    // For now, valid for checking if items are available
    public function store(Request $request)
    {
        $data = $request->validate(['items'=>['required','array'],'items.*.product_id'=>['required','exists:products,id'],'items.*.quantity'=>['required','integer','min:1']]);
        $availability = [];
        foreach ($data['items'] as $item) {
            $product = Product::findOrFail($item['product_id']);
            $available = $this->inventory->sellableStock($product);
            $availability[] = ['product_id'=>$product->id,'available_quantity'=>$available,'requested_quantity'=>$item['quantity'],'available'=>$item['quantity'] <= $available];
        }
        return response()->json(['items'=>$availability,'valid'=>collect($availability)->every('available')]);
    }
}
