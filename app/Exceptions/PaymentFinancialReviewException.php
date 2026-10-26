<?php

namespace App\Exceptions;

final class PaymentFinancialReviewException extends \RuntimeException
{
    public const CATEGORY = 'financial_review_required';

    public const MANUAL_RECONCILIATION_REQUIRED = 'manual_reconciliation_required';

    public const UNSUPPORTED_REMOTE_STATUS = 'unsupported_remote_status';

    public readonly string $category;

    public function __construct(string $category = self::CATEGORY)
    {
        if (! in_array($category, [self::CATEGORY, self::MANUAL_RECONCILIATION_REQUIRED, self::UNSUPPORTED_REMOTE_STATUS], true)) {
            $category = self::CATEGORY;
        }
        $this->category = $category;
        parent::__construct($category);
    }
}
