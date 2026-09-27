<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TreasuryPaymentSubmissionHistoryResource extends JsonResource
{
    public function toArray($request): array
    {
        $metadata = $this->safe_metadata ?: [];

        return array_filter([
            'event' => $this->event,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'role' => $this->actor->role,
            ] : null),
            'reason' => $this->reason,
            'previous_expires_at' => data_get($metadata, 'previous_expires_at'),
            'new_expires_at' => data_get($metadata, 'new_expires_at'),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ], fn ($value) => $value !== null);
    }
}
