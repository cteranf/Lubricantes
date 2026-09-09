<?php

namespace App\Http\Requests\Admin;

use App\Models\PaymentReceivingAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentReceivingAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:50', Rule::unique('payment_receiving_accounts', 'code')], 'channel' => ['required', Rule::in(PaymentReceivingAccount::CHANNELS)], 'display_name' => ['required', 'string', 'max:120'], 'holder_name' => ['required', 'string', 'max:150'], 'currency' => ['required', Rule::in(['PEN'])], 'phone' => ['nullable', 'string', 'max:30'], 'bank_name' => ['nullable', 'string', 'max:120'], 'account_number' => ['nullable', 'string', 'max:120'], 'cci' => ['nullable', 'string', 'max:120'], 'is_active' => ['sometimes', 'boolean'], 'is_default' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999999'], 'qr' => ['nullable', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:'.config('treasury.qr_max_kilobytes')]];
    }
}
