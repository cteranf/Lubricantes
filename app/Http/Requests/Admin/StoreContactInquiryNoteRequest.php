<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactInquiryNoteRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->isAdmin() === true; }
    protected function prepareForValidation(): void
    {
        if (is_string($this->body)) $this->merge(['body' => trim(str_replace(["\r\n", "\r"], "\n", $this->body))]);
    }
    public function rules(): array { return ['body' => ['required', 'string', 'min:2', 'max:2000']]; }
}
