<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenTreasuryPaymentResolutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $keys = array_unique(array_merge(array_keys($this->request->all()), array_keys($this->allFiles())));

            if ($keys !== []) {
                $validator->errors()->add('request', 'La apertura de resolución no acepta datos proporcionados por el cliente.');
            }

            if ($this->headers->has('Idempotency-Key')) {
                $validator->errors()->add('idempotency_key', 'La identidad de apertura es construida únicamente por el servidor.');
            }
        });
    }
}
