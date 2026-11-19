<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BillingOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'order_number' => '#'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT),
            'created_at' => $this->created_at?->toIso8601String(),
            'customer_name' => $this->user?->name ?? 'Cliente no disponible',
            'requested_receipt_type' => $this->requested_receipt_type,
            'requested_identity_type' => $this->requested_identity_type,
            'requested_identity_number' => $this->requested_identity_number,
            'requested_identity_required' => $this->requested_identity_required,
            'requested_recipient_name' => $this->requested_recipient_name,
            'requested_billing_address' => $this->requested_billing_address,
            'requested_receipt_email' => $this->requested_receipt_email,
            'receipt_data_missing' => $this->receiptDataMissing(),
            'total' => $this->total,
            'payment_status' => $this->payment_status,
            'receipt_status' => $this->requested_receipt_type ? 'requested_not_issued' : 'not_registered',
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'product_name' => $item->product_name ?? 'Producto no disponible',
                'quantity' => (int) $item->quantity,
                'regular_price' => $item->regular_price,
                'price' => $item->price,
                'subtotal' => $item->subtotal,
            ])->values()->all()),
        ];
    }

    private function receiptDataMissing(): bool
    {
        return ! $this->requested_receipt_type
            || ! $this->requested_receipt_email
            || ($this->requested_identity_required && (! $this->requested_identity_type || ! $this->requested_identity_number || ! $this->requested_recipient_name))
            || ($this->requested_receipt_type === 'factura' && ! $this->requested_billing_address);
    }
}
