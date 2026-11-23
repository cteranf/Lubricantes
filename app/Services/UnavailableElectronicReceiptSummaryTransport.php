<?php

namespace App\Services;

use App\Contracts\ElectronicReceiptSummaryTransport;
use App\Models\ElectronicReceiptSummary;

/** Fail-closed binding: no external receipt-summary transport is implemented. */
class UnavailableElectronicReceiptSummaryTransport implements ElectronicReceiptSummaryTransport
{
    public function sendSummary(ElectronicReceiptSummary $summary, string $zipBytes): array
    {
        throw new \RuntimeException('No existe un transporte externo implementado para resúmenes de boletas.');
    }

    public function query(string $localSimulationId, int $queryNumber): array
    {
        throw new \RuntimeException('No existe consulta externa implementada para resúmenes de boletas.');
    }
}
