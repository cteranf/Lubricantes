<?php

namespace App\Http\Resources;

use App\Support\EffectiveInventoryReservationStatus;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminOrderListResource extends JsonResource
{
    public function toArray($request): array
    {
        $firstItem = $this->items->first();

        return [
            'id' => $this->id,
            'sales_channel' => $this->sales_channel ?? 'web',
            'created_at' => $this->created_at?->toIso8601String(),
            'customer_name' => $this->user?->name ?? 'Invitado',
            'total' => $this->total,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'status' => $this->status,
            'fulfillment_status' => $this->effectiveFulfillmentStatus(),
            'delivery_type' => $this->delivery_type,
            'pickup_branch_name_snapshot' => $this->pickup_branch_name_snapshot,
            'warehouse_name' => $firstItem?->warehouse?->name,
            'reservation_status' => EffectiveInventoryReservationStatus::forItem(
                $this->resource,
                $firstItem?->currentReservation,
                $this->paymentSubmission?->status
            ),
            'has_payment_submission' => (bool) $this->has_payment_submission,
            'payment_submission_status' => $this->paymentSubmission?->status,
        ];
    }
}
