<?php

namespace App\Console\Commands;

use App\Services\ElectronicDocumentJobOutboxPublisher;
use App\Services\ElectronicDocumentRecoveryService;
use App\Services\ElectronicReceiptSummaryOutboxPublisher;
use App\Services\ElectronicReceiptSummaryRecoveryService;
use Illuminate\Console\Command;

class RecoverElectronicDocumentProcessing extends Command
{
    protected $signature = 'electronic-invoices:recover-processing';

    protected $description = 'Publish durable electronic-invoice jobs and mark interrupted workers uncertain.';

    public function handle(ElectronicDocumentJobOutboxPublisher $publisher, ElectronicDocumentRecoveryService $recovery, ElectronicReceiptSummaryOutboxPublisher $summaryPublisher, ElectronicReceiptSummaryRecoveryService $summaryRecovery): int
    {
        $published = $publisher->publishPending();
        $uncertain = $recovery->recoverStaleProcessing();
        $summaryPublished = $summaryPublisher->publishPending();
        $summaryFailures = $summaryRecovery->recoverStaleProcessing();
        $this->info("Published {$published} invoice job(s); marked {$uncertain} interrupted invoice attempt(s) uncertain; published {$summaryPublished} local summary job(s); recovered {$summaryFailures} interrupted local summary job(s).");

        return self::SUCCESS;
    }
}
