<?php

namespace App\Http\Requests\Admin;

use App\Models\ContactInquiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListContactInquiriesRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->isAdmin() === true; }
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:190'],
            'status' => ['nullable', Rule::in(ContactInquiry::STATUSES)],
            'assigned_to' => ['nullable', 'string', 'max:32'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'archived' => ['nullable', Rule::in(['active', 'archived', 'all'])],
            'sort' => ['nullable', Rule::in(['received_desc', 'received_asc', 'activity_desc', 'activity_asc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
