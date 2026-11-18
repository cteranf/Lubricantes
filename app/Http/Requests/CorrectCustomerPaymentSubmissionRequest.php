<?php

namespace App\Http\Requests;

use App\Models\PaymentSubmission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrectCustomerPaymentSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', 'string', Rule::in(PaymentSubmission::CHANNELS)],
            'submission_id' => ['sometimes', 'integer', 'min:1'],
            'amount' => ['sometimes', 'string', 'max:13', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/'],
            'receiving_account_id' => ['required', 'integer'],
            'operation_number' => ['required', 'string', 'max:'.config('treasury.operation_number_max_length')],
            'paid_at' => ['required', 'date'],
            'origin_phone_last_four' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'origin_bank' => ['nullable', 'string', 'max:'.config('treasury.origin_bank_max_length')],
            'currency' => ['prohibited'], 'order_id' => ['prohibited'], 'user_id' => ['prohibited'],
            'status' => ['prohibited'], 'decision_reason' => ['prohibited'], 'reviewed_by' => ['prohibited'], 'reviewed_at' => ['prohibited'],
            'snapshot' => ['prohibited'], 'fingerprint' => ['prohibited'], 'idempotency_key' => ['prohibited'], 'metadata' => ['prohibited'],
            'payment_status' => ['prohibited'], 'reserved_until' => ['prohibited'], 'qr' => ['prohibited'], 'file' => ['prohibited'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $channel = $this->input('channel');
            if (in_array($channel, ['yape', 'plin'], true) && ! $this->filled('origin_phone_last_four')) {
                $validator->errors()->add('origin_phone_last_four', 'Yape y Plin requieren los últimos cuatro dígitos del celular de origen.');
            }
            if ($channel === 'bank_transfer' && ! $this->filled('origin_bank')) {
                $validator->errors()->add('origin_bank', 'La transferencia bancaria requiere el banco de origen.');
            }
            $allowed = ['submission_id', 'amount', 'channel', 'receiving_account_id', 'operation_number', 'paid_at', 'origin_phone_last_four', 'origin_bank'];
            if (array_diff(array_keys($this->all()), $allowed) !== []) {
                $validator->errors()->add('payment_submission', 'La solicitud contiene campos no permitidos.');
            }
        });
    }
}
