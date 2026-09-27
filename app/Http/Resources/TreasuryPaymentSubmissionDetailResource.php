<?php

namespace App\Http\Resources;

class TreasuryPaymentSubmissionDetailResource extends TreasuryPaymentSubmissionListResource
{
    public function toArray($request): array
    {
        return array_merge(parent::toArray($request), [
            'operation_number' => $this->operation_number,
            'origin_phone_last4' => $this->origin_phone_last4,
            'origin_bank' => $this->origin_bank,
            'receiving_account_snapshot' => array_filter([
                'id' => data_get($this->receiving_account_snapshot, 'id'),
                'code' => data_get($this->receiving_account_snapshot, 'code'),
                'channel' => data_get($this->receiving_account_snapshot, 'channel'),
                'display_name' => data_get($this->receiving_account_snapshot, 'display_name'),
                'holder_name' => data_get($this->receiving_account_snapshot, 'holder_name'),
                'currency' => data_get($this->receiving_account_snapshot, 'currency'),
                'bank_name' => data_get($this->receiving_account_snapshot, 'bank_name'),
            ], fn ($value) => $value !== null),
            'review' => [
                'reviewed_at' => $this->reviewed_at?->toIso8601String(),
                'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? [
                    'id' => $this->reviewer->id,
                    'name' => $this->reviewer->name,
                    'role' => $this->reviewer->role,
                ] : null),
                'reason' => $this->decision_reason,
            ],
            'history' => TreasuryPaymentSubmissionHistoryResource::collection($this->whenLoaded('histories')),
            'approved_payment' => $this->when($this->status === 'approved' && $this->relationLoaded('paymentTransaction'), fn () => $this->paymentTransaction ? [
                'id' => $this->paymentTransaction->id,
                'status' => $this->paymentTransaction->status,
                'amount' => (string) $this->paymentTransaction->amount,
                'currency' => $this->paymentTransaction->currency,
                'channel' => $this->paymentTransaction->collection_method,
                'confirmed_at' => $this->paymentTransaction->confirmed_at?->toIso8601String(),
                'confirmer' => $this->paymentTransaction->relationLoaded('confirmer') && $this->paymentTransaction->confirmer ? [
                    'id' => $this->paymentTransaction->confirmer->id,
                    'name' => $this->paymentTransaction->confirmer->name,
                ] : null,
            ] : null),
        ]);
    }
}
