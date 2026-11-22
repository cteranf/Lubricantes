<?php

namespace App\Jobs;

use App\Contracts\ElectronicInvoiceGateway;
use App\Models\ElectronicDocument;
use App\Models\ElectronicDocumentAttempt;
use App\Models\ElectronicDocumentEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessElectronicDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $documentId, public int $attemptId) {}

    public function handle(ElectronicInvoiceGateway $gateway): void
    {
        $ready = DB::transaction(function (): bool {
            $document = ElectronicDocument::query()->whereKey($this->documentId)->lockForUpdate()->first();
            $attempt = ElectronicDocumentAttempt::query()->whereKey($this->attemptId)->lockForUpdate()->first();
            if (! $document || ! $attempt || (int) $attempt->electronic_document_id !== (int) $document->id
                || $document->status !== 'queued' || $attempt->status !== 'queued') {
                return false;
            }
            $document->status = 'processing';
            $document->save();
            $attempt->status = 'processing';
            $attempt->save();

            return true;
        });
        if (! $ready) {
            return;
        }

        try {
            $document = ElectronicDocument::query()->findOrFail($this->documentId);
            $attempt = ElectronicDocumentAttempt::query()->findOrFail($this->attemptId);
            $result = $gateway->send($document, $attempt);
        } catch (\Throwable $exception) {
            Log::warning('Electronic document processing failed safely.', ['document_id' => $this->documentId, 'attempt_id' => $this->attemptId]);
            $result = ['status' => 'uncertain', 'response_code' => 'local_processing_error', 'message' => 'El procesamiento no pudo confirmar el resultado. Requiere consulta antes de reintentar.'];
        }

        DB::transaction(function () use ($result): void {
            $document = ElectronicDocument::query()->whereKey($this->documentId)->lockForUpdate()->firstOrFail();
            $attempt = ElectronicDocumentAttempt::query()->whereKey($this->attemptId)->lockForUpdate()->firstOrFail();
            if (in_array($document->status, ['accepted', 'accepted_observed', 'rejected', 'sunat_exception'], true)) {
                return;
            }
            $status = in_array($result['status'] ?? null, ['accepted', 'accepted_observed', 'rejected', 'uncertain', 'submitted', 'sunat_exception'], true)
                ? $result['status'] : 'uncertain';
            $document->status = $status;
            $document->result_code = $result['response_code'] ?? 'result_unavailable';
            $document->result_message = mb_substr((string) ($result['message'] ?? 'Resultado no disponible.'), 0, 1000);
            foreach (['xml_path', 'zip_path', 'cdr_path', 'xml_hash', 'accepted_at'] as $field) {
                if (array_key_exists($field, $result)) {
                    $document->{$field} = $result[$field];
                }
            }
            if (in_array($status, ['accepted', 'accepted_observed'], true) && ! $document->accepted_at) {
                $document->accepted_at = now();
            }
            $document->save();
            $attempt->status = $status;
            $attempt->response_code = $result['response_code'] ?? null;
            $attempt->ticket = $result['ticket'] ?? null;
            $attempt->response_path = $result['response_path'] ?? null;
            $attempt->safe_observations = isset($result['observations']) ? mb_substr((string) $result['observations'], 0, 2000) : null;
            $attempt->completed_at = in_array($status, ['uncertain', 'submitted'], true) ? null : now();
            $attempt->save();
            ElectronicDocumentEvent::query()->create([
                'electronic_document_id' => $document->id, 'actor_id' => null, 'event_type' => 'processing_result',
                'version_from' => $document->version, 'version_to' => $document->version,
                'changed_fields' => ['status', 'result_code'], 'created_at' => now(),
            ]);
        });
    }
}
