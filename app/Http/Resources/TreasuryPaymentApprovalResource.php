<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TreasuryPaymentApprovalResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'message' => 'Pago por transferencia aprobado.',
            'submission' => [
                'id' => $this->id,
                'status' => $this->status,
                'channel' => $this->channel,
                'amount' => (string) $this->expected_amount,
                'currency' => $this->currency,
                'reviewed_at' => $this->reviewed_at?->toIso8601String(),
                'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? ['id' => $this->reviewer->id, 'name' => $this->reviewer->name] : null),
            ],
            'transaction' => $this->whenLoaded('paymentTransaction', fn () => [
                'id' => $this->paymentTransaction->id,
                'status' => $this->paymentTransaction->status,
                'confirmed_at' => $this->paymentTransaction->confirmed_at?->toIso8601String(),
                'confirmed_by' => $this->paymentTransaction->confirmed_by,
            ]),
            'order' => $this->whenLoaded('order', fn () => [
                'number' => $this->order->id,
                'payment_status' => $this->order->payment_status,
                'reservation_status' => $this->order->reservations->pluck('status')->unique()->values()->all(),
            ]),
        ];
    }
}
