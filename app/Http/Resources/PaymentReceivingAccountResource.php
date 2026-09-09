<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentReceivingAccountResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'channel' => $this->channel, 'display_name' => $this->display_name, 'holder_name' => $this->holder_name, 'currency' => $this->currency, 'phone_masked' => $this->mask($this->phone), 'bank_name' => $this->bank_name, 'account_number_masked' => $this->mask($this->account_number), 'cci_masked' => $this->mask($this->cci), 'has_qr' => filled($this->qr_path), 'is_active' => $this->is_active, 'is_default' => $this->is_default, 'sort_order' => $this->sort_order, 'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }

    private function mask(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        return strlen($value) <= 4 ? '••••' : str_repeat('•', strlen($value) - 4).substr($value, -4);
    }
}
