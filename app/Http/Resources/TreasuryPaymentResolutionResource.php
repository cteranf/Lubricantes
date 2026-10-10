<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TreasuryPaymentResolutionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'message' => 'El pago fue recibido y requiere una resolución manual.',
            'resolution' => [
                'id' => $this->id,
                'status' => $this->status,
                'type' => $this->type,
                'amount' => (string) $this->amount,
                'currency' => $this->currency,
                'funds_received_at' => $this->funds_received_at?->toIso8601String(),
                'requested_at' => $this->requested_at?->toIso8601String(),
            ],
            'order_number' => $this->whenLoaded('submission', fn () => $this->submission?->order_id),
            'submission' => $this->whenLoaded('submission', fn () => [
                'id' => $this->submission->id,
                'status' => $this->submission->status,
            ]),
            'transaction' => $this->whenLoaded('receivedPaymentTransaction', fn () => [
                'id' => $this->receivedPaymentTransaction->id,
                'status' => $this->receivedPaymentTransaction->status,
                'confirmed_at' => $this->receivedPaymentTransaction->confirmed_at?->toIso8601String(),
            ]),
        ];
    }
}
