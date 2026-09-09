<?php

namespace App\Http\Requests\Admin;

class UpdatePaymentReceivingAccountRequest extends StorePaymentReceivingAccountRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['code'] = ['sometimes', 'string', 'max:50'];

        return $rules;
    }
}
