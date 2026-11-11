<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminProductsRequest;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Http\Resources\AdminProductListResource;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\MoneyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function index(ListAdminProductsRequest $request, MoneyService $money)
    {
        $query = Product::query()
            ->select([
                'products.id', 'products.category_id', 'products.brand_id', 'products.name',
                'products.sku', 'products.price', 'products.is_active', 'products.image_path',
                'products.stock', 'products.created_at', 'products.description',
            ])
            ->with(['category:id,name', 'brand:id,name'])
            ->withSum('inventories as inventory_stock', 'quantity');

        $filters = $request->filters();
        $escapeLike = static fn (string $value): string => strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);

        if (isset($filters['search'])) {
            $pattern = '%'.$escapeLike($filters['search']).'%';
            $query->where(fn ($builder) => $builder
                ->whereRaw("products.name LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("products.sku LIKE ? ESCAPE '!'", [$pattern]));
        }

        foreach (['sku' => 'products.sku', 'name' => 'products.name'] as $key => $column) {
            if (isset($filters[$key])) {
                $query->whereRaw($column." LIKE ? ESCAPE '!'", ['%'.$escapeLike($filters[$key]).'%']);
            }
        }

        foreach (['category_id' => 'products.category_id', 'brand_id' => 'products.brand_id'] as $key => $column) {
            if (isset($filters[$key])) {
                $query->where($column, '=', (int) $filters[$key]);
            }
        }

        if (isset($filters['is_active'])) {
            $query->where('products.is_active', '=', $filters['is_active'] === '1');
        }

        if (isset($filters['price_min'])) {
            $query->where('products.price', '>=', $money->fromMinor($money->toMinor($filters['price_min'])));
        }
        if (isset($filters['price_max'])) {
            $query->where('products.price', '<=', $money->fromMinor($money->toMinor($filters['price_max'])));
        }

        $sortColumns = [
            'id' => 'products.id', 'name' => 'products.name', 'sku' => 'products.sku',
            'price' => 'products.price', 'is_active' => 'products.is_active', 'created_at' => 'products.created_at',
        ];
        $sortColumn = $sortColumns[$request->sortColumn()];
        $direction = $request->sortDirection();
        $query->orderBy($sortColumn, $direction);
        if ($sortColumn !== 'products.id') {
            $query->orderBy('products.id', $direction);
        }

        return AdminProductListResource::collection($query->paginate(
            $request->pageSize(),
            [
                'products.id', 'products.category_id', 'products.brand_id', 'products.name',
                'products.sku', 'products.price', 'products.is_active', 'products.image_path',
                'products.stock', 'products.created_at', 'products.description',
            ],
            'page',
            $request->pageNumber()
        ));
    }

    public function store(StoreProductRequest $request)
    {
        $validated = $request->validated();

        $validated['slug'] = Str::slug($validated['name']).'-'.Str::random(5);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products', 'public');
            $validated['image_path'] = '/storage/'.$path;
        }

        $initialQuantity = (int) $validated['cantidad_inicial'];
        $warehouse = $initialQuantity > 0 ? Warehouse::findOrFail($validated['warehouse_id']) : null;
        unset($validated['cantidad_inicial'], $validated['warehouse_id']);
        $validated['sku'] = Product::normalizeSku($validated['sku'] ?? null);
        $product = DB::transaction(function () use ($validated, $initialQuantity, $warehouse, $request) {
            $product = Product::create($validated);
            $this->inventory->initializeProduct($product, $initialQuantity, $warehouse, $request->user());

            return $product->refresh();
        });

        return response()->json($product->load(['category:id,name', 'brand:id,name']), 201);
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $validated = $request->validated();

        unset($validated['stock']);
        if (array_key_exists('sku', $validated)) {
            $validated['sku'] = Product::normalizeSku($validated['sku']);
        }

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products', 'public');
            $validated['image_path'] = '/storage/'.$path;
        }

        $product->update($validated);

        return response()->json($product->refresh()->load(['category:id,name', 'brand:id,name']));
    }

    public function status(Request $request, Product $product)
    {
        $product->update($request->validate(['is_active' => ['required', 'boolean']]));

        return response()->json($product->refresh()->load(['category:id,name', 'brand:id,name']));
    }

    public function destroy(Product $product)
    {
        $product->update(['is_active' => false]);

        return response()->json(['message' => 'Producto desactivado; se conservó su historial.']);
    }
}
