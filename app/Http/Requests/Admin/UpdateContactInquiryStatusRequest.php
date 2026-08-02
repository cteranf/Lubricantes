<?php

namespace App\Http\Requests\Admin;

use App\Models\ContactInquiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContactInquiryStatusRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->isAdmin() === true; }
    public function rules(): array { return ['status' => ['required', Rule::in(ContactInquiry::STATUSES)]]; }
}
