<?php

namespace App\Http\Requests\Admin;

use App\Models\BusinessProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBusinessProfileRequest extends FormRequest
{
    private const FIELDS = [
        'commercial_name', 'legal_name', 'tax_id_type', 'tax_id_number', 'country_code',
        'fiscal_address', 'contact_email', 'contact_phone', 'logo', 'remove_logo',
        'boleta_enabled', 'factura_enabled', 'default_receipt_type',
    ];

    public function authorize(): bool
    {
        return (bool) ($this->user()?->is_active && $this->user()?->isAdmin());
    }

    protected function prepareForValidation(): void
    {
        foreach (['commercial_name', 'legal_name', 'tax_id_number', 'fiscal_address', 'contact_email', 'contact_phone'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
        if (is_string($this->input('tax_id_type'))) {
            $this->merge(['tax_id_type' => strtoupper(trim($this->input('tax_id_type')))]);
        }
        if (is_string($this->input('country_code'))) {
            $this->merge(['country_code' => strtoupper(trim($this->input('country_code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'commercial_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_id_type' => ['sometimes', 'nullable', 'string', 'in:RUC'],
            'tax_id_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country_code' => ['sometimes', 'required', 'string', 'size:2', 'in:PE'],
            'fiscal_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/\A[0-9+() .-]{6,40}\z/'],
            'logo' => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.(int) config('brand_images.max_kilobytes', 2048)],
            'remove_logo' => ['sometimes', 'boolean'],
            'boleta_enabled' => ['sometimes', 'boolean'],
            'factura_enabled' => ['sometimes', 'boolean'],
            'default_receipt_type' => ['sometimes', 'in:boleta,factura'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $transportFields = ['_token'];
            if ($this->getRealMethod() === 'POST' && $this->method() === 'PUT' && strtoupper((string) $this->input('_method')) === 'PUT') {
                $transportFields[] = '_method';
            }

            $extra = array_diff(array_keys($this->all()), self::FIELDS, $transportFields);
            if ($extra !== []) {
                $validator->errors()->add('request', 'La solicitud contiene campos no permitidos.');
            }
            if ($this->hasFile('logo') && $this->boolean('remove_logo')) {
                $validator->errors()->add('remove_logo', 'Selecciona un logo nuevo o retira el guardado, pero no ambas acciones.');
            }

            $profile = BusinessProfile::query()->where('singleton_key', BusinessProfile::SINGLETON_KEY)->first();
            $boleta = $this->has('boleta_enabled') ? $this->boolean('boleta_enabled') : (bool) ($profile?->boleta_enabled ?? true);
            $factura = $this->has('factura_enabled') ? $this->boolean('factura_enabled') : (bool) ($profile?->factura_enabled ?? false);
            $default = $this->input('default_receipt_type', $profile?->default_receipt_type ?? 'boleta');
            if (! $boleta && ! $factura) {
                $validator->errors()->add('boleta_enabled', 'Debe quedar habilitado al menos un tipo de comprobante.');
            }
            if (($default === 'boleta' && ! $boleta) || ($default === 'factura' && ! $factura)) {
                $validator->errors()->add('default_receipt_type', 'El documento inicial debe estar habilitado.');
            }

            $current = $profile;
            $type = $this->input('tax_id_type', $current?->tax_id_type);
            $number = $this->input('tax_id_number', $current?->tax_id_number);
            if (filled($number) && $type !== 'RUC') {
                $validator->errors()->add('tax_id_number', 'El número informado requiere el tipo RUC.');
            } elseif (filled($number) && (! preg_match('/\A(?:10|20)[0-9]{9}\z/D', $number) || ! $this->hasValidRucChecksum($number))) {
                $validator->errors()->add('tax_id_number', 'Ingresa un RUC peruano con estructura y dígito verificador válidos. Esta validación local no consulta SUNAT.');
            }
        });
    }

    public function permittedFields(): array
    {
        return array_intersect_key($this->validated(), array_flip(self::FIELDS));
    }

    private function hasValidRucChecksum(string $number): bool
    {
        $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($index = 0; $index < 10; $index++) {
            $sum += ((int) $number[$index]) * $weights[$index];
        }
        $check = 11 - ($sum % 11);
        $check = $check === 10 ? 0 : ($check === 11 ? 1 : $check);

        return $check === (int) $number[10];
    }
}
