<?php

namespace App\Services;

use Illuminate\Database\Connection;

class ElectronicDocumentSeriesAllocator
{
    /**
     * Reserve the next number inside the caller's transaction. The row lock
     * serializes distinct documents sharing an issuer/type series.
     *
     * @return array{series: string, document_number: int}
     */
    public function reserve(Connection $connection, string $issuerTaxId, string $environment, string $documentType, string $defaultSeries): array
    {
        if ($connection->transactionLevel() < 1) {
            throw new \LogicException('Electronic document numbering requires an active transaction.');
        }

        $identity = [
            'issuer_tax_id' => $issuerTaxId,
            'environment' => $environment,
            'document_type' => $documentType,
        ];
        $now = now();
        $connection->table('electronic_document_series')->insertOrIgnore($identity + [
            'series' => $defaultSeries,
            'next_number' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $row = $connection->table('electronic_document_series')->where($identity)->lockForUpdate()->first();
        if (! $row) {
            throw new \RuntimeException('Electronic document series could not be locked.');
        }

        $number = (int) $row->next_number;
        if ($number < 1 || $number > 99999999) {
            throw new \DomainException('The configured document number exceeds the SUNAT limit.');
        }

        $connection->table('electronic_document_series')->where('id', $row->id)->update([
            'next_number' => $number + 1,
            'updated_at' => now(),
        ]);

        return ['series' => (string) $row->series, 'document_number' => $number];
    }
}
