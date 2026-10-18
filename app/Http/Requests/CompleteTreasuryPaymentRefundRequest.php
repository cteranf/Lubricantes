<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteTreasuryPaymentRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['external_reference' => ['bail', 'required', 'string', function ($attribute, $value, $fail): void {
            if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{C}\p{Zl}\p{Zp}]/u', $value)) {
                $fail('La referencia debe ser UTF-8 válido, de una línea y sin controles.');

                return;
            }
            $normalized = preg_replace('/^\p{Zs}+|\p{Zs}+$/u', '', $value);
            if ($normalized === '' || strlen($normalized) > 255) {
                $fail('La referencia debe contener entre 1 y 255 bytes.');
            }
        }]];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (array_diff(array_keys($this->all()), ['external_reference']) !== [] || $this->allFiles() !== [] || $this->query->count() !== 0) {
                $validator->errors()->add('request', 'La solicitud solo acepta la referencia.');
            }
            if ($this->headers->has('Idempotency-Key')) {
                $validator->errors()->add('request', 'La identidad del reembolso pertenece al servidor.');
            }
        });
    }
}
