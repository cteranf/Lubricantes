<?php

namespace App\Services;

use App\Models\News;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class NewsImageService
{
    public function store(UploadedFile $file): string
    {
        $absolutePath = $file->getRealPath();
        $details = $absolutePath ? @getimagesize($absolutePath) : false;
        $mime = is_array($details) ? ($details['mime'] ?? null) : null;
        $width = is_array($details) ? (int) ($details[0] ?? 0) : 0;
        $height = is_array($details) ? (int) ($details[1] ?? 0) : 0;
        $maxMegapixels = (int) config('news_images.max_megapixels', 10);

        if (! $file->isValid()
            || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $file->getMimeType() !== $mime
            || $width < (int) config('news_images.min_width', 640)
            || $height < (int) config('news_images.min_height', 400)
            || $width > (int) config('news_images.max_width', 4000)
            || $height > (int) config('news_images.max_height', 2500)
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
                (int) config('news_images.output_width', 1600) / imagesx($source),
                (int) config('news_images.output_height', 1000) / imagesy($source)
            );
            $targetWidth = max(1, (int) round(imagesx($source) * $scale));
            $targetHeight = max(1, (int) round(imagesy($source) * $scale));
            $target = imagecreatetruecolor($targetWidth, $targetHeight);
            if ($mime === 'image/png' || $mime === 'image/webp') {
                imagealphablending($target, false);
                imagesavealpha($target, true);
                imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
            }
            if (! imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, imagesx($source), imagesy($source))) {
                throw new \RuntimeException('No se pudo procesar la imagen.');
            }

            $format = function_exists('imagewebp') ? 'webp' : match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                // Keep valid WebP bytes when this PHP build can decode but not encode WebP.
                'image/webp' => 'webp',
            };
            if ($format === 'webp' && ! function_exists('imagewebp')) {
                $bytes = file_get_contents($absolutePath);
            } else {
                ob_start();
                $encoded = match ($format) {
                    'webp' => imagewebp($target, null, (int) config('news_images.webp_quality', 82)),
                    'jpg' => imagejpeg($target, null, 86),
                    'png' => imagepng($target, null, 6),
                };
                $bytes = ob_get_clean();
                if (! $encoded) {
                    $bytes = false;
                }
            }

            if (! is_string($bytes) || $bytes === '') {
                throw new \RuntimeException('No se pudo procesar la imagen.');
            }

            $directory = trim((string) config('news_images.directory', 'news'), '/');
            $path = $directory.'/'.Str::uuid().'.'.$format;
            if (! Storage::disk((string) config('news_images.disk', 'public'))->put($path, $bytes)) {
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
        $disk = Storage::disk((string) config('news_images.disk', 'public'));

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

        $directory = trim((string) config('news_images.directory', 'news'), '/');
        if (! str_starts_with($path, $directory.'/')) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }

    public function update(News $news, array $attributes, ?UploadedFile $image, bool $removeImage): News
    {
        $newPath = $image ? $this->store($image) : null;
        $oldPath = null;
        $connection = DB::connection($news->getConnectionName());

        try {
            $connection->transaction(function () use ($news, $attributes, $newPath, $removeImage, &$oldPath): void {
                $locked = $news->newQuery()->whereKey($news->getKey())->lockForUpdate()->firstOrFail();
                if ($newPath !== null || $removeImage) {
                    $oldPath = $locked->image_path;
                    $attributes['image_path'] = $newPath;
                }
                $locked->fill($attributes)->save();
                $news->setRawAttributes($locked->getAttributes(), true);
            });
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                $this->deleteIfUnreferenced($newPath);
            }
            throw $exception;
        }

        if ($oldPath !== null && ($newPath !== null || $removeImage)) {
            $this->afterCommit($news, fn () => $this->deleteIfUnreferenced($oldPath));
        }

        return $news;
    }

    public function delete(News $news): void
    {
        $oldPath = null;
        $connection = DB::connection($news->getConnectionName());
        $connection->transaction(function () use ($news, &$oldPath): void {
            $locked = $news->newQuery()->whereKey($news->getKey())->lockForUpdate()->firstOrFail();
            $oldPath = $locked->image_path;
            $locked->delete();
        });

        if ($oldPath !== null) {
            $this->afterCommit($news, fn () => $this->deleteIfUnreferenced($oldPath));
        }
    }

    public function deleteIfUnreferenced(?string $storedPath): void
    {
        $path = $this->safePath($storedPath);
        if (! $path) {
            return;
        }

        try {
            $aliases = array_unique([$storedPath, $path, '/'.$path, 'storage/'.$path, '/storage/'.$path]);
            if (! News::query()->whereIn('image_path', $aliases)->exists()) {
                Storage::disk((string) config('news_images.disk', 'public'))->delete($path);
            }
        } catch (Throwable) {
            // A safe orphan is preferable to deleting a file still referenced by an article.
        }
    }

    private function afterCommit(News $news, callable $callback): void
    {
        $connection = DB::connection($news->getConnectionName());
        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($callback);
        } else {
            $callback();
        }
    }
}
