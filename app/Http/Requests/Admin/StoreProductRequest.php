<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Schema;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('sku')) {
            $this->merge(['sku' => Product::normalizeSku($this->input('sku'))]);
        }
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
            'viscosity' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'max:2048'],
        ];
    }
}
