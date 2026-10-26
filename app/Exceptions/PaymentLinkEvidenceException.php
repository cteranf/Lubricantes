<?php

namespace App\Exceptions;

final class PaymentLinkEvidenceException extends \RuntimeException
{
    public const REMOTE_RESOURCE_ABSENT = 'remote_resource_absent';

    public const REMOTE_EVIDENCE_INCOMPLETE = 'remote_evidence_incomplete';

    public const CONTRADICTORY_EVIDENCE = 'contradictory_evidence';

    public const STALE_LOCAL_LINK = 'stale_local_link';

    public const UNKNOWN_CATEGORY = 'payment_link_evidence_error';

    private const ALLOWED_CATEGORIES = [
        self::REMOTE_RESOURCE_ABSENT,
        self::REMOTE_EVIDENCE_INCOMPLETE,
        self::CONTRADICTORY_EVIDENCE,
        self::STALE_LOCAL_LINK,
        self::UNKNOWN_CATEGORY,
    ];

    public readonly string $category;

    public function __construct(string $category, ?\Throwable $previous = null)
    {
        $this->category = in_array($category, self::ALLOWED_CATEGORIES, true)
            ? $category
            : self::UNKNOWN_CATEGORY;

        parent::__construct($this->category, 0, $previous);
    }

    public function __serialize(): array
    {
        return ['category' => $this->category];
    }

    public function __unserialize(array $data): void
    {
        $category = $data['category'] ?? self::UNKNOWN_CATEGORY;
        $this->category = is_string($category) && in_array($category, self::ALLOWED_CATEGORIES, true)
            ? $category
            : self::UNKNOWN_CATEGORY;

        parent::__construct($this->category);
    }
}
