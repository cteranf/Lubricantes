<?php

namespace App\Services;

use App\Exceptions\InvalidProductImage;
use App\Exceptions\ProductImageProcessingFailed;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductImageProcessor
{
    private const MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function validateUpload(UploadedFile $file): array
    {
        if (! $file->isValid() || ! $file->getRealPath()) {
            throw new InvalidProductImage;
        }

        $path = $file->getRealPath();
        $size = @filesize($path);
        if (! $size || $size > (int) config('product_images.max_bytes')) {
            throw new InvalidProductImage;
        }
        $extension = strtolower($file->getClientOriginalExtension());
        $expectedMime = self::MIME_BY_EXTENSION[$extension] ?? null;
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = @$finfo->file($path);
        $image = @getimagesize($path);

        if (! $expectedMime || $detectedMime !== $expectedMime
            || ! is_array($image) || ($image['mime'] ?? null) !== $expectedMime
            || ! $this->hasValidSignature($path, $expectedMime)) {
            throw new InvalidProductImage;
        }

        $width = (int) $image[0];
        $height = (int) $image[1];
        $this->assertDimensions($width, $height);

        if (($expectedMime === 'image/png' && $this->isAnimatedPng($path))
            || ($expectedMime === 'image/webp' && $this->isAnimatedWebp($path))) {
            throw new InvalidProductImage;
        }

        return ['mime' => $expectedMime, 'width' => $width, 'height' => $height];
    }

    public function validatePrivateMaster(string $path): array
    {
        $disk = $this->privateDisk();
        $allowed = [trim((string) config('product_images.original_prefix'), '/').'/', trim((string) config('product_images.temporary_prefix'), '/').'/'];
        if (! collect($allowed)->contains(fn ($prefix) => str_starts_with($path, $prefix)) || ! $disk->exists($path)) {
            throw new ProductImageProcessingFailed;
        }

        $absolute = $disk->path($path);
        $image = @getimagesize($absolute);
        if (! is_array($image) || ($image['mime'] ?? null) !== 'image/webp' || ! $this->hasValidSignature($absolute, 'image/webp')) {
            throw new ProductImageProcessingFailed;
        }

        try {
            $this->assertDimensions((int) $image[0], (int) $image[1]);
        } catch (InvalidProductImage) {
            throw new ProductImageProcessingFailed;
        }

        return ['width' => (int) $image[0], 'height' => (int) $image[1]];
    }

    public function storePrivateOriginal(UploadedFile $file, int $productId, string $generationId): array
    {
        $this->privateDisk();
        $metadata = $this->validateUpload($file);
        $extension = match ($metadata['mime']) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'
        };
        $relativePath = trim((string) config('product_images.original_prefix'), '/').'/incoming/'.$generationId.'.'.$extension;
        if (! $file->storeAs(dirname($relativePath), basename($relativePath), (string) config('product_images.original_disk'))) {
            throw new ProductImageProcessingFailed;
        }

        return ['path' => $relativePath, 'width' => $metadata['width'], 'height' => $metadata['height']];
    }

    public function sanitizePrivateUpload(string $path, int $productId, string $generationId): array
    {
        $disk = $this->privateDisk();
        if (! str_starts_with($path, trim((string) config('product_images.original_prefix'), '/').'/incoming/') || ! $disk->exists($path)) {
            throw new ProductImageProcessingFailed;
        }
        $absolute = $disk->path($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolute);
        $imageInfo = @getimagesize($absolute);
        if (! is_array($imageInfo) || ! in_array($mime, self::MIME_BY_EXTENSION, true)
            || ($imageInfo['mime'] ?? null) !== $mime || ! $this->hasValidSignature($absolute, $mime)) {
            throw new ProductImageProcessingFailed;
        }
        try {
            $this->assertDimensions((int) $imageInfo[0], (int) $imageInfo[1]);
        } catch (InvalidProductImage) {
            throw new ProductImageProcessingFailed;
        }
        if (($mime === 'image/png' && $this->isAnimatedPng($absolute)) || ($mime === 'image/webp' && $this->isAnimatedWebp($absolute))) {
            throw new ProductImageProcessingFailed;
        }

        $image = $this->decodeUploadedImage($absolute, $mime);
        $image = $this->applyExifOrientation($image, $absolute, $mime);
        $width = imagesx($image);
        $height = imagesy($image);
        $stagePath = trim((string) config('product_images.temporary_prefix'), '/').'/'.$productId.'/'.$generationId.'/master.webp';
        $absoluteStagePath = $disk->path($stagePath);
        $directory = dirname($absoluteStagePath);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            imagedestroy($image);
            throw new ProductImageProcessingFailed;
        }
        try {
            if (! @imagewebp($image, $absoluteStagePath, (int) config('product_images.master_quality'))) {
                throw new ProductImageProcessingFailed;
            }
        } finally {
            imagedestroy($image);
        }
        $verified = @getimagesize($absoluteStagePath);
        if (! is_array($verified) || ($verified['mime'] ?? null) !== 'image/webp' || (int) $verified[0] !== $width || (int) $verified[1] !== $height) {
            @unlink($absoluteStagePath);
            throw new ProductImageProcessingFailed;
        }

        return ['path' => $stagePath, 'width' => $width, 'height' => $height];
    }

    public function generateVariants(string $originalPath, int $productId, string $generationId): array
    {
        $disk = Storage::disk((string) config('product_images.original_disk'));
        $masterPath = $disk->path($originalPath);
        $dimensions = $this->validatePrivateMaster($originalPath);
        $source = @imagecreatefromwebp($masterPath);
        if (! $source) {
            throw new ProductImageProcessingFailed;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $targets = [];
        foreach ((array) config('product_images.variants') as $candidate) {
            $candidate = (int) $candidate;
            if ($candidate <= $sourceWidth && ($candidate !== 1600 || config('product_images.zoom_enabled'))) {
                $targets[$candidate] = $candidate === 160 ? 'thumbnail' : ($candidate === 360 ? 'card' : ($candidate === 720 ? 'medium' : ($candidate === 1200 ? 'detail' : 'zoom')));
            }
        }

        if ($sourceWidth > 720 && $sourceWidth < 1600 && ! isset($targets[$sourceWidth])) {
            $targets[$sourceWidth] = 'detail_source';
        }
        ksort($targets, SORT_NUMERIC);

        if (! isset($targets[160]) && $sourceWidth >= 160) {
            $targets[160] = 'thumbnail';
        }

        $result = [];
        $stagingPrefix = trim((string) config('product_images.temporary_prefix'), '/').'/'.$productId.'/'.$generationId;

        try {
            foreach ($targets as $targetWidth => $key) {
                $targetHeight = max(1, (int) floor($sourceHeight * ($targetWidth / $sourceWidth)));
                $variant = imagecreatetruecolor($targetWidth, $targetHeight);
                imagealphablending($variant, false);
                imagesavealpha($variant, true);
                if (! imagecopyresampled($variant, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight)) {
                    imagedestroy($variant);
                    throw new ProductImageProcessingFailed;
                }

                if (in_array($key, ['detail', 'detail_source', 'zoom'], true)) {
                    $this->applyWatermark($variant);
                }

                $stagedPath = $stagingPrefix.'/'.$key.'.webp';
                $absoluteStagePath = Storage::disk((string) config('product_images.original_disk'))->path($stagedPath);
                $directory = dirname($absoluteStagePath);
                if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                    imagedestroy($variant);
                    throw new ProductImageProcessingFailed;
                }
                if (! @imagewebp($variant, $absoluteStagePath, (int) config('product_images.quality'))) {
                    imagedestroy($variant);
                    throw new ProductImageProcessingFailed;
                }
                imagedestroy($variant);

                $verified = @getimagesize($absoluteStagePath);
                if (! is_array($verified) || ($verified['mime'] ?? null) !== 'image/webp'
                    || (int) $verified[0] !== $targetWidth || (int) $verified[1] !== $targetHeight) {
                    throw new ProductImageProcessingFailed;
                }

                $result[$key] = [
                    'stage_path' => $stagedPath,
                    'width' => $targetWidth,
                    'height' => $targetHeight,
                ];
            }
        } catch (Throwable) {
            imagedestroy($source);
            $this->deleteStaged($result, $stagingPrefix);
            throw new ProductImageProcessingFailed;
        }

        imagedestroy($source);

        return $result;
    }

    public function deleteStaged(array $variants, ?string $prefix = null): void
    {
        $disk = Storage::disk((string) config('product_images.original_disk'));
        foreach ($variants as $variant) {
            $path = $variant['stage_path'] ?? null;
            if (is_string($path) && str_starts_with($path, trim((string) config('product_images.temporary_prefix'), '/').'/')) {
                $disk->delete($path);
            }
        }
        if ($prefix && str_starts_with($prefix, trim((string) config('product_images.temporary_prefix'), '/').'/')) {
            $disk->deleteDirectory($prefix);
        }
    }

    private function assertDimensions(int $width, int $height): void
    {
        if ($width < (int) config('product_images.min_width') || $height < (int) config('product_images.min_height')
            || $width > (int) config('product_images.max_width') || $height > (int) config('product_images.max_height')
            || ($width * $height) > ((int) config('product_images.max_megapixels') * 1000000)) {
            throw new InvalidProductImage;
        }
    }

    private function hasValidSignature(string $path, string $mime): bool
    {
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            return false;
        }
        $header = fread($handle, 12);
        fclose($handle);

        return match ($mime) {
            'image/jpeg' => str_starts_with($header, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($header, "\x89PNG\r\n\x1A\n"),
            'image/webp' => strlen($header) >= 12 && substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP',
            default => false,
        };
    }

    private function isAnimatedPng(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            return true;
        }
        fseek($handle, 8);
        $animated = false;
        while (! feof($handle)) {
            $header = fread($handle, 8);
            if (strlen($header) !== 8) {
                break;
            }
            $length = unpack('Nlength', substr($header, 0, 4))['length'];
            $type = substr($header, 4, 4);
            if ($type === 'acTL') {
                $animated = true;
                break;
            }
            if ($type === 'IEND' || $length > 10 * 1024 * 1024 || fseek($handle, $length + 4, SEEK_CUR) !== 0) {
                break;
            }
        }
        fclose($handle);

        return $animated;
    }

    private function isAnimatedWebp(string $path): bool
    {
        $bytes = @file_get_contents($path);
        if (! is_string($bytes) || strlen($bytes) < 12) {
            return true;
        }
        $offset = 12;
        $length = strlen($bytes);
        while ($offset + 8 <= $length) {
            $type = substr($bytes, $offset, 4);
            $chunkLength = unpack('Vlength', substr($bytes, $offset + 4, 4))['length'];
            if (in_array($type, ['ANIM', 'ANMF'], true) || $chunkLength > $length - $offset - 8) {
                return true;
            }
            $offset += 8 + $chunkLength + ($chunkLength % 2);
        }

        return false;
    }

    private function decodeUploadedImage(string $path, string $mime)
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        } ?: throw new InvalidProductImage;
    }

    private function applyExifOrientation($image, string $path, string $mime)
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }
        $metadata = @exif_read_data($path);
        $orientation = (int) ($metadata['Orientation'] ?? 1);

        if ($orientation === 2) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 3 || $orientation === 4) {
            if ($orientation === 4) {
                imageflip($image, IMG_FLIP_VERTICAL);
            } else {
                $image = $this->rotate($image, 180);
            }
        } elseif ($orientation === 5 || $orientation === 6) {
            $image = $this->rotate($image, -90);
            if ($orientation === 5) {
                imageflip($image, IMG_FLIP_HORIZONTAL);
            }
        } elseif ($orientation === 7 || $orientation === 8) {
            $image = $this->rotate($image, 90);
            if ($orientation === 7) {
                imageflip($image, IMG_FLIP_HORIZONTAL);
            }
        }

        return $image;
    }

    private function rotate($image, int $angle)
    {
        $rotated = @imagerotate($image, $angle, 0);
        if ($rotated) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    private function applyWatermark($image): void
    {
        $config = (array) config('product_images.watermark');
        $text = $this->watermarkText($config);
        $font = (string) ($config['font'] ?? '');
        if ($text === '' || ! is_file($font) || ! function_exists('imagettftext') || ! function_exists('imagettfbbox')) {
            throw new ProductImageProcessingFailed;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $maxTextWidth = max(1, (int) floor($width * ((float) ($config['max_width_percent'] ?? 14) / 100)));
        $fontSize = max(8, (int) floor($width * 0.04));
        $box = imagettfbbox($fontSize, 0, $font, $text);
        while ($fontSize > 1 && $this->boxWidth($box) > $maxTextWidth) {
            $fontSize--;
            $box = imagettfbbox($fontSize, 0, $font, $text);
        }
        if ($this->boxWidth($box) > $maxTextWidth) {
            while ($text !== '' && $this->boxWidth($box) > $maxTextWidth) {
                $text = mb_substr($text, 0, max(0, mb_strlen($text, 'UTF-8') - 1), 'UTF-8');
                $box = imagettfbbox($fontSize, 0, $font, $text ?: ' ');
            }
            if ($text === '') {
                throw new ProductImageProcessingFailed;
            }
        }

        $margin = max(1, (int) floor(min($width, $height) * ((float) ($config['margin_percent'] ?? 3) / 100)));
        $textWidth = $this->boxWidth($box);
        $x = max($margin, $width - $margin - $textWidth);
        $y = max($margin + 8, $height - $margin - max($box[1], $box[3]));
        $opacity = min(100, max(0, (int) ($config['opacity'] ?? 20)));
        $alpha = (int) round(127 * (1 - ($opacity / 100)));
        $shadow = imagecolorallocatealpha($image, 0, 0, 0, min(127, $alpha + 18));
        $white = imagecolorallocatealpha($image, 255, 255, 255, $alpha);
        imagettftext($image, $fontSize, 0, $x + 1, $y + 1, $shadow, $font, $text);
        imagettftext($image, $fontSize, 0, $x, $y, $white, $font, $text);
    }

    private function watermarkText(array $config): string
    {
        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($config['text'] ?? '')) ?? '');
        if ($text === '') {
            $text = trim((string) config('app.name'));
        }
        if ($text === '') {
            $text = trim((string) ($config['fallback'] ?? 'LubriStore'));
        }
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return mb_substr($text, 0, (int) ($config['max_length'] ?? 48), 'UTF-8');
    }

    private function boxWidth(array $box): int
    {
        return (int) (max($box[0], $box[2], $box[4], $box[6]) - min($box[0], $box[2], $box[4], $box[6]));
    }

    private function privateDisk()
    {
        $originalName = (string) config('product_images.original_disk');
        $publicName = (string) config('product_images.public_disk');
        $original = config('filesystems.disks.'.$originalName, []);
        $public = config('filesystems.disks.'.$publicName, []);
        $originalRoot = is_string($original['root'] ?? null) ? (realpath($original['root']) ?: $original['root']) : '';
        $publicRoot = is_string($public['root'] ?? null) ? (realpath($public['root']) ?: $public['root']) : '';
        $sameLocalRoot = ($original['driver'] ?? null) === 'local'
            && ($public['driver'] ?? null) === 'local'
            && rtrim(str_replace('\\', '/', $originalRoot), '/') === rtrim(str_replace('\\', '/', $publicRoot), '/');

        if ($originalName === '' || $originalName === $publicName || $sameLocalRoot) {
            throw new ProductImageProcessingFailed;
        }

        return Storage::disk($originalName);
    }
}
