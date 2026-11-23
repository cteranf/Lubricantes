<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ElectronicReceiptSummaryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'document_id' => $this->document_id,
            'issue_date' => $this->issue_date?->toDateString(),
            'status' => $this->status,
            'status_label' => $this->statusLabel((string) $this->status),
            'document_count' => (int) $this->document_count,
            'local_simulation_id' => $this->local_simulation_id,
            'external_ticket' => $this->external_ticket,
            'communication_status' => $this->communication_status ?? 'not_started',
            'communication_status_label' => $this->communicationStatusLabel((string) ($this->communication_status ?? 'not_started')),
            'simulated' => true,
            'result_code' => $this->result_code,
            'result_message' => $this->result_message,
            'communication_mode' => 'local_simulation_only',
            'files' => ['xml' => filled($this->xml_path), 'archive' => filled($this->zip_path), 'cdr' => false, 'result' => false],
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'line_number' => (int) $item->line_number,
                'result_status' => $item->result_status,
                'result_code' => $item->result_code,
                'result_message' => $item->result_message,
                'document' => $item->document ? [
                    'id' => (int) $item->document->id,
                    'order_id' => (int) $item->document->order_id,
                    'order_number' => '#'.str_pad((string) $item->document->order_id, 6, '0', STR_PAD_LEFT),
                    'series' => $item->document->series,
                    'document_number' => (int) $item->document->document_number,
                    'total' => data_get($item->document->amounts_snapshot, 'total'),
                    'status' => $item->document->status,
                    'files' => [
                        'xml' => filled($item->document->xml_path),
                        'archive' => filled($item->document->zip_path),
                        'pdf' => $item->document->environment === 'testing' && filled($item->document->xml_path),
                        'cdr' => filled($item->document->cdr_path),
                    ],
                ] : null,
            ])->all()),
            'attempts' => $this->whenLoaded('attempts', fn () => $this->attempts->map(fn ($attempt): array => [
                'attempt_number' => (int) $attempt->attempt_number,
                'status' => $attempt->status,
                'local_simulation_id' => $attempt->local_simulation_id,
                'external_ticket' => $attempt->external_ticket,
                'communication_status' => $attempt->communication_status,
                'response_code' => $attempt->response_code,
                'observations' => $attempt->safe_observations,
                'started_at' => $attempt->started_at?->toISOString(),
                'completed_at' => $attempt->completed_at?->toISOString(),
            ])->all()),
            'history' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event): array => [
                'event_type' => $event->event_type,
                'details' => $event->details ?? [],
                'created_at' => $event->created_at?->toISOString(),
            ])->all()),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'queued' => 'En cola para preparar resumen local',
            'processing' => 'Preparando resumen en QA local',
            'simulated_processing_pending' => 'Procesamiento pendiente simulado localmente; sin comunicación con SUNAT',
            'simulated_accepted' => 'Aceptación simulada localmente; no es una respuesta SUNAT',
            'simulated_accepted_observed' => 'Aceptación con observaciones simulada localmente',
            'simulated_rejected' => 'Rechazo simulado localmente; no es una respuesta SUNAT',
            'simulated_soap_fault' => 'Falla SOAP simulada localmente',
            'simulated_http_error' => 'Error HTTP simulado localmente',
            'simulated_timeout_uncertain' => 'Timeout simulado; estado incierto, conserva numeración',
            'local_preparation_failed' => 'Falló la preparación local; no enviado',
            'local_preparation_interrupted' => 'Preparación local interrumpida; conserva número',
            default => 'Estado no disponible',
        };
    }

    private function communicationStatusLabel(string $status): string
    {
        return match ($status) {
            'ticket_pending' => 'Identificador local pendiente; no es ticket externo',
            'accepted' => 'Aceptación simulada',
            'accepted_observed' => 'Aceptación con observaciones simulada',
            'rejected' => 'Rechazo simulado',
            'soap_fault' => 'Falla SOAP simulada',
            'http_error' => 'Error HTTP simulado',
            'uncertain' => 'Resultado simulado incierto',
            default => 'Sin resultado de comunicación',
        };
    }
}
