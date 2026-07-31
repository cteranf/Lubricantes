<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\WarehouseInventory;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ProductController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function index(Request $request)
    {
        $query = Product::query()->with(['category', 'brand', 'images'])->where('is_active', true);

        // Filters
        if ($request->has('category')) {
            $query->whereHas('category', function ($q) use ($request) {
                $q->where('slug', $request->category);
            });
        }

        if ($request->has('brand')) {
            $query->whereHas('brand', function ($q) use ($request) {
                $q->where('slug', $request->brand);
            });
        }

        if ($request->has('search')) {
            $query->where(fn ($search) => $search->where('name', 'like', '%' . $request->search . '%')
                ->orWhere('sku', 'like', '%' . $request->search . '%'));
        }

        if ($request->has('viscosity')) {
            $query->where('viscosity', $request->viscosity);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Sorting
        if ($request->has('sort')) {
            switch ($request->sort) {
                case 'price_asc':
                    $query->orderBy('price', 'asc');
                    break;
                case 'price_desc':
                    $query->orderBy('price', 'desc');
                    break;
                case 'newest':
                    $query->orderBy('created_at', 'desc');
                    break;
                default:
                    $query->orderBy('is_featured', 'desc')->orderBy('created_at', 'desc');
            }
        } else {
            $query->orderBy('is_featured', 'desc')->orderBy('created_at', 'desc');
        }

        $products = $query->paginate(12);
        if (Schema::hasTable('warehouse_inventories')) {
            $warehouseId = $this->inventory->defaultWarehouse()->id;
            $sellable = WarehouseInventory::where('warehouse_id', $warehouseId)
                ->whereIn('product_id', $products->getCollection()->pluck('id'))
                ->pluck('quantity', 'product_id');
            $products->getCollection()->each(fn ($product) => $product->setAttribute('stock', (int) ($sellable[$product->id] ?? 0)));
        }
        return $products;
    }

    public function show($slug)
    {
        $product = Product::with(['category', 'brand', 'images'])
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        if (Schema::hasTable('warehouse_inventories')) {
            $product->setAttribute('stock', $this->inventory->sellableStock($product));
        }
        return response()->json($product);
    }
}
