<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentRefundConflictException extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('La operación de reembolso no es compatible con el estado actual.', 0, $previous);
    }
}
