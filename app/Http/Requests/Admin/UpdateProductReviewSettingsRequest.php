<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductReviewSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'show_reviews_publicly' => ['required', 'boolean'],
            'accept_new_reviews' => ['required', 'boolean'],
            'require_moderation' => ['required', 'boolean'],
        ];
    }
}
