<?php

namespace App\Services;

use App\Models\ElectronicDocument;
use App\Models\ElectronicDocumentAttempt;
use App\Models\ElectronicDocumentEvent;
use Illuminate\Support\Facades\DB;

class ElectronicDocumentRecoveryService
{
    public function recoverStaleProcessing(int $staleMinutes = 5): int
    {
        $cutoff = now()->subMinutes(max(2, $staleMinutes));
        $candidates = ElectronicDocumentAttempt::query()->where('status', 'processing')
            ->where('updated_at', '<', $cutoff)->orderBy('id')->limit(500)
            ->get(['id', 'electronic_document_id']);
        $recovered = 0;

        foreach ($candidates as $candidate) {
            $recovered += DB::transaction(function () use ($candidate, $cutoff): int {
                $document = ElectronicDocument::query()->whereKey($candidate->electronic_document_id)->lockForUpdate()->first();
                $attempt = ElectronicDocumentAttempt::query()->whereKey($candidate->id)->lockForUpdate()->first();
                if (! $document || ! $attempt || $document->status !== 'processing' || $attempt->status !== 'processing'
                    || ! $attempt->updated_at || $attempt->updated_at->greaterThanOrEqualTo($cutoff)) {
                    return 0;
                }

                // A crashed worker may have sent the request before losing its response.
                // Never resend automatically: retain number and snapshots for explicit query.
                $document->status = 'uncertain';
                $document->result_code = 'worker_interrupted_result_uncertain';
                $document->result_message = 'El procesamiento se interrumpió. Consulta el resultado antes de cualquier reenvío.';
                $document->save();
                $attempt->status = 'uncertain';
                $attempt->response_code = 'worker_interrupted_result_uncertain';
                $attempt->completed_at = null;
                $attempt->save();
                ElectronicDocumentEvent::query()->create([
                    'electronic_document_id' => $document->id,
                    'actor_id' => null,
                    'event_type' => 'processing_interrupted',
                    'version_from' => $document->version,
                    'version_to' => $document->version,
                    'changed_fields' => ['status', 'result_code'],
                    'created_at' => now(),
                ]);

                return 1;
            });
        }

        return $recovered;
    }
}
