<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignContactInquiryRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->isAdmin() === true; }
    public function rules(): array
    {
        return [
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'admin'))],
        ];
    }
}
