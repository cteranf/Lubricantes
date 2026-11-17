<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class PreviewInventoryReceiptImportRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $existingLines = $this->input('existing_lines');
        if (is_string($existingLines)) {
            $decoded = json_decode($existingLines, true);
            $this->merge(['existing_lines' => is_array($decoded) ? $decoded : null]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true && $this->user()?->is_active === true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'file' => [
                'required',
                'file',
                'max:'.(int) config('inventory_receipts.max_import_kilobytes', 2048),
                function (string $attribute, $file, \Closure $fail) {
                    if (! $file instanceof UploadedFile) {
                        return;
                    }
                    if (! in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'xlsx'], true)) {
                        $fail('El archivo debe tener extensión .csv o .xlsx.');
                    }
                },
            ],
            'existing_lines' => ['present', 'array', 'max:'.(int) config('inventory_receipts.max_lines', 100)],
            'existing_lines.*.product_id' => ['required', 'integer', 'min:1'],
            'existing_lines.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
