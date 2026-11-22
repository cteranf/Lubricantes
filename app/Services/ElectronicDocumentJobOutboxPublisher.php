<?php

namespace App\Services;

use App\Jobs\ProcessElectronicDocument;
use App\Models\ElectronicDocument;
use App\Models\ElectronicDocumentAttempt;
use App\Models\ElectronicDocumentOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class ElectronicDocumentJobOutboxPublisher
{
    public function publishPendingForDocument(int $documentId): int
    {
        return $this->publishPending(100, $documentId);
    }

    public function publishPending(int $limit = 100, ?int $documentId = null): int
    {
        $defaultConnection = (string) config('database.default');
        $queueDatabase = config('queue.connections.database.connection') ?: $defaultConnection;
        if ($queueDatabase !== $defaultConnection) {
            throw new \RuntimeException('Electronic invoice queue must share the document database transaction.');
        }

        $connection = DB::connection($defaultConnection);
        $pendingIds = ElectronicDocumentOutbox::query()->whereNull('published_at')
            ->when($documentId !== null, fn ($query) => $query->whereHas('attempt', fn ($attempt) => $attempt->where('electronic_document_id', $documentId)))
            ->orderBy('id')->limit(max(1, min($limit, 500)))->pluck('id');

        $published = 0;
        foreach ($pendingIds as $outboxId) {
            $wasPublished = $connection->transaction(function () use ($outboxId): bool {
                $outboxCandidate = ElectronicDocumentOutbox::query()->find($outboxId);
                if (! $outboxCandidate) {
                    return false;
                }
                $attemptCandidate = ElectronicDocumentAttempt::query()->find($outboxCandidate->electronic_document_attempt_id);
                if (! $attemptCandidate) {
                    return false;
                }

                // Keep the same lock order as the worker: document, attempt, outbox.
                $document = ElectronicDocument::query()->whereKey($attemptCandidate->electronic_document_id)->lockForUpdate()->first();
                $attempt = ElectronicDocumentAttempt::query()->whereKey($attemptCandidate->id)->lockForUpdate()->first();
                $outbox = ElectronicDocumentOutbox::query()->whereKey($outboxId)->lockForUpdate()->first();
                if (! $document || ! $attempt || ! $outbox || $outbox->published_at) {
                    return false;
                }
                if ($document->status !== 'queued' || $attempt->status !== 'queued') {
                    $outbox->published_at = now();
                    $outbox->save();

                    return false;
                }

                Queue::connection('database')->pushOn('electronic-invoices', new ProcessElectronicDocument($document->id, $attempt->id));
                $outbox->published_at = now();
                $outbox->save();

                return true;
            });
            $published += $wasPublished ? 1 : 0;
        }

        return $published;
    }
}
