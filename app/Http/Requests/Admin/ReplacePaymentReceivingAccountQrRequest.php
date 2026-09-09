<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class ReplacePaymentReceivingAccountQrRequest extends FormRequest
{
    public function rules(): array
    {
        return ['qr' => ['required', 'file', 'max:'.config('treasury.qr_max_kilobytes'), function (string $attribute, mixed $value, \Closure $fail): void {
            if (! $value instanceof UploadedFile || ! $value->isValid() || ! $value->getRealPath()) {
                $fail('El archivo QR no es válido.');

                return;
            }
            $image = @getimagesize($value->getRealPath());
            if (! is_array($image) || ! in_array($image['mime'] ?? null, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                $fail('El QR debe ser una imagen PNG, JPEG o WebP válida.');
            }
        }]];
    }
}
