<?php

namespace App\Http\Requests\Admin;

use App\Models\ProductReview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProductReviewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(ProductReview::STATUSES)],
            'product_id' => ['sometimes', 'integer', 'min:1', 'exists:products,id'],
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'search' => ['sometimes', 'string', 'min:1', 'max:80'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'string', Rule::in(['newest', 'highest', 'lowest'])],
        ];
    }
}
