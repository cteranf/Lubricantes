<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AdminInventoryReceiptResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'reference' => $this->reference,
            'external_reference' => $this->external_reference,
            'status' => $this->status,
            'version' => (int) $this->version,
            'notes' => $this->notes,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => (int) $this->warehouse->id,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ]),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => (int) $line->id,
                'product_id' => (int) $line->product_id,
                'product' => $line->product ? [
                    'id' => (int) $line->product->id,
                    'name' => $line->product->name,
                    'sku' => $line->product->sku,
                ] : null,
                'quantity' => (int) $line->quantity,
                'sort_order' => (int) $line->sort_order,
            ])->values()),
            'created_by' => $this->created_by === null ? null : (int) $this->created_by,
            'confirmed_by' => $this->confirmed_by === null ? null : (int) $this->confirmed_by,
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
