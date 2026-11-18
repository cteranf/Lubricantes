<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAdminProductsRequest;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Http\Resources\AdminProductListResource;
use App\Models\Product;
use App\Models\ProductModel;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\MoneyService;
use App\Services\ProductImageProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function __construct(private InventoryService $inventory, private ProductImageProcessingService $imageProcessing) {}

    public function index(ListAdminProductsRequest $request, MoneyService $money)
    {
        $query = Product::query()
            ->select([
                'products.id', 'products.category_id', 'products.brand_id', 'products.product_model_id', 'products.name',
                'products.sku', 'products.presentation', 'products.price', 'products.is_active', 'products.image_path',
                'products.stock', 'products.created_at', 'products.description', 'products.allow_reviews',
            ])
            ->with(['category:id,name', 'brand:id,name', 'productModel:id,brand_id,name,is_active', 'images:id,product_id,image_path,sort_order,is_primary,variants,original_path,processing_status'])
            ->withSum('inventories as inventory_stock', 'quantity');

        $filters = $request->filters();
        $escapeLike = static fn (string $value): string => strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
        if (isset($filters['search'])) {
            $pattern = '%'.$escapeLike($filters['search']).'%';
            $query->where(fn ($builder) => $builder->whereRaw("products.name LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("products.sku LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('productModel', fn ($models) => $models->whereRaw("product_models.name LIKE ? ESCAPE '!'", [$pattern])));
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
        if (isset($filters['product_model_id'])) {
            $query->where('products.product_model_id', '=', (int) $filters['product_model_id']);
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
            'productModel:id,brand_id,name,is_active',
            'images:id,product_id,image_path,sort_order,is_primary,variants,original_path,processing_status',
            'specifications:id,product_id,name,value,sort_order',
            'barcodes:id,product_id,code,format,is_primary',
        ]);

        return response()->json((new AdminProductListResource($product))->resolve($request));
    }

    public function store(StoreProductRequest $request)
    {
        $validated = $request->validated();
        $uploads = $validated['images'] ?? [];
        $specifications = $validated['specifications'] ?? [];
        $barcodes = $validated['barcodes'] ?? [];
        if ($uploads === [] && isset($validated['image'])) {
            $uploads = [$validated['image']];
        }
        unset($validated['image'], $validated['images'], $validated['specifications'], $validated['barcodes']);
        $validated['slug'] = Str::slug($validated['name']).'-'.Str::random(5);
        $initialQuantity = (int) $validated['cantidad_inicial'];
        $warehouse = $initialQuantity > 0 ? Warehouse::findOrFail($validated['warehouse_id']) : null;
        unset($validated['cantidad_inicial'], $validated['warehouse_id']);
        $validated['sku'] = Product::normalizeSku($validated['sku'] ?? null);

        $product = DB::transaction(function () use ($validated, $initialQuantity, $warehouse, $request, $specifications, $barcodes) {
            $this->lockAndValidateModelAssignment($validated['brand_id'] ?? null, $validated['product_model_id'] ?? null);
            $product = Product::create($validated);
            $this->inventory->initializeProduct($product, $initialQuantity, $warehouse, $request->user());
            $this->replaceSpecifications($product, $specifications);
            app(\App\Services\ProductBarcodeService::class)->sync($product, $barcodes);

            return $product;
        });

        if ($uploads !== []) {
            $this->imageProcessing->attachUploads($product, $uploads);
        }
        $product->refresh()->load(['category:id,name', 'brand:id,name', 'productModel:id,brand_id,name,is_active', 'images:id,product_id,image_path,sort_order,is_primary,variants,original_path,processing_status', 'specifications:id,product_id,name,value,sort_order', 'barcodes:id,product_id,code,format,is_primary']);
        $data = (new AdminProductListResource($product))->resolve($request);

        return response()->json($data, 201);
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $validated = $request->validated();
        $uploads = $validated['images'] ?? [];
        $syncSpecifications = array_key_exists('specifications', $validated);
        $specifications = $validated['specifications'] ?? [];
        $syncBarcodes = array_key_exists('barcodes', $validated);
        $barcodes = $validated['barcodes'] ?? [];
        $legacyUpload = $validated['image'] ?? null;
        unset($validated['image'], $validated['images'], $validated['stock'], $validated['specifications'], $validated['barcodes']);
        if (array_key_exists('sku', $validated)) {
            $validated['sku'] = Product::normalizeSku($validated['sku']);
        }

        DB::transaction(function () use ($validated, $product, $syncSpecifications, $specifications, $syncBarcodes, $barcodes) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $effectiveBrandId = array_key_exists('brand_id', $validated) ? $validated['brand_id'] : $locked->brand_id;
            $effectiveModelId = array_key_exists('product_model_id', $validated) ? $validated['product_model_id'] : $locked->product_model_id;
            if ((int) ($effectiveModelId ?? 0) !== (int) ($locked->product_model_id ?? 0)) {
                $this->lockAndValidateModelAssignment($effectiveBrandId, $effectiveModelId);
            } elseif ($effectiveModelId !== null && (int) ($effectiveBrandId ?? 0) !== (int) ($locked->brand_id ?? 0)) {
                throw ValidationException::withMessages(['product_model_id' => 'Al cambiar de marca, selecciona un modelo compatible o elimina el modelo actual.']);
            }
            if (array_key_exists('price', $validated)) {
                $invalidPromotion = $locked->promotions()->where('is_active', true)
                    ->where('promotion_price', '>=', $validated['price'])->exists();
                if ($invalidPromotion) {
                    throw ValidationException::withMessages(['price' => 'El precio normal no puede quedar por debajo de una oferta habilitada para este producto.']);
                }
            }
            $locked->fill($validated)->save();
            if ($syncSpecifications) {
                $this->replaceSpecifications($locked, $specifications);
            }
            if ($syncBarcodes) {
                app(\App\Services\ProductBarcodeService::class)->sync($locked, $barcodes);
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
        $product->refresh()->load(['category:id,name', 'brand:id,name', 'productModel:id,brand_id,name,is_active', 'images:id,product_id,image_path,sort_order,is_primary,variants,original_path,processing_status', 'specifications:id,product_id,name,value,sort_order', 'barcodes:id,product_id,code,format,is_primary']);
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

    private function lockAndValidateModelAssignment(mixed $brandId, mixed $modelId): void
    {
        if ($modelId === null || $modelId === '') {
            return;
        }

        $model = ProductModel::query()->whereKey($modelId)->lockForUpdate()->first();
        if (! $model || ! $model->is_active || (int) $model->brand_id !== (int) $brandId) {
            throw ValidationException::withMessages(['product_model_id' => 'El modelo no está disponible para la marca seleccionada.']);
        }
    }
}
