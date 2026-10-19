<?php

namespace App\ValueObjects;

/** Contains no persisted attributes, identifiers or private financial text. */
final readonly class TreasuryPaymentRefundAvailability implements \JsonSerializable
{
    public function __construct(
        public readonly bool $can_mark_refund_pending = false,
        public readonly bool $can_complete_refund = false,
        public readonly bool $requires_manual_review = false,
    ) {
        if (($requires_manual_review && ($can_mark_refund_pending || $can_complete_refund))
            || ($can_mark_refund_pending && $can_complete_refund)) {
            throw new \InvalidArgumentException('Refund availability flags are mutually exclusive.');
        }
    }

    /** @return array{can_mark_refund_pending: bool, can_complete_refund: bool, requires_manual_review: bool} */
    public function toArray(): array
    {
        return [
            'can_mark_refund_pending' => $this->can_mark_refund_pending,
            'can_complete_refund' => $this->can_complete_refund,
            'requires_manual_review' => $this->requires_manual_review,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
