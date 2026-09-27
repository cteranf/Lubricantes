<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TreasuryPaymentSubmissionDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:1000']];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (is_string($this->input('reason')) && trim($this->input('reason')) === '') {
                $validator->errors()->add('reason', 'El motivo no puede estar vacío.');
            }
            $unknown = array_diff(array_keys($this->all()), ['reason']);
            if ($unknown !== []) {
                $validator->errors()->add('reason', 'La solicitud contiene campos no permitidos.');
            }
        });
    }
}
