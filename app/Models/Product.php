<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'brand_id',
        'name',
        'slug',
        'sku',
        'description',
        'specifications',
        'price',
        'sale_price',
        'viscosity',
        'type',
        'presentation',
        'image_path',
        'is_active',
        'is_featured',
    ];

    protected $appends = ['image_url'];

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            $product->sku = self::normalizeSku($product->sku);
        });

        static::created(function (Product $product) {
            if (! $product->sku) {
                $base = 'LUB-'.str_pad((string) $product->id, 6, '0', STR_PAD_LEFT);
                $sku = $base;
                $suffix = 1;
                while (self::where('sku', $sku)->whereKeyNot($product->id)->exists()) {
                    $sku = $base.'-'.$suffix++;
                }
                $product->forceFill(['sku' => $sku])->saveQuietly();
            }
        });

        static::updating(function (Product $product) {
            if ($product->isDirty('sku')) {
                $product->sku = self::normalizeSku($product->sku);
            }
        });
    }

    public static function normalizeSku(?string $sku): ?string
    {
        if ($sku === null || trim($sku) === '') return null;
        $normalized = preg_replace('/[^A-Z0-9_-]+/', '-', Str::upper(trim($sku)));
        return substr(trim($normalized, '-'), 0, 64) ?: null;
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function getImageUrlAttribute(): ?string
    {
        $path = $this->image_path;

        if (! $path && $this->relationLoaded('images')) {
            $path = $this->images->first()?->image_path;
        }

        if (! $path) {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return preg_match('/^https?:\/\//i', $path) ? $path : null;
        }

        $normalized = str_replace('\\', '/', trim($path));
        if (preg_match('/^[A-Z]:\//i', $normalized) || str_contains($normalized, '../')) {
            return null;
        }

        $normalized = preg_replace('#^/?storage/app/public/#', '', $normalized);
        $normalized = preg_replace('#^/?storage/#', '', $normalized);
        $normalized = ltrim($normalized, '/');

        return $normalized === '' ? null : Storage::disk('public')->url($normalized);
    }

    public function inventories()
    {
        return $this->hasMany(WarehouseInventory::class);
    }

    public function warehouses()
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_inventories')->withPivot('quantity')->withTimestamps();
    }
}
