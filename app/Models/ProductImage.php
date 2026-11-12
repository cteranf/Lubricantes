<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductImage extends Model
{
    use HasFactory;

    public const VARIANT_KEYS = ['thumbnail', 'card', 'medium', 'detail', 'detail_source', 'zoom'];

    protected $fillable = [
        'product_id', 'image_path', 'sort_order', 'is_primary', 'original_path', 'variants',
        'source_width', 'source_height', 'processing_status', 'generation_id', 'pending_original_path', 'processed_at',
    ];

    protected $hidden = ['original_path', 'pending_original_path', 'generation_id', 'variants'];

    protected $casts = [
        'variants' => 'array',
        'is_primary' => 'boolean',
        'source_width' => 'integer',
        'source_height' => 'integer',
        'processed_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function isReady(): bool
    {
        return $this->processing_status === 'ready' && $this->hasPublishedImage();
    }

    public function hasPublishedImage(): bool
    {
        $path = $this->publicPath($this->image_path) ?? Product::safeLegacyImagePath($this->image_path);
        if ($path === null) {
            return false;
        }
        try {
            return Storage::disk((string) config('product_images.public_disk', 'public'))->exists($path);
        } catch (Throwable) {
            return false;
        }
    }

    public function canRegenerate(): bool
    {
        $path = $this->privatePath($this->original_path);
        if ($path === null) {
            return false;
        }

        try {
            return Storage::disk((string) config('product_images.original_disk', 'local'))->exists($path);
        } catch (Throwable) {
            return false;
        }
    }

    public function bestPublicVariant(): ?array
    {
        $variants = $this->publicVariants();
        if ($variants !== []) {
            $key = array_key_last($variants);

            return ['key' => $key] + $variants[$key];
        }

        $path = $this->publicPath($this->image_path) ?? Product::safeLegacyImagePath($this->image_path);
        if ($path !== null) {
            try {
                $disk = Storage::disk((string) config('product_images.public_disk', 'public'));
                if ($disk->exists($path)) {
                    return ['key' => 'legacy', 'url' => $disk->url($path), 'width' => null, 'height' => null];
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    public function publicVariants(): array
    {
        if (! $this->hasPublishedImage()) {
            return [];
        }

        $result = [];
        $stored = $this->variants;
        if (! is_array($stored)) {
            return [];
        }

        foreach (self::VARIANT_KEYS as $key) {
            $variant = $stored[$key] ?? null;
            $path = $this->publicPath(is_array($variant) ? ($variant['path'] ?? null) : null);
            $width = filter_var(is_array($variant) ? ($variant['width'] ?? null) : null, FILTER_VALIDATE_INT);
            $height = filter_var(is_array($variant) ? ($variant['height'] ?? null) : null, FILTER_VALIDATE_INT);
            if ($path === null || ! $width || ! $height || $width < 1 || $height < 1) {
                continue;
            }
            try {
                $disk = Storage::disk((string) config('product_images.public_disk', 'public'));
                if (! $disk->exists($path)) {
                    continue;
                }
                $result[$key] = [
                    'url' => $disk->url($path),
                    'width' => $width,
                    'height' => $height,
                ];
            } catch (Throwable) {
                continue;
            }
        }

        uasort($result, static fn (array $left, array $right): int => $left['width'] <=> $right['width']);

        return $result;
    }

    public function publicUrl(): ?string
    {
        return $this->bestPublicVariant()['url'] ?? null;
    }

    public function publicSrcset(): ?string
    {
        $entries = [];
        foreach ($this->publicVariants() as $variant) {
            $entries[$variant['width']] = $variant['url'].' '.$variant['width'].'w';
        }

        return $entries ? implode(', ', array_values($entries)) : null;
    }

    public function publicDimensions(): ?array
    {
        $best = $this->bestPublicVariant();
        if (! $best || ! is_int($best['width']) || ! is_int($best['height'])) {
            return null;
        }

        return ['width' => $best['width'], 'height' => $best['height']];
    }

    public function safeOriginalPath(): ?string
    {
        return $this->privatePath($this->original_path);
    }

    public function safePendingOriginalPath(): ?string
    {
        return $this->privatePath($this->pending_original_path);
    }

    public function collectCleanupPaths(): array
    {
        $paths = [];
        foreach ([$this->original_path, $this->pending_original_path] as $path) {
            $safe = $this->privatePath($path);
            if ($safe !== null) {
                $paths[] = ['disk' => (string) config('product_images.original_disk', 'local'), 'path' => $safe];
            }
        }
        foreach (is_array($this->variants) ? $this->variants : [] as $variant) {
            $path = $this->publicPath(is_array($variant) ? ($variant['path'] ?? null) : null);
            if ($path !== null) {
                $paths[] = ['disk' => (string) config('product_images.public_disk', 'public'), 'path' => $path];
            }
        }
        $legacy = Product::safeLegacyImagePath($this->image_path);
        if ($legacy !== null) {
            $paths[] = ['disk' => (string) config('product_images.public_disk', 'public'), 'path' => $legacy];
        }

        return $paths;
    }

    private function publicPath(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || str_contains($value, '..') || str_contains($value, '\\')) {
            return null;
        }

        $path = preg_replace('#^/?storage/#', '', $value);
        $prefix = trim((string) config('product_images.variant_prefix', 'products/optimized'), '/').'/';

        return is_string($path) && str_starts_with($path, $prefix) ? $path : null;
    }

    private function privatePath(mixed $value): ?string
    {
        $prefix = trim((string) config('product_images.original_prefix', 'products/originals'), '/').'/';
        if (! is_string($value) || ! str_starts_with($value, $prefix) || str_contains($value, '..') || str_contains($value, '\\')) {
            return null;
        }

        return $value;
    }
}
