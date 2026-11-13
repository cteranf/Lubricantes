<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class CategoryImageService
{
    public function store(UploadedFile $file): string
    {
        $details = @getimagesize($file->getRealPath());
        if (! is_array($details)) {
            throw new \InvalidArgumentException('La imagen no es válida.');
        }

        $mime = $details['mime'] ?? '';
        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file->getRealPath()),
            'image/png' => @imagecreatefrompng($file->getRealPath()),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file->getRealPath()) : false,
            default => false,
        };
        if (! $source) {
            throw new \InvalidArgumentException('No se pudo procesar la imagen.');
        }

        try {
            $width = imagesx($source);
            $height = imagesy($source);
            $maxWidth = (int) config('category_images.output_width', 1600);
            $maxHeight = (int) config('category_images.output_height', 1000);
            $scale = min(1, $maxWidth / $width, $maxHeight / $height);
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $image = imagecreatetruecolor($targetWidth, $targetHeight);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
            imagefill($image, 0, 0, $transparent);
            if (! imagecopyresampled($image, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
                imagedestroy($image);
                throw new \RuntimeException('No se pudo procesar la imagen.');
            }

            $extension = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            };
            $canEncodeWebp = function_exists('imagewebp');
            if ($canEncodeWebp) {
                $extension = 'webp';
            } elseif ($mime === 'image/webp') {
                imagedestroy($image);
                throw new \RuntimeException('No se pudo procesar la imagen.');
            }

            ob_start();
            $encoded = match ($extension) {
                'webp' => imagewebp($image, null, (int) config('category_images.webp_quality', 82)),
                'jpg' => imagejpeg($image, null, 86),
                'png' => imagepng($image, null, 6),
            };
            $bytes = ob_get_clean();
            imagedestroy($image);
            if (! $encoded || ! is_string($bytes) || $bytes === '') {
                throw new \RuntimeException('No se pudo procesar la imagen.');
            }

            $path = trim((string) config('category_images.directory', 'categories'), '/').'/'.Str::uuid().'.'.$extension;
            if (! Storage::disk((string) config('category_images.disk', 'public'))->put($path, $bytes)) {
                throw new \RuntimeException('No se pudo guardar la imagen.');
            }

            return $path;
        } finally {
            imagedestroy($source);
        }
    }

    public function publicUrl(?string $storedPath): ?string
    {
        $path = $this->safePath($storedPath);
        $disk = Storage::disk((string) config('category_images.disk', 'public'));

        return $path && $disk->exists($path) ? $disk->url($path) : null;
    }

    public function safePath(?string $storedPath): ?string
    {
        if (! is_string($storedPath) || $storedPath === '' || preg_match('/[\x00-\x1F%\\\\?#]/', $storedPath)) {
            return null;
        }

        if (Str::startsWith($storedPath, '/storage/')) {
            $path = substr($storedPath, 1);
        } elseif (str_starts_with($storedPath, '/')) {
            return null;
        } else {
            $path = $storedPath;
        }
        if (Str::startsWith($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        $directory = trim((string) config('category_images.directory', 'categories'), '/');
        if (! Str::startsWith($path, $directory.'/')) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }

    public function replace(Category $category, UploadedFile $file): void
    {
        $disk = Storage::disk((string) config('category_images.disk', 'public'));
        $newPath = $this->store($file);
        $oldPath = $category->image;
        $connection = DB::connection($category->getConnectionName());

        try {
            $connection->transaction(function () use ($category, $newPath): void {
                $locked = $category->newQuery()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();
                $locked->forceFill(['image' => $newPath])->save();
                $category->setRawAttributes($locked->getAttributes(), true);
            });
        } catch (Throwable $exception) {
            $disk->delete($newPath);
            throw $exception;
        }

        $this->afterCommit($category, fn () => $this->deleteIfUnreferenced($oldPath, $category));
    }

    public function remove(Category $category): void
    {
        $oldPath = $category->image;
        $connection = DB::connection($category->getConnectionName());
        $connection->transaction(function () use ($category): void {
            $locked = $category->newQuery()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill(['image' => null])->save();
            $category->setRawAttributes($locked->getAttributes(), true);
        });

        $this->afterCommit($category, fn () => $this->deleteIfUnreferenced($oldPath, $category));
    }

    public function deleteIfUnreferenced(?string $storedPath, Category $category): void
    {
        $path = $this->safePath($storedPath);
        if (! $path) {
            return;
        }

        try {
            $aliases = array_unique([$storedPath, $path, '/'.$path, 'storage/'.$path, '/storage/'.$path]);
            if (! $category->newQuery()->whereIn('image', $aliases)->exists()) {
                Storage::disk((string) config('category_images.disk', 'public'))->delete($path);
            }
        } catch (Throwable) {
            // A safe orphan is preferable to disrupting a committed category change.
        }
    }

    private function afterCommit(Category $category, callable $callback): void
    {
        $connection = DB::connection($category->getConnectionName());
        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($callback);
        } else {
            $callback();
        }
    }
}
