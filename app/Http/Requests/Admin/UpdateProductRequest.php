<?php

namespace App\Http\Requests\Admin;

use App\Exceptions\InvalidProductImage;
use App\Http\Requests\Admin\Concerns\NormalizesProductSpecifications;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ProductImageProcessor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProductRequest extends FormRequest
{
    use NormalizesProductSpecifications;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('sku')) {
            $this->merge(['sku' => Product::normalizeSku($this->input('sku'))]);
        }
        $this->normalizeProductSpecifications();
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateUniqueProductSpecificationNames($validator);
    }

    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'], 'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'sku' => ['nullable', 'string', 'max:64', Rule::unique('products', 'sku')->ignore($product)], 'image' => ['nullable', 'file', 'max:10240', function ($attribute, $value, $fail) use ($product) {
                if ($value === null || ! $value instanceof UploadedFile) {
                    return;
                }
                if ($product instanceof Product) {
                    $images = ProductImage::query()->where('product_id', $product->id);
                    if (! (clone $images)->where('is_primary', true)->exists() && $images->count() >= (int) config('product_images.max_images', 6)) {
                        $fail('Un producto admite hasta seis fotografías.');
                    }
                }
                try {
                    app(ProductImageProcessor::class)->validateUpload($value);
                } catch (InvalidProductImage) {
                    $fail('La imagen debe ser JPEG, PNG o WebP válida y cumplir los límites permitidos.');
                }
            }],
            'category_id' => ['nullable', function ($a, $v, $fail) use ($product) {
                if ($v === null || $v === '') {
                    return;
                } $q = Category::whereKey($v);
                if (! $q->exists()) {
                    return $fail('La categoria seleccionada no existe.');
                } if (Schema::hasColumn('categories', 'is_active') && ! $q->where('is_active', true)->exists() && (int) $v !== (int) $product->category_id) {
                    $fail('La categoria seleccionada esta inactiva.');
                }
            }],
            'brand_id' => ['nullable', function ($a, $v, $fail) use ($product) {
                if ($v === null || $v === '') {
                    return;
                } $q = Brand::whereKey($v);
                if (! $q->exists()) {
                    return $fail('La marca seleccionada no existe.');
                } if (Schema::hasColumn('brands', 'is_active') && ! $q->where('is_active', true)->exists() && (int) $v !== (int) $product->brand_id) {
                    $fail('La marca seleccionada esta inactiva.');
                }
            }],
            'description' => ['nullable', 'string'], 'viscosity' => ['nullable', 'string'],
            'allow_reviews' => ['sometimes', 'boolean'],
            'specifications' => ['sometimes', 'array', 'max:30'],
            'specifications.*' => ['required', 'array:name,value'],
            'specifications.*.name' => ['required', 'string', 'max:100'],
            'specifications.*.value' => ['required', 'string', 'max:500'],
            'images' => ['sometimes', 'array', 'max:'.(int) config('product_images.max_images', 6), function ($attribute, $value, $fail) use ($product) {
                if (! is_array($value)) {
                    return;
                }
                if ($this->hasFile('image') && $value !== []) {
                    $fail('Envía imágenes en un solo campo.');

                    return;
                }
                $existing = $product instanceof Product ? ProductImage::query()->where('product_id', $product->id)->count() : 0;
                if ($existing + count($value) > (int) config('product_images.max_images', 6)) {
                    $fail('Un producto admite hasta seis fotografías.');
                }
            }],
            'images.*' => ['required', 'file', 'max:10240', function ($attribute, $value, $fail) {
                if (! $value instanceof UploadedFile) {
                    return;
                }
                try {
                    app(ProductImageProcessor::class)->validateUpload($value);
                } catch (InvalidProductImage) {
                    $fail('La imagen debe ser JPEG, PNG o WebP válida y cumplir los límites permitidos.');
                }
            }],
        ];
    }
}
