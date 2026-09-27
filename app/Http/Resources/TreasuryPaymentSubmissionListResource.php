<?php

namespace App\Http\Resources;

use App\Models\InventoryReservation;
use Illuminate\Http\Resources\Json\JsonResource;

class TreasuryPaymentSubmissionListResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'order_number' => '#'.str_pad((string) $this->order_id, 6, '0', STR_PAD_LEFT),
            'customer' => $this->whenLoaded('order', fn () => [
                'name' => $this->order->user?->name,
                'email' => $this->order->user?->email,
            ]),
            'channel' => $this->channel,
            'expected_amount' => $this->expected_amount,
            'currency' => $this->currency,
            'operation_number_masked' => $this->resource->maskedOperationNumber(),
            'receiving_account' => $this->receivingAccount(),
            'status' => $this->status,
            'declared_paid_at' => $this->iso($this->declared_paid_at),
            'submitted_at' => $this->iso($this->submitted_at),
            'pending_minutes' => $this->pendingMinutes(),
            'reservation_status' => $this->reservationStatus(),
        ];
    }

    protected function receivingAccount(): array
    {
        return array_filter([
            'id' => data_get($this->receiving_account_snapshot, 'id'),
            'code' => data_get($this->receiving_account_snapshot, 'code'),
            'display_name' => data_get($this->receiving_account_snapshot, 'display_name'),
        ], fn ($value) => $value !== null);
    }

    protected function pendingMinutes(): ?int
    {
        if (! in_array($this->status, ['pending_review', 'observed'], true) || ! $this->submitted_at) {
            return null;
        }

        return max(0, $this->submitted_at->diffInMinutes(now()));
    }

    protected function reservationStatus(): string
    {
        $reservations = $this->order?->reservations ?? collect();
        if ($reservations->contains(fn ($reservation) => $reservation->status === InventoryReservation::ACTIVE && $reservation->expires_at?->isFuture())) {
            return 'active';
        }

        return $reservations->isEmpty() ? 'none' : 'expired';
    }

    protected function iso($date): ?string
    {
        return $date?->toIso8601String();
    }
}
