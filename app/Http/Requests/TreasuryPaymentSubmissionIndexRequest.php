<?php

namespace App\Http\Requests;

use App\Models\PaymentSubmission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TreasuryPaymentSubmissionIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(PaymentSubmission::STATUSES)],
            'channel' => ['nullable', 'string', Rule::in(PaymentSubmission::CHANNELS)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'receiving_account_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
