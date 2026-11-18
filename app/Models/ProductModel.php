<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductModel extends Model
{
    use HasFactory;

    protected $fillable = ['brand_id', 'name', 'normalized_name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public static function normalizeName(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);

        return Str::upper($name);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'product_model_id');
    }
}
