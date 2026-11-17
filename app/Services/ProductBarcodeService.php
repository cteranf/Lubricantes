<?php

namespace App\Services;

use App\Models\Product;

class ProductBarcodeService
{
    public const FORMATS = ['ean13', 'ean8', 'upca', 'upce', 'code128'];

    public function normalize(string $code, string $format): ?string
    {
        if (! in_array($format, self::FORMATS, true)) {
            return null;
        }

        if ($format === 'code128') {
            return strlen($code) >= 1 && strlen($code) <= 64 && preg_match('/\A[\x20-\x7E]+\z/D', $code) === 1
                ? 'code128:'.$code
                : null;
        }

        if (preg_match('/\A[0-9]+\z/D', $code) !== 1) {
            return null;
        }

        return match ($format) {
            'ean13' => strlen($code) === 13 && $this->hasValidCheckDigit($code) ? 'gtin13:'.$code : null,
            'ean8' => strlen($code) === 8 && $this->hasValidCheckDigit($code) ? 'gtin8:'.$code : null,
            'upca' => strlen($code) === 12 && $this->hasValidCheckDigit($code) ? 'gtin13:0'.$code : null,
            'upce' => $this->normalizeUpce($code),
            default => null,
        };
    }

    public function inferredKeys(string $code): array
    {
        $keys = [];
        foreach (self::FORMATS as $format) {
            $normalized = $this->normalize($code, $format);
            if ($normalized !== null) {
                $keys[] = $normalized;
            }
        }

        return array_values(array_unique($keys));
    }

    public function sync(Product $product, array $barcodes): void
    {
        $product->barcodes()->delete();
        foreach ($barcodes as $barcode) {
            $product->barcodes()->create([
                'code' => $barcode['code'],
                'format' => $barcode['format'],
                'normalized_code' => $this->normalize($barcode['code'], $barcode['format']),
                'is_primary' => (bool) ($barcode['is_primary'] ?? false),
            ]);
        }
    }

    private function normalizeUpce(string $code): ?string
    {
        if (strlen($code) !== 8 || ! in_array($code[0], ['0', '1'], true)) {
            return null;
        }

        $numberSystem = $code[0];
        $digits = substr($code, 1, 6);
        $check = $code[7];
        $last = $digits[5];
        $manufacturer = substr($digits, 0, 5);
        $product = match (true) {
            $last <= '2' => substr($digits, 0, 2).$last.'0000'.substr($digits, 2, 3),
            $last === '3' => substr($digits, 0, 3).'00000'.substr($digits, 3, 2),
            $last === '4' => substr($digits, 0, 4).'00000'.$digits[4],
            default => $manufacturer.'0000'.$last,
        };
        $upcA = $numberSystem.$product.$check;

        return $this->hasValidCheckDigit($upcA) ? 'gtin13:0'.$upcA : null;
    }

    private function hasValidCheckDigit(string $code): bool
    {
        $digits = str_split(substr($code, 0, -1));
        $sum = 0;
        foreach (array_reverse($digits) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return (10 - ($sum % 10)) % 10 === (int) substr($code, -1);
    }
}
