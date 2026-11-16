<?php

namespace App\Exceptions;

use RuntimeException;

class InventoryReceiptConflictException extends RuntimeException
{
    public static function invalid(string $message): self
    {
        return new self($message, 422);
    }
}
