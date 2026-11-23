<?php

namespace App\Services;

use App\Jobs\ProcessElectronicReceiptSummary;
use App\Models\ElectronicReceiptSummary;
use App\Models\ElectronicReceiptSummaryAttempt;
use App\Models\ElectronicReceiptSummaryOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class ElectronicReceiptSummaryOutboxPublisher
{
    public function publishPending(?int $summaryId = null, int $limit = 100): int
    {
        $defaultConnection = (string) config('database.default');
        $queueDatabase = config('queue.connections.database.connection') ?: $defaultConnection;
        if ($queueDatabase !== $defaultConnection) {
            throw new \RuntimeException('The receipt summary queue must share its database transaction.');
        }
        $connection = DB::connection($defaultConnection);
        $ids = ElectronicReceiptSummaryOutbox::query()->whereNull('published_at')
            ->when($summaryId !== null, fn ($query) => $query->whereHas('attempt', fn ($attempt) => $attempt->where('electronic_receipt_summary_id', $summaryId)))
            ->orderBy('id')->limit(max(1, min($limit, 500)))->pluck('id');
        $published = 0;
        foreach ($ids as $id) {
            $published += $connection->transaction(function () use ($id): int {
                $outboxCandidate = ElectronicReceiptSummaryOutbox::query()->find($id);
                if (! $outboxCandidate) {
                    return 0;
                }
                $attemptCandidate = ElectronicReceiptSummaryAttempt::query()->find($outboxCandidate->electronic_receipt_summary_attempt_id);
                if (! $attemptCandidate) {
                    return 0;
                }
                // Lock order: summary, attempt, outbox.
                $summary = ElectronicReceiptSummary::query()->whereKey($attemptCandidate->electronic_receipt_summary_id)->lockForUpdate()->first();
                $attempt = ElectronicReceiptSummaryAttempt::query()->whereKey($attemptCandidate->id)->lockForUpdate()->first();
                $outbox = ElectronicReceiptSummaryOutbox::query()->whereKey($id)->lockForUpdate()->first();
                if (! $summary || ! $attempt || ! $outbox || $outbox->published_at) {
                    return 0;
                }
                if ($summary->status !== 'queued' || $attempt->status !== 'queued') {
                    $outbox->published_at = now();
                    $outbox->save();

                    return 0;
                }
                Queue::connection('database')->pushOn('electronic-invoices', new ProcessElectronicReceiptSummary($summary->id, $attempt->id));
                $outbox->published_at = now();
                $outbox->save();

                return 1;
            });
        }

        return $published;
    }
}
