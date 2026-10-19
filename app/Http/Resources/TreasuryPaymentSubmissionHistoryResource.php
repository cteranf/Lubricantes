<?php

namespace App\Http\Resources;

use App\Models\PaymentSubmissionHistory;
use Illuminate\Http\Resources\Json\JsonResource;

class TreasuryPaymentSubmissionHistoryResource extends JsonResource
{
    private const EVENTS_WITH_PUBLIC_DETAILS = [
        'submitted', 'observed', 'corrected', 'approved', 'rejected', 'expired',
        'canceled', 'resolution_opened', PaymentSubmissionHistory::REASSIGNMENT_APPROVED,
    ];

    public function toArray($request): array
    {
        $publicDetails = in_array($this->event, self::EVENTS_WITH_PUBLIC_DETAILS, true);
        $metadata = $publicDetails ? ($this->safe_metadata ?: []) : [];

        return array_filter([
            'event' => $this->event,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'role' => $this->actor->role,
            ] : null),
            'reason' => $publicDetails ? $this->reason : null,
            'previous_expires_at' => data_get($metadata, 'previous_expires_at'),
            'new_expires_at' => data_get($metadata, 'new_expires_at'),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ], fn ($value) => $value !== null);
    }
}
