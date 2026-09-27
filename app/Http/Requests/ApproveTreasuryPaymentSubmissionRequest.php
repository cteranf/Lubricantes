<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveTreasuryPaymentSubmissionRequest extends FormRequest
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
            // The approval is entirely server-derived. Inspect only the request body
            // (including uploaded files), not route parameters or operational query
            // parameters, so this is a real empty-body allowlist.
            $bodyKeys = array_unique(array_merge(
                array_keys($this->request->all()),
                array_keys($this->allFiles()),
            ));

            if ($bodyKeys !== []) {
                $validator->errors()->add('request', 'La aprobación no acepta datos proporcionados por el cliente.');
            }
        });
    }
}
