<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentRefundConflictException extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('El reembolso pendiente no puede registrarse porque el estado es incompatible.', 0, $previous);
    }
}
