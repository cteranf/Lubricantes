<?php
namespace App\Http\Requests\Admin;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->isAdmin() === true; }
    protected function prepareForValidation(): void { $this->merge(['name' => preg_replace('/\s+/u', ' ', trim((string) $this->input('name')))]); }
    public function rules(): array { return ['name' => ['required','string','max:255', function ($a,$v,$fail) { $q=Category::whereRaw('LOWER(name) = ?', [mb_strtolower($v)]); if ($item=$this->route('category')) $q->whereKeyNot($item->getKey()); if ($q->exists()) $fail('Ya existe una categoria con este nombre.'); }], 'image'=>['nullable','image','max:2048']]; }
}
