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

/** Persists QA simulation responses idempotently; real summary transport remains unavailable. */
class ElectronicReceiptSummaryCommunicationService
{
    public function __construct(private ElectronicReceiptSummaryTransport $transport) {}

    /** @param array<string, mixed> $result */
    public function persistSubmission(int $summaryId, int $attemptId, array $result): void
    {
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
            if (! $summary->local_simulation_id || ! in_array($summary->communication_status, ['ticket_pending', 'uncertain'], true)) {
                return null;
            }
            $attempt->query_count = (int) $attempt->query_count + 1;
            $attempt->save();
            $this->event($summary, 'simulated_query_requested', [
                'simulation' => true, 'query_number' => (int) $attempt->query_count,
            ], (int) $actor->id);

            return ['local_simulation_id' => $summary->local_simulation_id, 'query_number' => (int) $attempt->query_count, 'attempt_id' => (int) $attempt->id];
        });
        if ($query !== null) {
            $result = $this->transport->query($query['local_simulation_id'], $query['query_number']);
            $this->persistQueryResponse($summaryId, $query['attempt_id'], $result);
            if (($result['duplicate'] ?? false) === true) {
                $this->persistQueryResponse($summaryId, $query['attempt_id'], $result);
            }
        }

        return ElectronicReceiptSummary::query()->with(['items.document', 'attempts', 'events'])->findOrFail($summaryId);
    }

    /** Public for deterministic late/duplicate response tests; never accepts unsimulated responses. */
    public function persistQueryResponse(int $summaryId, int $attemptId, array $result): void
    {
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
