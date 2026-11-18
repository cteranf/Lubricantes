<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\ProductModel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class ProductModelController extends Controller
{
    public function index(Brand $brand)
    {
        $paginator = $brand->productModels()->withCount('products')->paginate(15)->withQueryString();
        $paginator->setCollection($paginator->getCollection()->map(fn (ProductModel $model) => [
            'id' => (int) $model->id,
            'name' => (string) $model->name,
            'is_active' => (bool) $model->is_active,
            'products_count' => (int) $model->products_count,
        ]));

        return $paginator;
    }

    public function brandOptions(Brand $brand)
    {
        return $brand->productModels()->where('is_active', true)->get(['id', 'brand_id', 'name']);
    }

    public function options(Request $request)
    {
        $query = ProductModel::query()->join('brands', 'brands.id', '=', 'product_models.brand_id')
            ->orderBy('product_models.name')->orderBy('product_models.id');
        if ($request->filled('brand_id')) {
            $query->where('brand_id', (int) $request->input('brand_id'));
        }

        return $query->get(['product_models.id', 'product_models.brand_id', 'product_models.name', 'brands.name as brand_name']);
    }

    public function store(Request $request, Brand $brand)
    {
        $name = trim((string) $request->input('name', ''));
        $normalizedName = ProductModel::normalizeName($name);
        $validated = validator(['name' => $name], [
            'name' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) use ($brand, $normalizedName): void {
                if (ProductModel::query()->where('brand_id', $brand->id)->where('normalized_name', $normalizedName)->exists()) {
                    $fail('Ya existe un modelo equivalente para esta marca.');
                }
            }],
        ])->validate();

        try {
            $model = $brand->productModels()->create([
                'name' => $validated['name'],
                'normalized_name' => $normalizedName,
                'is_active' => true,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return response()->json(['message' => 'Ya existe un modelo equivalente para esta marca.', 'errors' => ['name' => ['Ya existe un modelo equivalente para esta marca.']]], 422);
        }

        return response()->json($this->present($model->loadCount('products')), 201);
    }

    public function update(Request $request, ProductModel $productModel)
    {
        $name = trim((string) $request->input('name', ''));
        $normalizedName = ProductModel::normalizeName($name);
        $validated = validator(['name' => $name], [
            'name' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) use ($productModel, $normalizedName): void {
                if (ProductModel::query()->where('brand_id', $productModel->brand_id)
                    ->where('normalized_name', $normalizedName)->whereKeyNot($productModel->id)->exists()) {
                    $fail('Ya existe un modelo equivalente para esta marca.');
                }
            }],
        ])->validate();

        try {
            $productModel->update(['name' => $validated['name'], 'normalized_name' => $normalizedName]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return response()->json(['message' => 'Ya existe un modelo equivalente para esta marca.', 'errors' => ['name' => ['Ya existe un modelo equivalente para esta marca.']]], 422);
        }

        return response()->json($this->present($productModel->refresh()->loadCount('products')));
    }

    public function status(Request $request, ProductModel $productModel)
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $productModel = \Illuminate\Support\Facades\DB::transaction(function () use ($productModel, $data): ProductModel {
            $locked = ProductModel::query()->whereKey($productModel->id)->lockForUpdate()->firstOrFail();
            $locked->update($data);

            return $locked;
        });

        return response()->json($this->present($productModel->refresh()->loadCount('products')));
    }

    private function present(ProductModel $model): array
    {
        return [
            'id' => (int) $model->id,
            'brand_id' => (int) $model->brand_id,
            'name' => (string) $model->name,
            'is_active' => (bool) $model->is_active,
            'products_count' => (int) ($model->products_count ?? 0),
        ];
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $errorCode = $exception->errorInfo[1] ?? null;
        $message = strtolower($exception->getMessage());

        return $errorCode === 1062
            || str_contains($message, 'unique constraint failed')
            || str_contains($message, 'duplicate entry');
    }
}
