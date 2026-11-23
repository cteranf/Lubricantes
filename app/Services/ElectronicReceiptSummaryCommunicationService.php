<?php

namespace App\Services;

use App\Contracts\ElectronicReceiptSummaryTransport;
use App\Models\ElectronicDocument;
use App\Models\ElectronicReceiptSummary;
use App\Models\ElectronicReceiptSummaryAttempt;
use App\Models\ElectronicReceiptSummaryEvent;
use App\Models\ElectronicReceiptSummaryItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Persists QA simulation responses idempotently; real summary transport remains unavailable. */
class ElectronicReceiptSummaryCommunicationService
{
    public function __construct(private ElectronicReceiptSummaryTransport $transport, private SunatReceiptSummaryCdrProcessor $cdrProcessor) {}

    /** @param array<string, mixed> $result */
    public function persistSubmission(int $summaryId, int $attemptId, array $result): void
    {
        if (($result['simulated'] ?? false) !== true) {
            $this->persistExternalResponse($summaryId, $attemptId, $result, true);

            return;
        }
        DB::transaction(function () use ($summaryId, $attemptId, $result): void {
            [$summary, $attempt, $items, $documents] = $this->lockSummaryContext($summaryId, $attemptId);
            if ($summary->status !== 'processing' || $attempt->status !== 'processing' || ($result['simulated'] ?? false) !== true) {
                return;
            }

            $type = (string) ($result['type'] ?? 'timeout');
            $localId = is_string($result['local_simulation_id'] ?? null) ? $result['local_simulation_id'] : null;
            $communicationStatus = match ($type) {
                'ticket' => 'ticket_pending',
                'pending' => 'ticket_pending',
                'accepted' => 'accepted',
                'accepted_observed' => 'accepted_observed',
                'rejected' => 'rejected',
                'soap_fault' => 'soap_fault',
                'http_error' => 'http_error',
                default => 'uncertain',
            };
            $summaryStatus = match ($type) {
                'ticket', 'pending' => 'simulated_processing_pending',
                'accepted' => 'simulated_accepted',
                'accepted_observed' => 'simulated_accepted_observed',
                'rejected' => 'simulated_rejected',
                'soap_fault' => 'simulated_soap_fault',
                'http_error' => 'simulated_http_error',
                default => 'simulated_timeout_uncertain',
            };
            $hash = $this->responseHash($result);
            $summary->status = $summaryStatus;
            $summary->communication_status = $communicationStatus;
            $summary->local_simulation_id = $localId;
            $summary->external_ticket = null;
            $summary->response_hash = $hash;
            $summary->result_code = (string) ($result['code'] ?? 'SIMULATED_RESULT');
            $summary->result_message = $this->safeMessage($result);
            $summary->save();

            $attempt->status = $summaryStatus;
            $attempt->communication_status = $communicationStatus;
            $attempt->local_simulation_id = $localId;
            $attempt->external_ticket = null;
            $attempt->response_hash = $hash;
            $attempt->response_code = $summary->result_code;
            $attempt->safe_observations = $summary->result_message;
            $attempt->completed_at = in_array($communicationStatus, ['ticket_pending', 'uncertain'], true) ? null : now();
            $attempt->save();

            $this->projectToDocuments($summary, $items, $documents, $type, (string) $summary->result_code, (string) $summary->result_message);
            $this->event($summary, 'simulated_submission_result', ['simulation' => true, 'type' => $type, 'communication_status' => $communicationStatus]);
        });
    }

