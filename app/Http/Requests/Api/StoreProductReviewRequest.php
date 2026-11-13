<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductReviewRequest extends FormRequest
{
    use ProductReviewInput;

    public function authorize(): bool
    {
        return $this->user()?->is_active === true;
    }
}
