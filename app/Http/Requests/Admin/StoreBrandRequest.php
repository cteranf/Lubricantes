<?php
namespace App\Http\Requests\Admin;
use App\Models\Brand;
use Illuminate\Foundation\Http\FormRequest;
class StoreBrandRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->isAdmin() === true; }
    protected function prepareForValidation(): void { $this->merge(['name' => preg_replace('/\s+/u', ' ', trim((string) $this->input('name')))]); }
    public function rules(): array { return ['name' => ['required','string','max:255', function ($a,$v,$fail) { if (Brand::whereRaw('LOWER(name) = ?', [mb_strtolower($v)])->exists()) $fail('Ya existe una marca con este nombre.'); }]]; }
}
