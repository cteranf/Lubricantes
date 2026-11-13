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
use App\Services\ProductImageProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(private InventoryService $inventory, private ProductImageProcessingService $imageProcessing) {}

    public function index(ListAdminProductsRequest $request, MoneyService $money)
    {
        $query = Product::query()
            ->select([
                'products.id', 'products.category_id', 'products.brand_id', 'products.name',
                'products.sku', 'products.price', 'products.is_active', 'products.image_path',
                'products.stock', 'products.created_at', 'products.description',
            ])
            ->with(['category:id,name', 'brand:id,name', 'images:id,product_id,image_path,sort_order,is_primary,variants,original_path,processing_status'])
            ->withSum('inventories as inventory_stock', 'quantity');

        $filters = $request->filters();
        $escapeLike = static fn (string $value): string => strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
        if (isset($filters['search'])) {
            $pattern = '%'.$escapeLike($filters['search']).'%';
            $query->where(fn ($builder) => $builder->whereRaw("products.name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("products.sku LIKE ? ESCAPE '!'", [$pattern]));
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

        $sortColumns = ['id' => 'products.id', 'name' => 'products.name', 'sku' => 'products.sku', 'price' => 'products.price', 'is_active' => 'products.is_active', 'created_at' => 'products.created_at'];
        $sortColumn = $sortColumns[$request->sortColumn()];
        $direction = $request->sortDirection();
        $query->orderBy($sortColumn, $direction);
        if ($sortColumn !== 'products.id') {
            $query->orderBy('products.id', $direction);
        }

        return AdminProductListResource::collection($query->paginate($request->pageSize(), ['*'], 'page', $request->pageNumber()));
    }

    public function show(Request $request, Product $product)
    {
        $product->load([
            'category:id,name',
            'brand:id,name',
            'images:id,product_id,image_path,sort_order,is_primary,variants,original_path,processing_status',
            'specifications:id,product_id,name,value,sort_order',
        ]);

        return response()->json((new AdminProductListResource($product))->resolve($request));
    }

    public function store(StoreProductRequest $request)
    {
        $validated = $request->validated();
        $uploads = $validated['images'] ?? [];
        $specifications = $validated['specifications'] ?? [];
        if ($uploads === [] && isset($validated['image'])) {
            $uploads = [$validated['image']];
        }
        unset($validated['image'], $validated['images'], $validated['specifications']);
        $validated['slug'] = Str::slug($validated['name']).'-'.Str::random(5);
        $initialQuantity = (int) $validated['cantidad_inicial'];
        $warehouse = $initialQuantity > 0 ? Warehouse::findOrFail($validated['warehouse_id']) : null;
        unset($validated['cantidad_inicial'], $validated['warehouse_id']);
        $validated['sku'] = Product::normalizeSku($validated['sku'] ?? null);

        $product = DB::transaction(function () use ($validated, $initialQuantity, $warehouse, $request, $specifications) {
            $product = Product::create($validated);
            $this->inventory->initializeProduct($product, $initialQuantity, $warehouse, $request->user());
            $this->replaceSpecifications($product, $specifications);

            return $product;
        });

        if ($uploads !== []) {
            $this->imageProcessing->attachUploads($product, $uploads);
        }
        $product->refresh()->load(['category:id,name', 'brand:id,name', 'images:id,product_id,image_path,sort_order,is_primary,variants,original_path,processing_status', 'specifications:id,product_id,name,value,sort_order']);
        $data = (new AdminProductListResource($product))->resolve($request);

        return response()->json($data, 201);
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $validated = $request->validated();
        $uploads = $validated['images'] ?? [];
        $syncSpecifications = array_key_exists('specifications', $validated);
        $specifications = $validated['specifications'] ?? [];
        $legacyUpload = $validated['image'] ?? null;
        unset($validated['image'], $validated['images'], $validated['stock'], $validated['specifications']);
        if (array_key_exists('sku', $validated)) {
            $validated['sku'] = Product::normalizeSku($validated['sku']);
        }

        DB::transaction(function () use ($validated, $product, $syncSpecifications, $specifications) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $locked->fill($validated)->save();
            if ($syncSpecifications) {
                $this->replaceSpecifications($locked, $specifications);
            }
        });

        if ($legacyUpload) {
            $primary = $product->fresh()->load('images')->primaryImageRecord();
            if ($primary) {
                $this->imageProcessing->replace($product, $primary, $legacyUpload);
            } else {
                $this->imageProcessing->attachUploads($product, [$legacyUpload]);
            }
        }
        if ($uploads !== []) {
            $this->imageProcessing->attachUploads($product, $uploads);
        }
        $product->refresh()->load(['category:id,name', 'brand:id,name', 'images:id,product_id,image_path,sort_order,is_primary,variants,original_path,processing_status', 'specifications:id,product_id,name,value,sort_order']);
        $data = (new AdminProductListResource($product))->resolve($request);

        return response()->json($data);
    }

    public function status(Request $request, Product $product)
    {
        $product->update($request->validate(['is_active' => ['required', 'boolean']]));

        return response()->json($product->refresh()->load(['category:id,name', 'brand:id,name', 'images']));
    }

    public function destroy(Product $product)
    {
        $product->update(['is_active' => false]);

        return response()->json(['message' => 'Producto desactivado; se conservó su historial.']);
    }

    private function replaceSpecifications(Product $product, array $specifications): void
    {
        $product->specifications()->delete();
        foreach (array_values($specifications) as $sortOrder => $specification) {
            $product->specifications()->create([
                'name' => $specification['name'],
                'value' => $specification['value'],
                'sort_order' => $sortOrder,
            ]);
        }
    }
}
