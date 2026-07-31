<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function index(Request $request)
    {
        $query = Product::with(['category:id,name', 'brand:id,name'])
            ->when($request->search, fn ($q, $v) => $q->where(fn ($s) => $s->where('name', 'like', "%$v%")->orWhere('sku', 'like', "%$v%")))
            ->orderBy('created_at', 'desc');
        if (Schema::hasTable('warehouse_inventories')) $query->withSum('inventories as inventory_stock', 'quantity');
        $products = $query->paginate(20);
        $products->getCollection()->each(fn ($product) => $product->setAttribute('stock', isset($product->inventory_stock) ? (int) $product->inventory_stock : (int) $product->stock));
        return $products;
    }

    public function store(StoreProductRequest $request)
    {
        $validated = $request->validated();

        $validated['slug'] = Str::slug($validated['name']) . '-' . Str::random(5);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products', 'public');
            $validated['image_path'] = '/storage/' . $path;
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
        if (array_key_exists('sku', $validated)) $validated['sku'] = Product::normalizeSku($validated['sku']);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products', 'public');
            $validated['image_path'] = '/storage/' . $path;
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
