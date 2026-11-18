<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Models\Product;
use App\Models\ProductModel;
use Illuminate\Validation\Validator;

trait ValidatesProductModelAssignment
{
    protected function validateProductModelAssignment(Validator $validator, ?Product $product = null): void
    {
        $validator->after(function (Validator $validator) use ($product): void {
            $data = $this->all();
            $brandWasProvided = array_key_exists('brand_id', $data);
            $modelWasProvided = array_key_exists('product_model_id', $data);
            $effectiveBrandId = $brandWasProvided ? $data['brand_id'] : $product?->brand_id;
            $effectiveModelId = $modelWasProvided ? $data['product_model_id'] : $product?->product_model_id;

            if ($product && $brandWasProvided && (string) ($effectiveBrandId ?? '') !== (string) ($product->brand_id ?? '')
                && $product->product_model_id !== null && ! $modelWasProvided) {
                $validator->errors()->add('product_model_id', 'Al cambiar de marca, envía un modelo compatible o product_model_id: null.');

                return;
            }

            if ($effectiveModelId === null || $effectiveModelId === '') {
                return;
            }

            if ($effectiveBrandId === null || $effectiveBrandId === '') {
                $validator->errors()->add('product_model_id', 'Selecciona una marca compatible con el modelo.');

                return;
            }

            $model = ProductModel::query()->whereKey($effectiveModelId)->first();
            $isRetainingSameModel = $product && (int) $product->product_model_id === (int) $effectiveModelId
                && (int) $product->brand_id === (int) $effectiveBrandId;

            if (! $model || (int) $model->brand_id !== (int) $effectiveBrandId || (! $model->is_active && ! $isRetainingSameModel)) {
                $validator->errors()->add('product_model_id', 'El modelo no está disponible para la marca seleccionada.');
            }
        });
    }
}
