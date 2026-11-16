<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreInventoryReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true && $this->user()?->is_active === true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:'.config('inventory_receipts.max_lines', 100)],
            'lines.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.config('inventory_receipts.max_line_quantity', 2147483647)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $lines = $this->input('lines');
            if (! is_array($lines) || count($lines) > (int) config('inventory_receipts.max_lines', 100)) {
                return;
            }
            $total = 0;
            foreach ($lines as $line) {
                if (is_array($line) && isset($line['quantity']) && filter_var($line['quantity'], FILTER_VALIDATE_INT) !== false) {
                    $total += (int) $line['quantity'];
                }
            }
            if ($total > (int) config('inventory_receipts.max_total_units', 100000)) {
                $validator->errors()->add('lines', 'La recepción supera el máximo de unidades permitido.');
            }
        });
    }
}
