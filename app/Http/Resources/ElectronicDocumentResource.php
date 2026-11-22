<?php

namespace App\Http\Resources;

use App\Support\ElectronicDocumentStatus;
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
            'status_label' => ElectronicDocumentStatus::label($this->status),
            'series' => $this->series,
            'document_number' => $this->document_number === null ? null : (int) $this->document_number,
            'result_code' => $this->result_code,
            'result_message' => $this->result_message,
            'xml_hash' => $this->xml_hash,
            'files' => [
                'xml' => filled($this->xml_path),
                'archive' => filled($this->zip_path),
                'cdr' => filled($this->cdr_path),
                'pdf' => $this->document_type === 'factura'
                    && $this->environment === 'testing'
                    && in_array($this->status, ['accepted', 'accepted_observed', 'rejected'], true)
                    && filled($this->series) && filled($this->document_number),
            ],
            'accepted_at' => $this->accepted_at?->toISOString(),
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'attempts' => $this->whenLoaded('attempts', fn () => $this->attempts->map(fn ($attempt): array => [
                'attempt_number' => (int) $attempt->attempt_number,
                'status' => $attempt->status,
                'response_code' => $attempt->response_code,
                'ticket' => $attempt->ticket,
                'observations' => $attempt->safe_observations,
                'started_at' => $attempt->started_at?->toISOString(),
                'completed_at' => $attempt->completed_at?->toISOString(),
            ])->all()),
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
