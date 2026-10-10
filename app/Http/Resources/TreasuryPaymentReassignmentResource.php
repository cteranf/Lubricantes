<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TreasuryPaymentReassignmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'message' => 'El pago fue aprobado y el stock fue reasignado.',
            'resolution' => [
                'id' => $this->id,
                'payment_submission_id' => $this->payment_submission_id,
                'order_id' => $this->whenLoaded('submission', fn () => $this->submission?->order_id),
                'type' => $this->type,
                'status' => $this->status,
                'amount' => (string) $this->amount,
                'currency' => $this->currency,
                'resolved_at' => $this->resolved_at?->toIso8601String(),
            ],
        ];
    }
}
