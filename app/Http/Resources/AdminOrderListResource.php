<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AdminOrderListResource extends JsonResource
{
    public function toArray($request): array
    {
        $firstItem = $this->items->first();

        return [
            'id' => $this->id,
            'created_at' => $this->created_at?->toIso8601String(),
            'customer_name' => $this->user?->name ?? 'Invitado',
            'total' => $this->total,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'status' => $this->status,
            'fulfillment_status' => $this->fulfillment_status,
            'delivery_type' => $this->delivery_type,
            'pickup_branch_name_snapshot' => $this->pickup_branch_name_snapshot,
            'warehouse_name' => $firstItem?->warehouse?->name,
            'reservation_status' => $firstItem?->currentReservation?->status,
            'has_payment_submission' => (bool) $this->has_payment_submission,
            'payment_submission_status' => $this->paymentSubmission?->status,
        ];
    }
}
