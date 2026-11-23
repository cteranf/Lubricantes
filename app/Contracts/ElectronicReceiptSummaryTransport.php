<?php

namespace App\Contracts;

use App\Models\ElectronicReceiptSummary;

/** Transport contract; the only available implementation is local QA simulation. */
interface ElectronicReceiptSummaryTransport
{
    /** @return array<string, mixed> */
    public function sendSummary(ElectronicReceiptSummary $summary, string $zipBytes): array;

    /** @return array<string, mixed> */
    public function query(string $localSimulationId, int $queryNumber): array;
}
