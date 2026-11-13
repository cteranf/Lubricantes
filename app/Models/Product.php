<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id', 'brand_id', 'name', 'slug', 'sku', 'description',
        'price', 'sale_price', 'viscosity', 'type', 'presentation', 'image_path', 'is_active', 'is_featured', 'allow_reviews',
    ];

    protected $appends = ['image_url', 'image_variants', 'image_srcset', 'image_dimensions'];

    protected $casts = ['allow_reviews' => 'boolean'];

    protected array $cleanupFilesAfterDelete = [];

    public function delete()
    {
        $connection = $this->getConnection();
        if ($this->exists && $connection->transactionLevel() === 0) {
            return $connection->transaction(function () {
                return parent::delete();
            });
        }

        return parent::delete();
    }

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

        static::deleting(function (Product $product) {
            $product->cleanupFilesAfterDelete = [];
            $connection = $product->getConnection();
            if ($product->exists && $product->getKey() !== null) {
                $connection->table($product->getTable())
                    ->where($product->getKeyName(), $product->getKey())
                    ->lockForUpdate()
                    ->first();
            }
            $product->setRelation('images', $product->images()->get());
            $publicDisk = (string) config('product_images.public_disk', 'public');
            $legacy = self::safeLegacyImagePath($product->image_path);
            if ($legacy !== null) {
                $product->cleanupFilesAfterDelete[] = ['disk' => $publicDisk, 'path' => $legacy, 'kind' => 'file', 'role' => 'legacy_public'];
            }
            foreach ($product->images as $image) {
                foreach ($image->collectCleanupPaths() as $file) {
                    if (! is_array($file) || ! is_string($file['disk'] ?? null) || ! is_string($file['path'] ?? null)) {
                        continue;
                    }
                    $role = self::cleanupRole($file['disk'], $file['path']);
                    if ($role !== null) {
                        $product->cleanupFilesAfterDelete[] = [
                            'disk' => $file['disk'],
                            'path' => $file['path'],
                            'kind' => 'file',
                            'role' => $role,
                        ];
                    }
                }
            }

            // A deleted product cannot have a live worker; this also catches staged
            // files whose generation was interrupted before its image row was updated.
            $temporaryPrefix = trim((string) config('product_images.temporary_prefix', 'products/image-processing'), '/');
            $originalDisk = (string) config('product_images.original_disk', 'local');
            $temporaryDirectory = $temporaryPrefix.'/'.(int) $product->getKey();
            if (self::isManagedPath($temporaryDirectory) && self::pathHasPrefix($temporaryDirectory, $temporaryPrefix.'/')) {
                $product->cleanupFilesAfterDelete[] = [
                    'disk' => $originalDisk,
                    'path' => $temporaryDirectory,
                    'kind' => 'directory',
                    'role' => 'temporary_generation',
                ];
            }

            $deduplicated = [];
            foreach ($product->cleanupFilesAfterDelete as $file) {
                $key = $file['disk']."\0".$file['kind']."\0".$file['path'];
                $deduplicated[$key] = $file;
            }
            $product->cleanupFilesAfterDelete = array_values($deduplicated);
        });

        static::deleted(function (Product $product) {
            $files = $product->cleanupFilesAfterDelete;
            $product->cleanupFilesAfterDelete = [];
            $connection = $product->getConnection();
            $cleanup = static function () use ($connection, $files): void {
                foreach ($files as $file) {
                    self::removeCapturedFile($connection, $file);
                }
            };

            if ($connection->transactionLevel() > 0) {
                try {
                    $connection->afterCommit($cleanup);
                } catch (Throwable) {
                    Log::warning('Product image cleanup could not be scheduled.', ['operation' => 'product_delete']);
                }

                return;
            }

            $cleanup();
        });
    }

    public static function normalizeSku(?string $sku): ?string
    {
        if ($sku === null || trim($sku) === '') {
            return null;
        }
        $normalized = preg_replace('/[^A-Z0-9_-]+/', '-', Str::upper(trim($sku)));

        return substr(trim($normalized, '-'), 0, 64) ?: null;
    }

    public static function safeLegacyImagePath(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '' || str_contains($value, '..') || str_contains($value, '\\')) {
            return null;
        }

        $path = preg_replace('#^/?storage/#', '', trim($value));

        return is_string($path) && str_starts_with($path, 'products/') ? $path : null;
    }

    private static function cleanupRole(string $disk, string $path): ?string
    {
        $publicDisk = (string) config('product_images.public_disk', 'public');
        $originalDisk = (string) config('product_images.original_disk', 'local');
        $originalPrefix = trim((string) config('product_images.original_prefix', 'products/originals'), '/');
        $variantPrefix = trim((string) config('product_images.variant_prefix', 'products/optimized'), '/');

        if ($disk === $originalDisk && self::pathHasPrefix($path, $originalPrefix.'/') && self::isManagedPath($path)) {
            return 'private_original';
        }
        if ($disk === $publicDisk && self::pathHasPrefix($path, $variantPrefix.'/') && self::isManagedPath($path)) {
            return 'published_variant';
        }
        if ($disk === $publicDisk && self::pathHasPrefix($path, 'products/')
            && ! self::pathHasPrefix($path, $originalPrefix.'/')
            && ! self::pathHasPrefix($path, trim((string) config('product_images.temporary_prefix', 'products/image-processing'), '/').'/')
            && self::isManagedPath($path)) {
            return 'legacy_public';
        }

        return null;
    }

    private static function isManagedPath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || preg_match('/\A[A-Za-z0-9._\/-]+\z/D', $path) !== 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private static function pathHasPrefix(string $path, string $prefix): bool
    {
        return $prefix !== '' && str_starts_with($path, $prefix);
    }

    private static function removeCapturedFile($connection, array $file): void
    {
        if (! in_array($file['role'] ?? null, ['legacy_public', 'published_variant', 'private_original', 'temporary_generation'], true)
            || ! in_array($file['kind'] ?? null, ['file', 'directory'], true)
            || ! is_string($file['disk'] ?? null) || ! is_string($file['path'] ?? null)
            || ! self::isManagedPath($file['path'])) {
            return;
        }

        $publicDisk = (string) config('product_images.public_disk', 'public');
        $originalDisk = (string) config('product_images.original_disk', 'local');
        $temporaryPrefix = trim((string) config('product_images.temporary_prefix', 'products/image-processing'), '/');
        $isTemporary = $file['role'] === 'temporary_generation';
        if ($isTemporary) {
            if ($file['disk'] !== $originalDisk || ! self::pathHasPrefix($file['path'], $temporaryPrefix.'/')
                || $file['kind'] !== 'directory') {
                return;
            }
        } elseif (($file['role'] === 'private_original' && $file['disk'] !== $originalDisk)
            || (in_array($file['role'], ['legacy_public', 'published_variant'], true) && $file['disk'] !== $publicDisk)
            || $file['kind'] !== 'file') {
            return;
        }

        try {
            if (! $isTemporary && self::isStillReferenced($connection, $file)) {
                return;
            }

            $disk = Storage::disk($file['disk']);
            $exists = $isTemporary ? $disk->directoryExists($file['path']) : $disk->exists($file['path']);
            if (! $exists) {
                return;
            }
            $deleted = $isTemporary ? $disk->deleteDirectory($file['path']) : $disk->delete($file['path']);
            if (! $deleted) {
                Log::warning('Product image cleanup failed after deletion.', [
                    'operation' => 'product_delete',
                    'file_role' => $file['role'],
                ]);
            }
        } catch (Throwable) {
            Log::warning('Product image cleanup failed after deletion.', [
                'operation' => 'product_delete',
                'file_role' => $file['role'],
            ]);
        }
    }

    private static function isStillReferenced($connection, array $file): bool
    {
        $path = $file['path'];
        if ($file['disk'] === (string) config('product_images.public_disk', 'public')) {
            $publicForms = [$path, '/storage/'.$path, 'storage/'.$path];
            if ($connection->table('products')->whereIn('image_path', $publicForms)->exists()
                || $connection->table('product_images')->whereIn('image_path', $publicForms)->exists()) {
                return true;
            }

            // A conservative match is intentional: a false positive leaves an orphan,
            // while a false negative could remove a still-published variant.
            if ($connection->table('product_images')->where('variants', 'like', '%'.$path.'%')->exists()) {
                return true;
            }
        } else {
            if ($connection->table('product_images')->where('original_path', $path)->exists()
                || $connection->table('product_images')->where('pending_original_path', $path)->exists()) {
                return true;
            }
        }

        return false;
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
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function specifications()
    {
        return $this->hasMany(ProductSpecification::class)->orderBy('sort_order')->orderBy('id');
    }

    public function reviews()
    {
        return $this->hasMany(ProductReview::class);
    }

    public function primaryImage()
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true)->orderBy('sort_order')->orderBy('id');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function inventories()
    {
        return $this->hasMany(WarehouseInventory::class);
    }

    public function warehouses()
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_inventories')->withPivot('quantity')->withTimestamps();
    }

    public function primaryImageRecord(): ?ProductImage
    {
        if ($this->relationLoaded('images')) {
            $published = $this->images
                ->filter(fn (ProductImage $image) => $image->hasPublishedImage())
                ->sort(fn (ProductImage $left, ProductImage $right) => [(int) $left->sort_order, (int) $left->id] <=> [(int) $right->sort_order, (int) $right->id]);

            return $published->first(fn (ProductImage $image) => $image->is_primary) ?? $published->first();
        }

        if ($this->relationLoaded('primaryImage')) {
            $primary = $this->getRelation('primaryImage');

            return $primary instanceof ProductImage && $primary->hasPublishedImage() ? $primary : null;
        }

        return null;
    }

    public function getImageUrlAttribute(): ?string
    {
        $image = $this->primaryImageRecord();
        $url = $image?->publicUrl();
        if ($url !== null) {
            return $url;
        }

        $legacy = self::safeLegacyImagePath($this->attributes['image_path'] ?? null);
        if ($legacy === null) {
            return null;
        }
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk('public');

            return $disk->exists($legacy) ? $disk->url($legacy) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function getImageVariantsAttribute(): array
    {
        return $this->primaryImageRecord()?->publicVariants() ?? [];
    }

    public function getImageSrcsetAttribute(): ?string
    {
        return $this->primaryImageRecord()?->publicSrcset();
    }

    public function getImageDimensionsAttribute(): ?array
    {
        return $this->primaryImageRecord()?->publicDimensions();
    }
}
