<?php

namespace App\Services;

use App\Exceptions\ProductImageOriginalUnavailable;
use App\Exceptions\ProductImageProcessingFailed;
use App\Jobs\ProcessProductImage;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductImageProcessingService
{
    public function __construct(private ProductImageProcessor $processor) {}

    public function attachUploads(Product $product, array $files): array
    {
        $storedPaths = [];
        try {
            $images = DB::transaction(function () use ($product, $files, &$storedPaths) {
                $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $current = ProductImage::query()->where('product_id', $lockedProduct->id)->lockForUpdate()->get();
                if ($current->count() + count($files) > (int) config('product_images.max_images', 6)) {
                    throw ValidationException::withMessages(['images' => 'Un producto admite hasta seis fotografías.']);
                }
                $hasPrimary = $current->contains(fn (ProductImage $image) => $image->is_primary);
                $nextOrder = (int) ($current->max('sort_order') ?? -1) + 1;
                $created = [];

                foreach ($files as $index => $file) {
                    if (! $file instanceof UploadedFile) {
                        continue;
                    }
                    $generationId = (string) Str::uuid();
                    $stored = $this->processor->storePrivateOriginal($file, (int) $lockedProduct->id, $generationId);
                    $storedPaths[] = $stored['path'];
                    $image = ProductImage::query()->create([
                        'product_id' => $lockedProduct->id,
                        'image_path' => null,
                        'sort_order' => $nextOrder + $index,
                        'is_primary' => ! $hasPrimary && $index === 0,
                        'processing_status' => 'pending',
                        'generation_id' => $generationId,
                        'pending_original_path' => $stored['path'],
                        'source_width' => $stored['width'],
                        'source_height' => $stored['height'],
                    ]);
                    $created[] = ['image_id' => (int) $image->id, 'generation_id' => $generationId, 'operation' => 'upload'];
                }

                return $created;
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                $this->deletePrivate($path);
            }
            throw $exception;
        }

        return $this->dispatchCreated($images);
    }

    public function replace(Product $product, ProductImage $image, UploadedFile $file): array
    {
        $generationId = (string) Str::uuid();
        $stored = null;
        $oldPending = null;
        try {
            $result = DB::transaction(function () use ($product, $image, $file, $generationId, &$stored, &$oldPending) {
                Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                $lockedImage = ProductImage::query()->where('product_id', $product->id)->whereKey($image->id)->lockForUpdate()->firstOrFail();
                $stored = $this->processor->storePrivateOriginal($file, (int) $product->id, $generationId);
                $oldPending = $lockedImage->safePendingOriginalPath();
                $lockedImage->forceFill([
                    'generation_id' => $generationId,
                    'pending_original_path' => $stored['path'],
                    'source_width' => $stored['width'],
                    'source_height' => $stored['height'],
                    'processing_status' => 'pending',
                ])->save();

                return ['image_id' => (int) $lockedImage->id, 'generation_id' => $generationId, 'operation' => 'replace'];
            });
        } catch (Throwable $exception) {
            if (is_array($stored)) {
                $this->deletePrivate($stored['path']);
            }
            throw $exception;
        }

        if (is_string($oldPending) && $oldPending !== ($stored['path'] ?? null)) {
            $this->deletePrivate($oldPending);
        }

        return $this->dispatchCreated([$result])[0];
    }

    public function requestRegeneration(Product $product, ProductImage $image): array
    {
        $requested = DB::transaction(function () use ($product, $image) {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $lockedImage = ProductImage::query()->where('product_id', $product->id)->whereKey($image->id)->lockForUpdate()->firstOrFail();
            if (in_array($lockedImage->processing_status, ['pending', 'processing'], true)) {
                return ['image_id' => (int) $lockedImage->id, 'generation_id' => null, 'status' => $lockedImage->processing_status];
            }

            $path = $lockedImage->safeOriginalPath();
            if ($path === null || ! $lockedImage->canRegenerate()) {
                throw new ProductImageOriginalUnavailable;
            }
            try {
                $this->processor->validatePrivateMaster($path);
            } catch (ProductImageProcessingFailed) {
                throw new ProductImageOriginalUnavailable;
            }

            $generationId = (string) Str::uuid();
            $lockedImage->forceFill(['generation_id' => $generationId, 'pending_original_path' => null, 'processing_status' => 'pending'])->save();

            return ['image_id' => (int) $lockedImage->id, 'generation_id' => $generationId, 'status' => 'pending'];
        });

        if ($requested['generation_id'] === null) {
            return ['status' => $requested['status'], 'queued' => false];
        }
        $queued = $this->dispatch((int) $requested['image_id'], $requested['generation_id'], 'regenerate');

        return ['status' => $queued ? 'pending' : 'failed', 'queued' => $queued];
    }

    public function process(int $productImageId, string $generationId, string $operation): void
    {
        if (! in_array($operation, ['upload', 'replace', 'regenerate'], true)) {
            return;
        }

        $work = DB::transaction(function () use ($productImageId, $generationId, $operation) {
            $image = ProductImage::query()->whereKey($productImageId)->lockForUpdate()->first();
            if (! $image || ! Product::query()->whereKey($image->product_id)->lockForUpdate()->exists()
                || $image->generation_id !== $generationId || ! in_array($image->processing_status, ['pending', 'processing'], true)) {
                return null;
            }
            $sourcePath = in_array($operation, ['upload', 'replace'], true) ? $image->safePendingOriginalPath() : $image->safeOriginalPath();
            if ($sourcePath === null) {
                throw new ProductImageProcessingFailed(operation: $operation, phase: 'load-original');
            }
            $image->forceFill(['processing_status' => 'processing'])->save();

            return ['product_id' => (int) $image->product_id, 'source_path' => $sourcePath, 'old_files' => $image->collectCleanupPaths()];
        });

        if ($work === null) {
            return;
        }

        $productId = $work['product_id'];
        $stagePrefix = trim((string) config('product_images.temporary_prefix'), '/').'/'.$productId.'/'.$generationId;
        $publicVariants = [];
        $newOriginalPath = null;
        $phase = 'sanitize-original';

        try {
            $masterPath = in_array($operation, ['upload', 'replace'], true)
                ? $this->processor->sanitizePrivateUpload($work['source_path'], $productId, $generationId)['path']
                : $work['source_path'];
            $source = $this->processor->validatePrivateMaster($masterPath);
            $phase = 'generate-variants';
            $staged = $this->processor->generateVariants($masterPath, $productId, $generationId);
            $publicPrefix = trim((string) config('product_images.variant_prefix'), '/').'/'.$productId.'/'.$generationId;

            foreach ($staged as $key => $variant) {
                $publicPath = $publicPrefix.'/'.$key.'.webp';
                $stream = Storage::disk((string) config('product_images.original_disk'))->readStream($variant['stage_path']);
                if (! is_resource($stream)) {
                    throw new ProductImageProcessingFailed(operation: $operation, phase: 'read-variant');
                }
                try {
                    $written = Storage::disk((string) config('product_images.public_disk'))->put($publicPath, $stream, 'public');
                } finally {
                    fclose($stream);
                }
                if (! $written) {
                    throw new ProductImageProcessingFailed(operation: $operation, phase: 'publish-variant');
                }
                $dimensions = $this->verifyPublishedVariant($publicPath, (int) $variant['width'], (int) $variant['height']);
                $publicVariants[$key] = ['path' => $publicPath, 'width' => (int) $dimensions[0], 'height' => (int) $dimensions[1]];
            }

            $bestKey = array_key_last($publicVariants);
            $best = $bestKey === null ? null : $publicVariants[$bestKey];
            if ($best === null) {
                throw new ProductImageProcessingFailed(operation: $operation, phase: 'select-primary-variant');
            }

            if ($operation !== 'regenerate') {
                $newOriginalPath = trim((string) config('product_images.original_prefix'), '/').'/'.$productId.'/'.$productImageId.'/'.$generationId.'.webp';
                $stream = Storage::disk((string) config('product_images.original_disk'))->readStream($masterPath);
                if (! is_resource($stream)) {
                    throw new ProductImageProcessingFailed(operation: $operation, phase: 'read-original');
                }
                try {
                    if (! Storage::disk((string) config('product_images.original_disk'))->put($newOriginalPath, $stream)) {
                        throw new ProductImageProcessingFailed(operation: $operation, phase: 'store-original');
                    }
                } finally {
                    fclose($stream);
                }
            } else {
                $newOriginalPath = $work['source_path'];
            }

            $cleanup = DB::transaction(function () use ($productImageId, $generationId, $operation, $newOriginalPath, $publicVariants, $best, $source) {
                $image = ProductImage::query()->whereKey($productImageId)->lockForUpdate()->first();
                if (! $image || ! Product::query()->whereKey($image->product_id)->lockForUpdate()->exists()
                    || $image->generation_id !== $generationId || $image->processing_status !== 'processing') {
                    return null;
                }
                $previous = $image->collectCleanupPaths();
                if ($operation === 'regenerate') {
                    $previous = array_values(array_filter($previous, fn ($file) => $file['disk'] !== config('product_images.original_disk') || $file['path'] !== $newOriginalPath));
                }
                $image->forceFill([
                    'image_path' => '/storage/'.$best['path'],
                    'variants' => $publicVariants,
                    'original_path' => $newOriginalPath,
                    'pending_original_path' => null,
                    'source_width' => (int) $source['width'],
                    'source_height' => (int) $source['height'],
                    'processing_status' => 'ready',
                    'generation_id' => null,
                    'processed_at' => now(),
                    'is_primary' => $image->is_primary || ! ProductImage::query()->where('product_id', $image->product_id)->where('is_primary', true)->exists(),
                ])->save();

                return $previous;
            });

            if ($cleanup === null) {
                $this->deletePaths(array_map(fn ($variant) => ['disk' => config('product_images.public_disk'), 'path' => $variant['path']], $publicVariants));
                if ($operation !== 'regenerate' && $newOriginalPath !== null) {
                    $this->deletePrivate($newOriginalPath);
                }

                return;
            }
            $this->deletePaths($cleanup);
        } catch (Throwable $exception) {
            $this->deletePaths(array_map(fn ($variant) => ['disk' => config('product_images.public_disk'), 'path' => $variant['path']], $publicVariants));
            if ($operation !== 'regenerate' && $newOriginalPath !== null) {
                $this->deletePrivate($newOriginalPath);
            }
            throw new ProductImageProcessingFailed(previous: $exception, operation: $operation, phase: $phase);
        } finally {
            try {
                $this->processor->deleteStaged([], $stagePrefix);
                if (in_array($operation, ['upload', 'replace'], true)) {
                    $this->processor->deleteStaged([], trim((string) config('product_images.temporary_prefix'), '/').'/'.$productId.'/'.$generationId);
                }
            } catch (Throwable) {
                // Temporary cleanup is deliberately best effort.
            }
        }
    }

    public function markFailed(int $productImageId, string $generationId, string $operation): void
    {
        $pending = DB::transaction(function () use ($productImageId, $generationId, $operation) {
            $image = ProductImage::query()->whereKey($productImageId)->lockForUpdate()->first();
            if (! $image || $image->generation_id !== $generationId) {
                return null;
            }
            $path = in_array($operation, ['upload', 'replace'], true) ? $image->safePendingOriginalPath() : null;
            $image->forceFill([
                'pending_original_path' => null,
                'generation_id' => null,
                'processing_status' => 'failed',
            ])->save();

            if ($image->is_primary && ! $image->hasPublishedImage()) {
                ProductImage::query()->where('product_id', $image->product_id)->where('is_primary', true)->update(['is_primary' => false]);
                $next = ProductImage::query()->where('product_id', $image->product_id)->whereNotNull('image_path')->orderBy('sort_order')->orderBy('id')->lockForUpdate()->first();
                if ($next) {
                    $next->forceFill(['is_primary' => true])->save();
                }
            }

            return $path;
        });
        if (is_string($pending)) {
            $this->deletePrivate($pending);
        }
    }

    public function status(ProductImage $image): array
    {
        $status = in_array($image->processing_status, ['pending', 'processing', 'ready', 'failed'], true) ? $image->processing_status : 'failed';

        return [
            'id' => (int) $image->id,
            'status' => $status,
            'can_regenerate' => $image->canRegenerate() && ! in_array($status, ['pending', 'processing'], true),
            'url' => $image->publicUrl(),
            'variants' => $image->publicVariants(),
            'srcset' => $image->publicSrcset(),
            'width' => $image->publicDimensions()['width'] ?? null,
            'height' => $image->publicDimensions()['height'] ?? null,
        ];
    }

    public function setPrimary(Product $product, ProductImage $image): void
    {
        DB::transaction(function () use ($product, $image) {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $target = ProductImage::query()->where('product_id', $product->id)->whereKey($image->id)->lockForUpdate()->firstOrFail();
            if (! $target->hasPublishedImage()) {
                throw ValidationException::withMessages(['image' => 'Solo se puede elegir una fotografía publicada como principal.']);
            }
            ProductImage::query()->where('product_id', $product->id)->update(['is_primary' => false]);
            $target->forceFill(['is_primary' => true])->save();
        });
    }

    public function reorder(Product $product, array $imageIds): void
    {
        DB::transaction(function () use ($product, $imageIds) {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $images = ProductImage::query()->where('product_id', $product->id)->lockForUpdate()->get();
            $ids = array_map('intval', $imageIds);
            $existing = $images->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (count($ids) !== count(array_unique($ids)) || count($ids) !== count($existing) || array_diff($ids, $existing) !== [] || array_diff($existing, $ids) !== []) {
                throw ValidationException::withMessages(['image_ids' => 'El orden debe incluir exactamente las imágenes del producto.']);
            }
            foreach ($ids as $order => $id) {
                ProductImage::query()->where('product_id', $product->id)->whereKey($id)->update(['sort_order' => $order]);
            }
        });
    }

    public function delete(Product $product, ProductImage $image): void
    {
        $paths = DB::transaction(function () use ($product, $image) {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $target = ProductImage::query()->where('product_id', $product->id)->whereKey($image->id)->lockForUpdate()->firstOrFail();
            $paths = $target->collectCleanupPaths();
            $wasPrimary = $target->is_primary;
            $target->delete();
            if ($wasPrimary) {
                $next = ProductImage::query()->where('product_id', $product->id)->whereNotNull('image_path')->orderBy('sort_order')->orderBy('id')->lockForUpdate()->first();
                if ($next) {
                    ProductImage::query()->where('product_id', $product->id)->update(['is_primary' => false]);
                    $next->forceFill(['is_primary' => true])->save();
                }
            }

            return $paths;
        });
        $this->deletePaths($paths);
    }

    public function addLegacyUpload(Product $product, UploadedFile $file): array
    {
        return $this->attachUploads($product, [$file])[0] ?? ['status' => 'failed', 'queued' => false];
    }

    private function dispatchCreated(array $jobs): array
    {
        foreach ($jobs as &$job) {
            $job['queued'] = $this->dispatch($job['image_id'], $job['generation_id'], $job['operation']);
            $job['status'] = $job['queued'] ? 'pending' : 'failed';
        }

        return $jobs;
    }

    private function dispatch(int $imageId, string $generationId, string $operation): bool
    {
        $connection = (string) config('product_images.queue_connection');
        $driver = config('queue.connections.'.$connection.'.driver');
        if ($connection === '' || ! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            $this->markFailed($imageId, $generationId, $operation);

            return false;
        }
        try {
            ProcessProductImage::dispatch($imageId, $generationId, $operation)->onConnection($connection)->onQueue((string) config('product_images.queue'));

            return true;
        } catch (Throwable) {
            $this->markFailed($imageId, $generationId, $operation);

            return false;
        }
    }

    private function deletePaths(array $paths): void
    {
        foreach ($paths as $file) {
            if (! is_array($file) || ! is_string($file['disk'] ?? null) || ! is_string($file['path'] ?? null)) {
                continue;
            }
            $disk = $file['disk'];
            $path = $file['path'];
            $prefix = $disk === (string) config('product_images.original_disk')
                ? trim((string) config('product_images.original_prefix'), '/').'/'
                : trim((string) config('product_images.variant_prefix'), '/').'/';
            if (str_starts_with($path, $prefix) && ! str_contains($path, '..') && ! str_contains($path, '\\')) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (Throwable) {
                    // Database references are authoritative after commit.
                }
            }
        }
    }

    private function deletePrivate(string $path): void
    {
        $this->deletePaths([['disk' => (string) config('product_images.original_disk'), 'path' => $path]]);
    }

    private function verifyPublishedVariant(string $path, int $expectedWidth, int $expectedHeight): array
    {
        $stream = Storage::disk((string) config('product_images.public_disk'))->readStream($path);
        $temporary = tempnam(sys_get_temp_dir(), 'product-variant-check-');
        if (! is_resource($stream) || $temporary === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_string($temporary)) {
                @unlink($temporary);
            }
            throw new ProductImageProcessingFailed;
        }
        $target = fopen($temporary, 'wb');
        if (! is_resource($target)) {
            fclose($stream);
            @unlink($temporary);
            throw new ProductImageProcessingFailed;
        }
        stream_copy_to_stream($stream, $target);
        fclose($stream);
        fclose($target);
        try {
            $image = @getimagesize($temporary);
            if (! is_array($image) || ($image['mime'] ?? null) !== 'image/webp' || (int) $image[0] !== $expectedWidth || (int) $image[1] !== $expectedHeight) {
                throw new ProductImageProcessingFailed;
            }

            return $image;
        } finally {
            @unlink($temporary);
        }
    }
}
