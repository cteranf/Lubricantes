<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\CheckoutTaxQuoteService;
use App\Services\InventoryService;
use App\Services\MoneyService;
use App\Services\OrderTaxCalculationService;
use App\Services\ProductPricingService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        private InventoryService $inventory,
        private ProductPricingService $pricing,
        private MoneyService $money,
        private OrderTaxCalculationService $taxes,
        private CheckoutTaxQuoteService $taxQuotes,
    ) {}

    // Simple endpoint to sync cart or validate stock if needed
    // For now, valid for checking if items are available
    public function store(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'shipping_amount' => ['nullable', 'regex:/^\\d+(?:\\.\\d{1,2})?$/'],
        ]);
        $productIds = collect($data['items'])->pluck('product_id')->unique()->values();
        $products = Product::query()->with('activePromotion')->whereIn('id', $productIds)->get()->keyBy('id');
        abort_unless($products->count() === $productIds->count(), 404);
        $availability = [];
        $taxItems = [];
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
            $lineMinor = $this->money->toMinor((string) $quote['price']) * (int) $item['quantity'];
            $taxItems[] = [
                'product_id' => (int) $product->id,
                'tax_affectation' => $product->tax_affectation,
                'quantity' => (int) $item['quantity'],
                'regular_price' => (string) $quote['regular_price'],
                'price' => (string) $quote['price'],
                'subtotal' => $this->money->fromMinor($lineMinor),
            ];
        }
        $shippingMinor = isset($data['shipping_amount']) ? $this->money->toMinor((string) $data['shipping_amount']) : 0;
        $taxSnapshot = $this->taxes->calculate($taxItems, $shippingMinor);
        $taxQuoteInput = [
            'items' => array_map(static fn (array $item): array => [
                'product_id' => $item['product_id'], 'quantity' => $item['quantity'],
                'regular_price' => $item['regular_price'], 'price' => $item['price'],
                'subtotal' => $item['subtotal'], 'tax_affectation' => $item['tax_affectation'],
            ], $taxItems),
            'shipping_amount' => $this->money->fromMinor($shippingMinor),
            'tax_snapshot' => $taxSnapshot,
        ];

        return response()->json([
            'items' => $availability,
            'valid' => collect($availability)->every('available'),
            'tax_preview' => $taxSnapshot,
            'tax_quote_token' => $this->taxQuotes->issue($taxQuoteInput),
        ]);
    }
}
