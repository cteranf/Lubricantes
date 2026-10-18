<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkTreasuryPaymentRefundPendingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', function ($attribute, $value, $fail): void {
            if (is_string($value) && (trim($value) === '' || mb_strlen(trim($value)) > 1000)) {
                $fail('El motivo debe contener entre 1 y 1000 caracteres.');
            }
        }]];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (array_diff(array_keys($this->all()), ['reason']) !== [] || $this->allFiles() !== [] || $this->query->count() !== 0) {
                $validator->errors()->add('request', 'La solicitud solo acepta el motivo.');
            }
            if ($this->headers->has('Idempotency-Key')) {
                $validator->errors()->add('request', 'La identidad del reembolso pertenece al servidor.');
            }
        });
    }
}
