<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductPromotion;
use App\Services\MoneyService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ProductPromotionController extends Controller
{
    public function index()
    {
        $promotions = ProductPromotion::query()
            ->with(['product:id,name,sku,price,product_model_id', 'product.productModel:id,name'])
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(15);

        return response()->json([
            'data' => $promotions->getCollection()->map(fn (ProductPromotion $promotion) => $this->present($promotion))->values(),
            'meta' => [
                'current_page' => $promotions->currentPage(),
                'last_page' => $promotions->lastPage(),
                'per_page' => $promotions->perPage(),
                'total' => $promotions->total(),
                'business_timezone' => config('app.timezone'),
                'business_now' => now()->format('Y-m-d\\TH:i'),
            ],
        ]);
    }

    public function productOptions(Request $request)
    {
        $data = $request->validate(['search' => ['required', 'string', 'min:1', 'max:100']]);
        $term = '%'.strtr(trim($data['search']), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';

        return Product::query()->with('productModel:id,name')
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->whereRaw("name LIKE ? ESCAPE '!'", [$term])
                ->orWhereRaw("sku LIKE ? ESCAPE '!'", [$term])
                ->orWhereHas('productModel', fn (Builder $models) => $models->whereRaw("name LIKE ? ESCAPE '!'", [$term])))
            ->orderBy('name')->orderBy('id')->limit(25)->get(['id', 'name', 'sku', 'price', 'product_model_id'])
            ->map(fn (Product $product) => [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'sku' => (string) $product->sku,
                'model' => $product->productModel?->name,
                'price' => (string) $product->price,
            ])->values();
    }

    public function store(Request $request, MoneyService $money)
    {
        $data = $this->validatedData($request, true);
        $promotion = $this->persist($data, null, $money);

        return response()->json($this->present($promotion), 201);
    }

    public function update(Request $request, ProductPromotion $productPromotion, MoneyService $money)
    {
        $data = $this->validatedData($request, false);
        $promotion = $this->persist($data, $productPromotion->id, $money, false, (int) $productPromotion->product_id);

        return response()->json($this->present($promotion));
    }

    public function status(Request $request, ProductPromotion $productPromotion, MoneyService $money)
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $promotion = $this->persist(['is_active' => (bool) $data['is_active']], $productPromotion->id, $money, true, (int) $productPromotion->product_id);

        return response()->json($this->present($promotion));
    }

    private function validatedData(Request $request, bool $creating): array
    {
        return $request->validate([
            'product_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:products,id'],
            'promotion_price' => [$creating ? 'required' : 'sometimes', 'numeric', 'decimal:0,2', 'gt:0', 'lte:99999999.99'],
            'starts_at' => [$creating ? 'required' : 'sometimes', 'date'],
            'ends_at' => [$creating ? 'required' : 'sometimes', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function persist(array $data, ?int $promotionId, MoneyService $money, bool $statusOnly = false, ?int $productIdHint = null): ProductPromotion
    {
        return DB::transaction(function () use ($data, $promotionId, $money, $statusOnly, $productIdHint): ProductPromotion {
            $productId = (int) ($data['product_id'] ?? $productIdHint);
            $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
            $promotion = $promotionId
                ? ProductPromotion::query()->whereKey($promotionId)->lockForUpdate()->firstOrFail()
                : new ProductPromotion(['product_id' => $product->id]);

            if ((int) $promotion->product_id !== (int) $product->id) {
                throw ValidationException::withMessages(['product_id' => ['El producto de una oferta no se puede cambiar.']]);
            }

            $isActive = $statusOnly ? (bool) $data['is_active'] : (bool) ($data['is_active'] ?? $promotion->is_active ?? true);
            $price = $statusOnly ? (string) $promotion->promotion_price : (string) ($data['promotion_price'] ?? $promotion->promotion_price);
            if (! $statusOnly || $isActive) {
                try {
                    if ($money->toMinor($price) <= 0 || $money->toMinor($price) >= $money->toMinor((string) $product->price)) {
                        throw ValidationException::withMessages(['promotion_price' => ['El precio promocional debe ser positivo e inferior al precio normal del producto.']]);
                    }
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages(['promotion_price' => ['Ingresa un precio promocional válido.']]);
                }
            }

            $timezone = (string) config('app.timezone', 'UTC');
            $startsAt = $statusOnly ? $promotion->starts_at : Carbon::parse($data['starts_at'] ?? $promotion->starts_at, $timezone);
            $endsAt = $statusOnly ? $promotion->ends_at : Carbon::parse($data['ends_at'] ?? $promotion->ends_at, $timezone);
            if (! $startsAt || ! $endsAt || $startsAt->greaterThanOrEqualTo($endsAt)) {
                throw ValidationException::withMessages(['ends_at' => ['La fecha y hora de fin deben ser posteriores al inicio.']]);
            }

            if ($isActive) {
                $overlap = ProductPromotion::query()->where('product_id', $product->id)->where('is_active', true)
                    ->where('starts_at', '<', $endsAt->format('Y-m-d H:i:s'))
                    ->where('ends_at', '>', $startsAt->format('Y-m-d H:i:s'))
                    ->when($promotionId, fn (Builder $query) => $query->whereKeyNot($promotionId))
                    ->exists();
                if ($overlap) {
                    throw ValidationException::withMessages(['starts_at' => ['El periodo se superpone con otra oferta habilitada para este producto.']]);
                }
            }

            $promotion->fill([
                'product_id' => $product->id,
                'promotion_price' => $price,
                'starts_at' => $startsAt->format('Y-m-d H:i:s'),
                'ends_at' => $endsAt->format('Y-m-d H:i:s'),
                'is_active' => $isActive,
            ])->save();

            return $promotion->load(['product:id,name,sku,price,product_model_id', 'product.productModel:id,name']);
        }, 3);
    }

    private function present(ProductPromotion $promotion): array
    {
        $timezone = (string) config('app.timezone', 'UTC');

        return [
            'id' => (int) $promotion->id,
            'product' => [
                'id' => (int) $promotion->product_id,
                'name' => (string) ($promotion->product?->name ?? 'Producto no disponible'),
                'sku' => $promotion->product?->sku,
                'model' => $promotion->product?->productModel?->name,
                'normal_price' => $promotion->product?->price,
            ],
            'promotion_price' => (string) $promotion->promotion_price,
            'starts_at' => $promotion->starts_at?->timezone($timezone)->format('Y-m-d\TH:i'),
            'ends_at' => $promotion->ends_at?->timezone($timezone)->format('Y-m-d\TH:i'),
            'is_active' => (bool) $promotion->is_active,
            'business_timezone' => $timezone,
        ];
    }
}
