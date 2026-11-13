<?php

namespace App\Http\Requests\Admin;

use App\Models\ProductReview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModerateProductReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([ProductReview::PUBLISHED, ProductReview::HIDDEN, ProductReview::REJECTED, ProductReview::PENDING])],
            'rejection_reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:500'],
            'internal_reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
