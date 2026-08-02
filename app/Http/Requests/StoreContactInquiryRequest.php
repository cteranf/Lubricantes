<?php

namespace App\Http\Requests;

use App\Services\ContactFormSecurity;
use Illuminate\Foundation\Http\FormRequest;

class StoreContactInquiryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $normalizeSpaces = fn ($value) => is_string($value) ? preg_replace('/\s+/u', ' ', trim($value)) : $value;
        $message = is_string($this->message)
            ? trim(preg_replace('/[ \t]+$/m', '', str_replace(["\r\n", "\r"], "\n", $this->message)))
            : $this->message;

        $this->merge([
            'name' => $normalizeSpaces($this->name),
            'email' => is_string($this->email) ? mb_strtolower(trim($this->email)) : $this->email,
            'phone' => is_string($this->phone) ? trim(preg_replace('/\s+/u', ' ', $this->phone)) : $this->phone,
            'subject' => $normalizeSpaces($this->subject),
            'message' => $message,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\-\s]+$/'],
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'submission_token' => ['required', 'uuid'],
            'website' => ['nullable', 'max:0'],
            'form_started_at' => ['required', 'integer'],
            'form_signature' => ['required', 'string', 'size:64'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->hasAny(['submission_token', 'form_started_at', 'form_signature'])) return;
            $valid = app(ContactFormSecurity::class)->validContext(
                (string) $this->submission_token,
                (int) $this->form_started_at,
                (string) $this->form_signature
            );
            if (! $valid) $validator->errors()->add('form_token', 'El formulario expiró o se envió demasiado rápido. Actualízalo e inténtalo nuevamente.');

            if ($this->filled('phone')) {
                $digits = preg_replace('/\D+/', '', (string) $this->phone);
                if (strlen($digits) < 6 || strlen($digits) > 15) {
                    $validator->errors()->add('phone', 'Ingresa un teléfono válido de entre 6 y 15 dígitos.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'website.max' => 'No se pudo procesar la consulta.',
            'phone.regex' => 'El teléfono contiene caracteres no permitidos.',
        ];
    }
}
