<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CustomerPaymentOptionResource extends JsonResource
{
    public function toArray($request): array
    {
        $base = ['id' => $this->id, 'channel' => $this->channel, 'display_name' => $this->display_name, 'holder_name' => $this->holder_name, 'currency' => 'PEN'];
        if (in_array($this->channel, ['yape', 'plin'], true)) {
            return $base + ['has_qr' => true, 'qr_url' => url("/api/v1/orders/{$request->route('order')->id}/payment-options/{$this->id}/qr"), 'instructions' => 'Escanea el QR y conserva tu número de operación.'];
        }

        return $base + ['bank_name' => $this->bank_name, 'account_number' => $this->account_number, 'cci' => $this->cci, 'instructions' => 'Transfiere el importe exacto del pedido.'];
    }
}
