<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class ProductImageProcessingFailed extends RuntimeException
{
    public string $operation;

    public string $phase;

    public function __construct(
        ?Throwable $previous = null,
        string $operation = 'unknown',
        string $phase = 'processing',
    ) {
        $this->operation = in_array($operation, ['upload', 'regenerate'], true) ? $operation : 'unknown';
        $this->phase = in_array($phase, [
            'sanitize-original', 'generate-variants', 'publish-variant', 'verify-variant',
            'write-private-master', 'persist-references', 'cleanup-old-files', 'processing',
        ], true) ? $phase : 'processing';

        parent::__construct('No fue posible procesar la imagen del producto.', 0, $previous);
    }
}
