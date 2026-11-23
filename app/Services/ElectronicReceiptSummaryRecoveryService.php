<?php

namespace App\Services;

use App\Models\ElectronicDocument;
use App\Models\ElectronicReceiptSummary;
use App\Models\ElectronicReceiptSummaryAttempt;
use App\Models\ElectronicReceiptSummaryEvent;
use App\Models\ElectronicReceiptSummaryItem;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class ElectronicReceiptSummaryRecoveryService
{
    /** No external request occurs; interrupted local packaging is terminal and never auto-republished. */
    public function recoverStaleProcessing(int $staleMinutes = 5): int
    {
        $cutoff = now()->subMinutes(max(2, $staleMinutes));
        $candidates = ElectronicReceiptSummaryAttempt::query()->where('status', 'processing')->where('updated_at', '<', $cutoff)
            ->orderBy('id')->limit(500)->get(['id', 'electronic_receipt_summary_id']);
        $count = 0;
        foreach ($candidates as $candidate) {
            $count += DB::transaction(function () use ($candidate, $cutoff): int {
                $documentIds = ElectronicReceiptSummaryItem::query()->where('electronic_receipt_summary_id', $candidate->electronic_receipt_summary_id)
                    ->orderBy('electronic_document_id')->pluck('electronic_document_id');
                $orderIds = $documentIds->isEmpty() ? collect() : ElectronicDocument::query()->whereIn('id', $documentIds)
                    ->orderBy('order_id')->pluck('order_id')->unique()->values();
                if ($orderIds->isNotEmpty()) {
                    Order::query()->whereIn('id', $orderIds)->orderBy('id')->lockForUpdate()->get(['id']);
                }
                $documents = $documentIds->isEmpty() ? collect() : ElectronicDocument::query()->whereIn('id', $documentIds)
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $summary = ElectronicReceiptSummary::query()->whereKey($candidate->electronic_receipt_summary_id)->lockForUpdate()->first();
                $attempt = ElectronicReceiptSummaryAttempt::query()->whereKey($candidate->id)->lockForUpdate()->first();
                if (! $summary || ! $attempt || $summary->status !== 'processing' || $attempt->status !== 'processing'
                    || ! $attempt->updated_at || $attempt->updated_at->greaterThanOrEqualTo($cutoff)) {
                    return 0;
                }
                $items = ElectronicReceiptSummaryItem::query()->where('electronic_receipt_summary_id', $summary->id)
                    ->orderBy('electronic_document_id')->lockForUpdate()->get();

                if (filled($summary->local_simulation_id)) {
                    $summary->status = 'simulated_processing_pending';
                    $summary->communication_status = 'ticket_pending';
                    $summary->result_code ??= 'LOCAL_SIM_TICKET_CREATED';
                    $summary->result_message = 'Se recuperó el identificador LOCAL-SIM persistido. El resultado sigue pendiente de consulta simulada.';
                    $summary->save();
                    $attempt->status = 'simulated_processing_pending';
                    $attempt->communication_status = 'ticket_pending';
                    $attempt->local_simulation_id = $summary->local_simulation_id;
                    $attempt->completed_at = null;
                    $attempt->save();
                    foreach ($items as $item) {
                        $document = $documents->get($item->electronic_document_id);
                        if ($document) {
                            $document->status = 'summary_pending';
                            $document->result_code = (string) $summary->result_code;
                            $document->result_message = (string) $summary->result_message;
                            $document->save();
                        }
                        $item->result_status = 'simulated_ticket';
                        $item->result_code = (string) $summary->result_code;
                        $item->result_message = (string) $summary->result_message;
                        $item->save();
                    }
                    ElectronicReceiptSummaryEvent::query()->create([
                        'electronic_receipt_summary_id' => $summary->id, 'actor_id' => null,
                        'event_type' => 'local_simulation_ticket_recovered',
                        'details' => ['mode' => 'local_simulation'], 'created_at' => now(),
                    ]);

                    return 1;
                }
                if (($summary->snapshot['communication_mode'] ?? null) === 'sunat_beta') {
                    $hasTicket = filled($summary->external_ticket) || filled($attempt->external_ticket);
                    $summary->status = $hasTicket ? 'beta_processing_pending' : 'beta_uncertain';
                    $summary->communication_status = $hasTicket ? 'ticket_pending' : 'uncertain';
                    $summary->result_code ??= $hasTicket ? 'beta_ticket_recovered' : 'beta_worker_interrupted';
                    $summary->result_message = $hasTicket
                        ? 'Se recuperó el ticket externo persistido. El procesamiento Beta sigue pendiente de consulta.'
                        : 'El worker se interrumpió durante la posible comunicación. No se reenvía automáticamente; conserve el resumen y su número.';
                    $summary->save();
                    $attempt->status = $summary->status;
                    $attempt->communication_status = $summary->communication_status;
                    $attempt->external_ticket = $summary->external_ticket ?? $attempt->external_ticket;
                    $attempt->completed_at = null;
                    $attempt->safe_observations = $summary->result_message;
                    $attempt->save();
                    foreach ($items as $item) {
                        $document = $documents->get($item->electronic_document_id);
                        if ($document && in_array($document->status, ['ready_for_summary', 'summary_pending', 'summary_uncertain'], true)) {
                            $document->status = 'summary_uncertain';
                            $document->result_code = (string) $summary->result_code;
                            $document->result_message = (string) $summary->result_message;
                            $document->save();
                        }
                        $item->result_status = $hasTicket ? 'beta_ticket_pending' : 'beta_uncertain';
                        $item->result_code = (string) $summary->result_code;
                        $item->result_message = (string) $summary->result_message;
                        $item->save();
                    }
                    ElectronicReceiptSummaryEvent::query()->create([
                        'electronic_receipt_summary_id' => $summary->id, 'actor_id' => null,
                        'event_type' => $hasTicket ? 'beta_ticket_recovered' : 'beta_transmission_uncertain_after_interruption',
                        'details' => ['mode' => 'sunat_beta', 'ticket_persisted' => $hasTicket], 'created_at' => now(),
                    ]);

                    return 1;
                }
                $summary->status = 'local_preparation_interrupted';
                $summary->result_code = 'local_preparation_interrupted';
                $summary->result_message = 'La preparación local se interrumpió. No se transmitió a SUNAT; conserve el documento y la numeración.';
                $summary->save();
                $attempt->status = 'local_preparation_interrupted';
                $attempt->response_code = 'local_preparation_interrupted';
                $attempt->safe_observations = 'No se reintentó porque una interrupción del worker no debe crear una segunda operación.';
                $attempt->completed_at = now();
                $attempt->save();
                foreach ($items as $item) {
                    $document = $documents->get($item->electronic_document_id);
                    if ($document && in_array($document->status, ['ready_for_summary', 'summary_pending', 'summary_uncertain'], true)) {
                        $document->status = 'summary_failed';
                        $document->result_code = 'local_preparation_interrupted';
                        $document->result_message = 'La preparación local se interrumpió; conserve la numeración y revise el resumen.';
                        $document->save();
                    }
                    $item->result_status = 'local_preparation_interrupted';
                    $item->result_code = 'local_preparation_interrupted';
                    $item->result_message = 'La preparación local se interrumpió; no se confirmó comunicación.';
                    $item->save();
                }
                ElectronicReceiptSummaryEvent::query()->create([
                    'electronic_receipt_summary_id' => $summary->id, 'actor_id' => null,
                    'event_type' => 'local_preparation_interrupted', 'details' => ['mode' => 'local_simulation'], 'created_at' => now(),
                ]);

                return 1;
            });
        }

        return $count;
    }
}
