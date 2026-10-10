<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain-level inconsistency in a versioned reservation primitive.
 *
 * Transport translation belongs to the future resolution orchestrator, not to
 * InventoryService.
 */
class InventoryReservationConflictException extends RuntimeException {}
