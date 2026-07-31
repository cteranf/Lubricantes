<?php
namespace App\Http\Requests\Admin;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->isAdmin() === true; }
    protected function prepareForValidation(): void { if($this->filled('sku')) $this->merge(['sku'=>Product::normalizeSku($this->input('sku'))]); }
    public function rules(): array
    {
        $product=$this->route('product');
        return [
            'name'=>['sometimes','required','string','max:255'], 'price'=>['sometimes','required','numeric','min:0'],
            'sku'=>['nullable','string','max:64',Rule::unique('products','sku')->ignore($product)], 'image'=>['nullable','image','max:2048'],
            'category_id'=>['nullable',function($a,$v,$fail) use($product){ if($v===null||$v==='') return; $q=Category::whereKey($v); if(!$q->exists()) return $fail('La categoria seleccionada no existe.'); if(Schema::hasColumn('categories','is_active')&&!$q->where('is_active',true)->exists()&&(int)$v!==(int)$product->category_id) $fail('La categoria seleccionada esta inactiva.'); }],
            'brand_id'=>['nullable',function($a,$v,$fail) use($product){ if($v===null||$v==='') return; $q=Brand::whereKey($v); if(!$q->exists()) return $fail('La marca seleccionada no existe.'); if(Schema::hasColumn('brands','is_active')&&!$q->where('is_active',true)->exists()&&(int)$v!==(int)$product->brand_id) $fail('La marca seleccionada esta inactiva.'); }],
            'description'=>['nullable','string'], 'viscosity'=>['nullable','string'],
        ];
    }
}
