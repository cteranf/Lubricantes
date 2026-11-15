<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesNewsImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateNewsRequest extends FormRequest
{
    use ValidatesNewsImage;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['required', 'string'],
            'content' => ['nullable', 'string'],
            'image' => $this->newsImageRules(false),
            'remove_image' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'published_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->hasFile('image') && $this->boolean('remove_image')) {
                $validator->errors()->add('image', 'Selecciona una imagen nueva o solicita retirar la actual, no ambas acciones.');
            }
        });
    }
}
