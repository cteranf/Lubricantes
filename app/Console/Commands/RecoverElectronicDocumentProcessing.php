<?php

namespace App\Console\Commands;

use App\Services\ElectronicDocumentJobOutboxPublisher;
use App\Services\ElectronicDocumentRecoveryService;
use Illuminate\Console\Command;

class RecoverElectronicDocumentProcessing extends Command
{
    protected $signature = 'electronic-invoices:recover-processing';

    protected $description = 'Publish durable electronic-invoice jobs and mark interrupted workers uncertain.';

    public function handle(ElectronicDocumentJobOutboxPublisher $publisher, ElectronicDocumentRecoveryService $recovery): int
    {
        $published = $publisher->publishPending();
        $uncertain = $recovery->recoverStaleProcessing();
        $this->info("Published {$published} invoice job(s); marked {$uncertain} interrupted attempt(s) for query.");

        return self::SUCCESS;
    }
}
