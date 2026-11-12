<?php

namespace App\Jobs;

use App\Services\ProductImageProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ProcessProductImage implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public int $productImageId,
        public string $generationId,
        public string $operation,
    ) {}

    public function __serialize(): array
    {
        return ['productImageId' => $this->productImageId, 'generationId' => $this->generationId, 'operation' => $this->operation];
    }

    public function __unserialize(array $data): void
    {
        $this->productImageId = (int) ($data['productImageId'] ?? 0);
        $this->generationId = (string) ($data['generationId'] ?? '');
        $this->operation = (string) ($data['operation'] ?? '');
    }

    public function __isset(string $name): bool
    {
        return in_array($name, ['tries', 'timeout', 'backoff', 'uniqueFor'], true);
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'tries' => (int) config('product_images.tries', 3),
            'timeout' => (int) config('product_images.timeout', 120),
            'backoff' => (array) config('product_images.backoff', [30, 120, 300]),
            'uniqueFor' => 900,
            default => null,
        };
    }

    public function uniqueId(): string
    {
        return $this->productImageId.':'.$this->generationId.':'.$this->operation;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('product-image-'.$this->productImageId))->expireAfter(300)->releaseAfter(15)];
    }

    public function handle(ProductImageProcessingService $images): void
    {
        $images->process($this->productImageId, $this->generationId, $this->operation);
    }

    public function failed(Throwable $exception): void
    {
        app(ProductImageProcessingService::class)->markFailed($this->productImageId, $this->generationId, $this->operation);
    }
}