    public function query(int $summaryId, User $actor): ElectronicReceiptSummary
    {
        abort_unless((bool) config('electronic_invoicing.enabled'), 409, 'El módulo está deshabilitado; no se permiten consultas que cambien el estado.');
        abort_unless(app()->environment('testing', 'local'), 409, 'La consulta simulada está deshabilitada fuera de QA local.');

        $query = DB::transaction(function () use ($summaryId, $actor): ?array {
            [$summary, $attempt] = $this->lockSummaryContext($summaryId);
            if (! in_array($summary->communication_status, ['ticket_pending', 'uncertain'], true)) {
                return null;
            }
            $mode = null;
            $identifier = null;
            if (filled($summary->local_simulation_id)) {
                $mode = 'local_simulation';
                $identifier = (string) $summary->local_simulation_id;
            } elseif (filled($summary->external_ticket)) {
                abort_unless((bool) config('electronic_invoicing.receipt_beta.enabled'), 409, 'La consulta SUNAT Beta está deshabilitada; el ticket se conserva.');
                abort_unless($summary->environment === 'testing', 409, 'Solo se consultan tickets Beta de resúmenes de prueba.');
                $mode = 'sunat_beta';
                $identifier = (string) $summary->external_ticket;
            } else {
                return null;
            }
            $attempt->query_count = (int) $attempt->query_count + 1;
            $attempt->save();
            $this->event($summary, $mode === 'sunat_beta' ? 'beta_query_requested' : 'simulated_query_requested', [
                'mode' => $mode, 'query_number' => (int) $attempt->query_count,
            ], (int) $actor->id);

            return ['identifier' => $identifier, 'mode' => $mode, 'query_number' => (int) $attempt->query_count, 'attempt_id' => (int) $attempt->id];
        });
        if ($query !== null) {
            $transport = $query['mode'] === 'sunat_beta' ? app(SunatBetaElectronicReceiptSummaryTransport::class) : $this->transport;
            $result = $transport->query($query['identifier'], $query['query_number']);
            if ($query['mode'] === 'sunat_beta') {
                $this->persistExternalResponse($summaryId, $query['attempt_id'], $result, false);
            } else {
                $this->persistQueryResponse($summaryId, $query['attempt_id'], $result);
            }
            if (($result['duplicate'] ?? false) === true) {
                $query['mode'] === 'sunat_beta'
                    ? $this->persistExternalResponse($summaryId, $query['attempt_id'], $result, false)
                    : $this->persistQueryResponse($summaryId, $query['attempt_id'], $result);
            }
        }

        return ElectronicReceiptSummary::query()->with(['items.document', 'attempts', 'events'])->findOrFail($summaryId);
    }

    /** Public for deterministic late/duplicate response tests; never accepts unsimulated responses. */
    public function persistQueryResponse(int $summaryId, int $attemptId, array $result): void
    {
        if (($result['simulated'] ?? false) !== true) {
            $this->persistExternalResponse($summaryId, $attemptId, $result, false);

            return;
        }
        DB::transaction(function () use ($summaryId, $attemptId, $result): void {
            [$summary, $attempt, $items, $documents] = $this->lockSummaryContext($summaryId, $attemptId);
            if (($result['simulated'] ?? false) !== true || $attempt->local_simulation_id !== $summary->local_simulation_id) {
                abort(409, 'La respuesta no pertenece a una simulación local de este resumen.');
            }
            $hash = $this->responseHash($result);
            if (hash_equals((string) ($attempt->response_hash ?? ''), $hash)) {
                return;
            }
            if (! in_array($summary->communication_status, ['ticket_pending', 'uncertain'], true)) {
                $this->event($summary, 'simulated_late_response_ignored', ['simulation' => true, 'response_hash' => $hash]);

                return;
            }

            $type = (string) ($result['type'] ?? 'timeout');
            $communicationStatus = match ($type) {
                'pending' => 'ticket_pending',
                'accepted' => 'accepted',
                'accepted_observed' => 'accepted_observed',
                'rejected' => 'rejected',
                'soap_fault' => 'soap_fault',
                'http_error' => 'http_error',
                default => 'uncertain',
            };
            $summaryStatus = match ($communicationStatus) {
                'ticket_pending' => 'simulated_processing_pending',
                'accepted' => 'simulated_accepted',
                'accepted_observed' => 'simulated_accepted_observed',
                'rejected' => 'simulated_rejected',
                'soap_fault' => 'simulated_soap_fault',
                'http_error' => 'simulated_http_error',
                default => 'simulated_timeout_uncertain',
            };
            $code = (string) ($result['code'] ?? 'SIMULATED_RESULT');
            $message = $this->safeMessage($result);
            $summary->status = $summaryStatus;
            $summary->communication_status = $communicationStatus;
            $summary->response_hash = $hash;
            $summary->result_code = $code;
            $summary->result_message = $message;
            $summary->save();
            $attempt->status = $summaryStatus;
            $attempt->communication_status = $communicationStatus;
            $attempt->response_hash = $hash;
            $attempt->response_code = $code;
            $attempt->safe_observations = $message;
            $attempt->completed_at = $communicationStatus === 'ticket_pending' || $communicationStatus === 'uncertain' ? null : now();
            $attempt->save();
            $this->projectToDocuments($summary, $items, $documents, $type, $code, $message);
            $this->event($summary, 'simulated_query_result', ['simulation' => true, 'type' => $type, 'communication_status' => $communicationStatus, 'response_hash' => $hash]);

        });
    }

