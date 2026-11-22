<?php

namespace App\Support;

final class ElectronicDocumentStatus
{
    public static function label(?string $status): string
    {
        return match ($status) {
            'draft' => 'Borrador; no emitido',
            'queued' => 'Pendiente de procesamiento',
            'processing' => 'Procesando',
            'submitted' => 'Enviado; resultado pendiente',
            'uncertain' => 'Resultado incierto; requiere revisión manual',
            'accepted' => 'Aceptado en ambiente de pruebas',
            'accepted_observed' => 'Aceptado con observaciones en ambiente de pruebas',
            'rejected' => 'Rechazado por SUNAT pruebas',
            'sunat_exception' => 'Excepción SOAP de SUNAT; numeración conservada',
            default => 'Estado no disponible',
        };
    }
}
