<?php

namespace App\Services;

use App\Models\Brand;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class BrandImageService
{
    public function store(UploadedFile $file): string
    {
        $absolutePath = $file->getRealPath();
        $details = $absolutePath ? @getimagesize($absolutePath) : false;
        $mime = is_array($details) ? ($details['mime'] ?? null) : null;
        $width = is_array($details) ? (int) ($details[0] ?? 0) : 0;
        $height = is_array($details) ? (int) ($details[1] ?? 0) : 0;
        $maxMegapixels = (int) config('brand_images.max_megapixels', 10);

        if (! $file->isValid()
            || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $file->getMimeType() !== $mime
            || $width < 1
            || $height < 1
            || $width > (int) config('brand_images.max_width', 4000)
            || $height > (int) config('brand_images.max_height', 2500)
            || ($width * $height) > ($maxMegapixels * 1_000_000)) {
            throw new \InvalidArgumentException('La imagen no cumple los requisitos permitidos.');
        }

        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($absolutePath),
            'image/png' => @imagecreatefrompng($absolutePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolutePath) : false,
        };
        if (! $source) {
            throw new \InvalidArgumentException('No se pudo procesar la imagen.');
        }

        $target = null;
        try {
            if (! function_exists('imagecreatetruecolor')) {
                throw new \RuntimeException('El procesamiento de imágenes no está disponible.');
            }

            $scale = min(
                1,
                (int) config('brand_images.output_width', 2000) / $width,
                (int) config('brand_images.output_height', 1250) / $height
            );
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $target = imagecreatetruecolor($targetWidth, $targetHeight);
            $preservesAlpha = in_array($mime, ['image/png', 'image/webp'], true);
            if ($preservesAlpha) {
                imagealphablending($target, false);
                imagesavealpha($target, true);
                imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
            } else {
                $white = imagecolorallocate($target, 255, 255, 255);
                imagefill($target, 0, 0, $white);
            }
            if (! imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
                throw new \RuntimeException('No se pudo procesar la imagen.');
            }

            if (function_exists('imagewebp')) {
                $format = 'webp';
                ob_start();
                $encoded = imagewebp($target, null, (int) config('brand_images.webp_quality', 86));
                $bytes = ob_get_clean();
            } elseif ($mime === 'image/webp') {
                // Keep valid WebP bytes (and any alpha channel) when encoding is unavailable.
                $format = 'webp';
                $bytes = file_get_contents($absolutePath);
                $encoded = is_string($bytes) && $bytes !== '';
            } else {
                $format = $mime === 'image/png' ? 'png' : 'jpg';
                ob_start();
                $encoded = $format === 'png' ? imagepng($target, null, 6) : imagejpeg($target, null, 88);
                $bytes = ob_get_clean();
            }

            if (! $encoded || ! is_string($bytes) || $bytes === '') {
                throw new \RuntimeException('No se pudo procesar la imagen.');
            }

            $directory = trim((string) config('brand_images.directory', 'brands'), '/');
            $path = $directory.'/'.Str::uuid().'.'.$format;
            if (! Storage::disk((string) config('brand_images.disk', 'public'))->put($path, $bytes)) {
                throw new \RuntimeException('No se pudo guardar la imagen.');
            }

            return $path;
        } finally {
            imagedestroy($source);
            if ($target) {
                imagedestroy($target);
            }
        }
    }

    public function publicUrl(?string $storedPath): ?string
    {
        $path = $this->safePath($storedPath);
        $disk = Storage::disk((string) config('brand_images.disk', 'public'));

        return $path && $disk->exists($path) ? $disk->url($path) : null;
    }

    public function safePath(?string $storedPath): ?string
    {
        if (! is_string($storedPath) || $storedPath === '' || preg_match('/[\x00-\x1F%\\\\?#]/', $storedPath)) {
            return null;
        }

        if (str_starts_with($storedPath, '/storage/')) {
            $path = substr($storedPath, strlen('/storage/'));
        } elseif (str_starts_with($storedPath, 'storage/')) {
            $path = substr($storedPath, strlen('storage/'));
        } elseif (str_starts_with($storedPath, '/')) {
            return null;
        } else {
            $path = $storedPath;
        }

        $directory = trim((string) config('brand_images.directory', 'brands'), '/');
        if (! str_starts_with($path, $directory.'/') || ! preg_match('/\.(?:webp|png|jpe?g)$/i', $path)) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }

    public function create(array $attributes, ?UploadedFile $image): Brand
    {
        $newPath = $image ? $this->store($image) : null;
        unset($attributes['image']);
        $model = new Brand;
        $connection = DB::connection($model->getConnectionName());

        try {
            $brand = $connection->transaction(function () use ($attributes, $newPath): Brand {
                $brand = Brand::create($attributes);
                if ($newPath !== null) {
                    $brand->forceFill(['logo' => $newPath])->save();
                }

                return $brand;
            });
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                $this->deleteIfUnreferenced($newPath, $model);
            }
            throw $exception;
        }

        return $brand;
    }

    public function update(Brand $brand, array $attributes, ?UploadedFile $image): Brand
    {
        $newPath = $image ? $this->store($image) : null;
        unset($attributes['image']);
        $oldPath = null;
        $connection = DB::connection($brand->getConnectionName());

        try {
            $connection->transaction(function () use ($brand, $attributes, $newPath, &$oldPath): void {
                $locked = $brand->newQuery()->whereKey($brand->getKey())->lockForUpdate()->firstOrFail();
                if ($newPath !== null) {
                    $oldPath = $locked->logo;
                    $attributes['logo'] = $newPath;
                }
                $locked->fill($attributes)->save();
                $brand->setRawAttributes($locked->getAttributes(), true);
            });
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                $this->deleteIfUnreferenced($newPath, $brand);
            }
            throw $exception;
        }

        if ($newPath !== null && $oldPath !== null) {
            $this->afterCommit($brand, fn () => $this->deleteIfUnreferenced($oldPath, $brand));
        }

        return $brand;
    }

    public function remove(Brand $brand): void
    {
        $oldPath = null;
        $connection = DB::connection($brand->getConnectionName());
        $connection->transaction(function () use ($brand, &$oldPath): void {
            $locked = $brand->newQuery()->whereKey($brand->getKey())->lockForUpdate()->firstOrFail();
            $oldPath = $locked->logo;
            $locked->forceFill(['logo' => null])->save();
            $brand->setRawAttributes($locked->getAttributes(), true);
        });

        if ($oldPath !== null) {
            $this->afterCommit($brand, fn () => $this->deleteIfUnreferenced($oldPath, $brand));
        }
    }

    public function deleteIfUnreferenced(?string $storedPath, ?Brand $brand = null): void
    {
        $path = $this->safePath($storedPath);
        if (! $path) {
            return;
        }

        try {
            $aliases = array_unique([$storedPath, $path, '/'.$path, 'storage/'.$path, '/storage/'.$path]);
            $query = $brand ? $brand->newQuery() : (new Brand)->newQuery();
            if (! $query->whereIn('logo', $aliases)->exists()) {
                Storage::disk((string) config('brand_images.disk', 'public'))->delete($path);
            }
        } catch (Throwable) {
            // A safe orphan is preferable to deleting an image still referenced by a brand.
        }
    }

    private function afterCommit(Brand $brand, callable $callback): void
    {
        $connection = DB::connection($brand->getConnectionName());
        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($callback);
        } else {
            $callback();
        }
    }
}
