<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ElectronicDocumentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'order_id' => (int) $this->order_id,
            'draft_reference' => $this->draft_reference,
            'document_type' => $this->document_type,
            'status' => $this->status,
            'status_label' => 'Borrador; no emitido',
            'environment' => $this->environment,
            'connection_type' => $this->connection_type,
            'seller' => $this->seller_snapshot,
            'recipient' => $this->recipient_snapshot,
            'lines' => $this->lines_snapshot,
            'amounts' => $this->amounts_snapshot,
            'currency' => $this->currency,
            'tax_calculation_status' => $this->tax_calculation_status,
            'version' => (int) $this->version,
            'prepared_at' => $this->prepared_at?->toISOString(),
            'last_modified_at' => $this->last_modified_at?->toISOString(),
            'history' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event): array => [
                'event_type' => $event->event_type,
                'version_from' => $event->version_from,
                'version_to' => (int) $event->version_to,
                'changed_fields' => $event->changed_fields ?? [],
                'actor_name' => $event->actor?->name ?? 'Usuario no disponible',
                'created_at' => $event->created_at?->toISOString(),
            ])->all()),
        ];
    }
}
