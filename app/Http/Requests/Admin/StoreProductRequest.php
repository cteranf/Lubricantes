<?php

namespace App\Http\Requests\Admin;

use App\Exceptions\InvalidProductImage;
use App\Http\Requests\Admin\Concerns\NormalizesProductSpecifications;
use App\Models\Product;
use App\Services\ProductImageProcessor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where(fn ($q) => Schema::hasColumn('categories', 'is_active') ? $q->where('is_active', true) : $q)],
            'brand_id' => ['nullable', Rule::exists('brands', 'id')->where(fn ($q) => Schema::hasColumn('brands', 'is_active') ? $q->where('is_active', true) : $q)],
            'price' => ['required', 'numeric', 'min:0'],
            'cantidad_inicial' => ['required', 'integer', 'min:0'],
            'warehouse_id' => [
                Rule::requiredIf(fn () => (int) $this->input('cantidad_inicial', 0) > 0),
                'nullable',
                Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'sku' => ['nullable', 'string', 'max:64', 'unique:products,sku'],
            'description' => ['nullable', 'string'],
            'allow_reviews' => ['sometimes', 'boolean'],
            'specifications' => ['sometimes', 'array', 'max:30'],
            'specifications.*' => ['required', 'array:name,value'],
            'specifications.*.name' => ['required', 'string', 'max:100'],
            'specifications.*.value' => ['required', 'string', 'max:500'],
            'viscosity' => ['nullable', 'string'],
            'image' => ['nullable', 'file', 'max:10240', function ($attribute, $value, $fail) {
                if ($value === null || ! $value instanceof UploadedFile) {
                    return;
                }
                try {
                    app(ProductImageProcessor::class)->validateUpload($value);
                } catch (InvalidProductImage) {
                    $fail('La imagen debe ser JPEG, PNG o WebP válida y cumplir los límites permitidos.');
                }
            }],
            'images' => ['sometimes', 'array', 'max:'.(int) config('product_images.max_images', 6), function ($attribute, $value, $fail) {
                if ($this->hasFile('image') && is_array($value) && $value !== []) {
                    $fail('Envía imágenes en un solo campo.');
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