    /** Persist a response from the explicitly enabled Beta adapter; never labels it simulated. */
    private function persistExternalResponse(int $summaryId, int $attemptId, array $result, bool $submission): void
    {
        if (($result['simulated'] ?? null) !== false || ($result['mode'] ?? null) !== 'sunat_beta') {
            abort(409, 'La respuesta no pertenece al transporte SUNAT Beta.');
        }
        $type = (string) ($result['type'] ?? 'uncertain');
        $cdr = null;
        if ($type === 'cdr') {
            try {
                $context = ElectronicReceiptSummary::query()->with('items.document')->findOrFail($summaryId);
                $cdr = $this->cdrProcessor->process($context, (string) ($result['cdr_zip'] ?? ''));
                if (($result['sunat_status_code'] ?? null) === '99' && $cdr['type'] !== 'rejected') {
                    throw new \DomainException('El estado de procesamiento y el código de CDR son contradictorios.');
                }
                $type = $cdr['type'];
                $result['code'] = $cdr['code'];
                $result['message'] = $cdr['message'];
                $result['observations'] = $cdr['observations'];
                $result['cdr_xml'] = $cdr['xml'];
            } catch (\Throwable) {
                $type = 'uncertain';
                $result['code'] = 'cdr_validation_failed';
                $result['message'] = 'La respuesta recibida no superó la validación de firma, esquema o correspondencia. Se conserva el ticket y el resultado queda incierto.';
                unset($result['cdr_zip']);
            }
        }
        $communicationStatus = match ($type) {
            'ticket', 'pending' => 'ticket_pending',
            'accepted' => 'accepted',
            'accepted_observed' => 'accepted_observed',
            'rejected' => 'rejected',
            'soap_fault' => 'soap_fault',
            'http_error' => 'http_error',
            default => 'uncertain',
        };
        $summaryStatus = match ($communicationStatus) {
            'ticket_pending' => 'beta_processing_pending',
            'accepted' => 'beta_accepted',
            'accepted_observed' => 'beta_accepted_observed',
            'rejected' => 'beta_rejected',
            'soap_fault' => 'beta_soap_fault',
            'http_error' => 'beta_http_error',
            default => 'beta_uncertain',
        };
        $hashSource = ['type' => $type, 'code' => $result['code'] ?? null, 'ticket' => $result['external_ticket'] ?? null,
            'http_status' => $result['http_status'] ?? null, 'cdr_hash' => isset($result['cdr_xml']) ? hash('sha256', $result['cdr_xml']) : null,
            'response_hash' => isset($result['response_xml']) ? hash('sha256', $result['response_xml']) : null,
            'request_zip_sha256' => $result['request_zip_sha256'] ?? null];
        $hash = hash('sha256', json_encode($hashSource, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        DB::transaction(function () use ($summaryId, $attemptId, $result, $communicationStatus, $summaryStatus, $hash, $cdr, $submission): void {
            [$summary, $attempt, $items, $documents] = $this->lockSummaryContext($summaryId, $attemptId);
            if (($summary->snapshot['communication_mode'] ?? null) !== 'sunat_beta'
                || $summary->environment !== 'testing'
                || ($submission && ($summary->status !== 'processing' || $attempt->status !== 'processing'))
                || (! $submission && ! in_array($summary->communication_status, ['ticket_pending', 'uncertain'], true))) {
                return;
            }
            if (! $submission && hash_equals((string) ($attempt->response_hash ?? ''), $hash)) {
                return;
            }
            if (! $submission && ! in_array($summary->communication_status, ['ticket_pending', 'uncertain'], true)) {
                $this->event($summary, 'beta_late_response_ignored', ['mode' => 'sunat_beta', 'response_hash' => $hash]);

                return;
            }
            $externalTicket = is_string($result['external_ticket'] ?? null) ? $result['external_ticket'] : null;
            if ($externalTicket !== null) {
                abort_unless($externalTicket !== '' && ! str_starts_with($externalTicket, 'LOCAL-SIM-'), 409, 'Un ticket local no puede guardarse como ticket externo.');
                if ($submission) {
                    abort_unless($summary->external_ticket === null && $attempt->external_ticket === null, 409, 'El resumen ya tiene un ticket externo persistido.');
                } else {
                    abort_unless(hash_equals((string) $summary->external_ticket, $externalTicket), 409, 'La respuesta corresponde a otro ticket externo.');
                }
            }
            $disk = Storage::disk('local');
            $responsePath = null;
            $responseBody = is_string($result['response_xml'] ?? null) ? $result['response_xml'] : null;
            if ($responseBody !== null && $responseBody !== '') {
                $suffix = 'attempt-'.$attempt->attempt_number.'-query-'.(int) $attempt->query_count.'-response.xml';
                $responsePath = 'electronic-invoices/summaries/'.$summary->id.'/beta-'.$suffix;
                abort_unless($disk->put($responsePath, $responseBody), 503, 'No se pudo conservar la respuesta SOAP en almacenamiento privado.');
            }
            if (is_string($result['cdr_xml'] ?? null) && $result['cdr_xml'] !== '') {
                $cdrPath = 'electronic-invoices/summaries/'.$summary->id.'/beta-CDR-'.substr(hash('sha256', $result['cdr_xml']), 0, 16).'.xml';
                abort_unless($disk->put($cdrPath, $result['cdr_xml']), 503, 'No se pudo conservar la CDR validada en almacenamiento privado.');
                $responsePath = $cdrPath;
                foreach ($documents as $document) {
                    $document->cdr_path = $cdrPath;
                    $document->save();
                }
            }
            $summary->status = $summaryStatus;
            $summary->communication_status = $communicationStatus;
            $summary->external_ticket = $externalTicket ?? $summary->external_ticket;
            $summary->local_simulation_id = null;
            $summary->response_hash = $hash;
            $summary->result_code = mb_substr((string) ($result['code'] ?? 'sunat_beta_result'), 0, 80);
            $summary->result_message = $this->safeExternalMessage($result);
            $summary->response_path = $responsePath ?? $summary->response_path;
            $summary->save();
            if ($submission) {
                $requestSnapshot = $attempt->request_snapshot ?? [];
                $requestSnapshot['transport_evidence'] = [
                    'endpoint' => $result['request_endpoint'] ?? null,
                    'action' => $result['request_action'] ?? null,
                    'content_type' => $result['request_content_type'] ?? null,
                    'file_name' => $result['request_file_name'] ?? null,
                    'zip_bytes' => isset($result['request_zip_bytes']) ? (int) $result['request_zip_bytes'] : null,
                    'zip_sha256' => $result['request_zip_sha256'] ?? null,
                    'content_file_base64_bytes' => isset($result['request_content_file_base64_bytes']) ? (int) $result['request_content_file_base64_bytes'] : null,
                ];
                $attempt->request_snapshot = $requestSnapshot;
            }
            $attempt->status = $summaryStatus;
            $attempt->communication_status = $communicationStatus;
            $attempt->external_ticket = $externalTicket ?? $attempt->external_ticket;
            $attempt->local_simulation_id = null;
            $attempt->response_hash = $hash;
            $attempt->response_code = $summary->result_code;
            $attempt->safe_observations = $this->safeExternalMessage($result);
            $attempt->response_path = $responsePath ?? $attempt->response_path;
            $attempt->completed_at = in_array($communicationStatus, ['ticket_pending', 'uncertain'], true) ? null : now();
            $attempt->save();
            $this->projectExternalToDocuments($summary, $items, $documents, $communicationStatus, $summary->result_code, $this->safeExternalMessage($result));
            $this->event($summary, $submission ? 'beta_submission_result' : 'beta_query_result', [
                'mode' => 'sunat_beta', 'communication_status' => $communicationStatus, 'response_hash' => $hash,
                'http_status' => isset($result['http_status']) ? (int) $result['http_status'] : null,
                'ticket_received' => $externalTicket !== null, 'cdr_validated' => $cdr !== null,
                'request_zip_sha256' => $result['request_zip_sha256'] ?? null,
            ]);
        });
    }

    private function projectExternalToDocuments(ElectronicReceiptSummary $summary, Collection $items, Collection $documents, string $communicationStatus, string $code, string $message): void
    {
        $documentStatus = match ($communicationStatus) {
            'accepted', 'accepted_observed' => 'reported',
            'rejected' => 'summary_rejected',
            default => 'summary_uncertain',
        };
        foreach ($items as $item) {
            $document = $documents->get($item->electronic_document_id);
            if ($document) {
                $document->status = $documentStatus;
                $document->result_code = $code;
                $document->result_message = $message;
                $document->save();
            }
            $item->result_status = 'beta_'.$communicationStatus;
            $item->result_code = $code;
            $item->result_message = $message;
            $item->save();
        }
    }

    private function safeExternalMessage(array $result): string
    {
        $message = strip_tags((string) ($result['message'] ?? 'Respuesta Beta recibida; conserve el ticket y la evidencia.'));
        $message = preg_replace('/\b(?:password|username|token|authorization)\s*[:=]\s*[^\s<]+/i', '[dato de autenticación omitido]', $message) ?? '';

        return mb_substr(trim($message), 0, 1000);
    }

    /** @return array{0: ElectronicReceiptSummary, 1: ElectronicReceiptSummaryAttempt, 2: Collection, 3: Collection} */
    private function lockSummaryContext(int $summaryId, ?int $attemptId = null): array
    {
        $documentIds = ElectronicReceiptSummaryItem::query()->where('electronic_receipt_summary_id', $summaryId)
            ->orderBy('electronic_document_id')->pluck('electronic_document_id');
        $orderIds = $documentIds->isEmpty() ? collect() : ElectronicDocument::query()->whereIn('id', $documentIds)
            ->orderBy('order_id')->pluck('order_id')->unique()->values();
        if ($orderIds->isNotEmpty()) {
            Order::query()->whereIn('id', $orderIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }
        $documents = $documentIds->isEmpty() ? collect() : ElectronicDocument::query()->whereIn('id', $documentIds)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $summary = ElectronicReceiptSummary::query()->whereKey($summaryId)->lockForUpdate()->firstOrFail();
        $attemptQuery = ElectronicReceiptSummaryAttempt::query()->where('electronic_receipt_summary_id', $summary->id)->orderByDesc('id');
        if ($attemptId !== null) {
            $attemptQuery->whereKey($attemptId);
        }
        $attempt = $attemptQuery->lockForUpdate()->firstOrFail();
        $items = ElectronicReceiptSummaryItem::query()->where('electronic_receipt_summary_id', $summary->id)
            ->orderBy('electronic_document_id')->lockForUpdate()->get();

        return [$summary, $attempt, $items, $documents];
    }

    private function projectToDocuments(ElectronicReceiptSummary $summary, Collection $items, Collection $documents, string $type, string $code, string $message): void
    {
        $documentStatus = match ($type) {
            'ticket', 'pending' => 'summary_pending',
            'accepted', 'accepted_observed' => 'reported',
            'rejected' => 'summary_rejected',
            'soap_fault', 'http_error' => 'summary_failed',
            default => 'summary_uncertain',
        };
        $itemStatus = 'simulated_'.$type;
        foreach ($items as $item) {
            $document = $documents->get($item->electronic_document_id);
            if ($document) {
                $document->status = $documentStatus;
                $document->result_code = $code;
                $document->result_message = $message;
                $document->save();
            }
            $item->result_status = $itemStatus;
            $item->result_code = $code;
            $item->result_message = $message;
            $item->save();
        }
    }

    private function responseHash(array $response): string
    {
        return hash('sha256', json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function safeMessage(array $response): string
    {
        return mb_substr((string) ($response['message'] ?? 'Resultado simulado localmente.'), 0, 1000);
    }

    private function event(ElectronicReceiptSummary $summary, string $type, array $details, ?int $actorId = null): void
    {
        ElectronicReceiptSummaryEvent::query()->create([
            'electronic_receipt_summary_id' => $summary->id, 'actor_id' => $actorId,
            'event_type' => $type, 'details' => ['mode' => 'local_simulation', ...$details], 'created_at' => now(),
        ]);
    }
}
