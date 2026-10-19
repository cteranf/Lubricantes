<?php

namespace App\Exceptions;

/** A bounded read could not be completed. No internal evidence leaves the domain. */
final class PaymentRefundReadConflictException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Refund read unavailable.');
    }
}
