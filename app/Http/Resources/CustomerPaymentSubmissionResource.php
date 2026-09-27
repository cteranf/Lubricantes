<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CustomerPaymentSubmissionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'channel' => $this->channel,
            'expected_amount' => $this->expected_amount,
            'currency' => $this->currency,
            'operation_number_masked' => $this->resource->maskedOperationNumber(),
            'declared_paid_at' => optional($this->declared_paid_at)->toIso8601String(),
            'submitted_at' => optional($this->submitted_at)->toIso8601String(),
            'reservation_expires_at' => $this->order?->reserved_until?->toIso8601String(),
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
}
