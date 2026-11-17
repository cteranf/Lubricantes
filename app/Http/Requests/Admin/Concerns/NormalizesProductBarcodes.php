<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Models\Product;
use App\Services\ProductBarcodeService;
use Illuminate\Validation\Validator;

trait NormalizesProductBarcodes
{
    protected function normalizeProductBarcodes(): void
    {
        $barcodes = $this->input('barcodes');
        if (is_string($barcodes)) {
            $decoded = json_decode($barcodes, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $barcodes = $decoded;
            }
        }

        if (is_array($barcodes)) {
            foreach ($barcodes as &$barcode) {
                if (is_array($barcode)) {
                    $barcode['code'] = is_string($barcode['code'] ?? null) ? trim($barcode['code']) : $barcode['code'] ?? null;
                    $barcode['format'] = is_string($barcode['format'] ?? null) ? strtolower(trim($barcode['format'])) : $barcode['format'] ?? null;
                }
            }
            unset($barcode);
            $this->merge(['barcodes' => $barcodes]);
        }
    }

    protected function validateProductBarcodes(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $barcodes = $this->input('barcodes');
            if (! is_array($barcodes)) {
                return;
            }

            $normalizer = app(ProductBarcodeService::class);
            $seen = [];
            $primaryCount = 0;
            $currentProduct = $this->route('product');
            $currentProductId = $currentProduct instanceof Product ? $currentProduct->id : null;

            foreach ($barcodes as $index => $barcode) {
                if (! is_array($barcode)) {
                    continue;
                }
                $format = $barcode['format'] ?? '';
                $code = $barcode['code'] ?? '';
                if (! is_string($format) || ! is_string($code) || ! in_array($format, ProductBarcodeService::FORMATS, true)) {
                    continue;
                }

                $normalized = $normalizer->normalize($code, $format);
                if ($normalized === null) {
                    $validator->errors()->add("barcodes.$index.code", 'El código no coincide con el formato seleccionado o su dígito de control no es válido.');

                    continue;
                }

                if (isset($seen[$normalized])) {
                    $validator->errors()->add("barcodes.$index.code", 'El código está repetido en este producto.');
                }
                $seen[$normalized] = true;

                if (isset($barcode['id']) && (! ctype_digit((string) $barcode['id']) || ! $currentProductId
                    || ! $currentProduct->barcodes()->whereKey((int) $barcode['id'])->exists())) {
                    $validator->errors()->add("barcodes.$index.id", 'La asociación de código no pertenece a este producto.');
                }
                if (\Illuminate\Support\Facades\DB::table('product_barcodes')->where('normalized_code', $normalized)
                    ->where('product_id', '!=', $currentProductId ?? 0)->exists()) {
                    $validator->errors()->add("barcodes.$index.code", 'Este código ya está asociado a otro producto.');
                }

                if (($barcode['is_primary'] ?? false) === true || ($barcode['is_primary'] ?? null) === '1' || ($barcode['is_primary'] ?? null) === 1) {
                    $primaryCount++;
                }
            }

            if ($primaryCount > 1) {
                $validator->errors()->add('barcodes', 'Solo puedes marcar un código de barras como principal.');
            }
        });
    }
}
