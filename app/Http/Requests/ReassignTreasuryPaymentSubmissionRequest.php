<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReassignTreasuryPaymentSubmissionRequest extends FormRequest
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
            $bodyKeys = array_unique(array_merge(
                array_keys($this->request->all()),
                array_keys($this->allFiles()),
            ));

            if ($bodyKeys !== []) {
                $validator->errors()->add('request', 'La reasignación no acepta datos proporcionados por el cliente.');
            }

            if ($this->headers->has('Idempotency-Key')) {
                $validator->errors()->add('idempotency_key', 'La identidad de reasignación es construida únicamente por el servidor.');
            }
        });
    }
}
