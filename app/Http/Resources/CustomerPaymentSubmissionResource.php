<?php

namespace App\Http\Resources;

use App\Models\InventoryReservation;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerPaymentSubmissionResource extends JsonResource
{
    public function toArray($request): array
    {
        $reservation = $this->reservationSummary();

        return [
            'id' => $this->id,
            'status' => $this->status,
            'channel' => $this->channel,
            'expected_amount' => $this->expected_amount,
            'currency' => $this->currency,
            'operation_number_masked' => $this->resource->maskedOperationNumber(),
            'declared_paid_at' => optional($this->declared_paid_at)->toIso8601String(),
            'submitted_at' => optional($this->submitted_at)->toIso8601String(),
            'reservation_status' => $reservation['status'],
            'reservation_expires_at' => $reservation['expires_at'],
            'message' => match ($this->status) {
                'observed' => 'Tesorería solicitó revisar los datos del pago.',
                'rejected' => 'La presentación del pago fue rechazada.',
                'approved' => 'Pago validado por Tesorería.',
                default => 'Pendiente de validación por Tesorería',
            },
            'reason' => $this->when(in_array($this->status, ['observed', 'rejected'], true), $this->decision_reason),
            'validated_at' => $this->when($this->status === 'approved', $this->reviewed_at?->toIso8601String()),
        ];
    }

    private function reservationSummary(): array
    {
        if (! $this->relationLoaded('order') || ! $this->order?->relationLoaded('items')) {
            return ['status' => 'none', 'expires_at' => null];
        }

        $reservations = $this->order->items
            ->map(fn ($item) => $item->relationLoaded('currentReservation') ? $item->currentReservation : null)
            ->filter()
            ->values();

        if ($reservations->isEmpty()) {
            return ['status' => 'none', 'expires_at' => null];
        }

        $statuses = $reservations
            ->map(fn ($reservation) => $reservation->status === InventoryReservation::ACTIVE && ! $reservation->expires_at?->isFuture()
                ? InventoryReservation::EXPIRED
                : $reservation->status)
            ->unique()
            ->values();

        if ($statuses->count() !== 1) {
            return ['status' => 'mixed', 'expires_at' => null];
        }

        $status = $statuses->first();
        $expiresAt = $status === InventoryReservation::ACTIVE
            ? $reservations->min(fn ($reservation) => $reservation->expires_at?->getTimestamp())
            : null;

        return [
            'status' => $status,
            'expires_at' => $expiresAt === null ? null : now()->setTimestamp($expiresAt)->toIso8601String(),
        ];
    }
}
