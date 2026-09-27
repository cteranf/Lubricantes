<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentReceivingAccountDefaultRequest extends FormRequest
{
    public function rules(): array
    {
        return ['is_default' => ['required', 'boolean', 'accepted']];
    }
}
