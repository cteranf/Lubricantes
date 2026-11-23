<?php

namespace App\Support;

final class ElectronicDocumentStatus
{
    public static function label(?string $status): string
    {
        return match ($status) {
            'draft' => 'Borrador; no emitido',
            'generating' => 'Generación local en curso',
            'ready_for_summary' => 'Boleta firmada y numerada; pendiente de resumen',
            'summary_pending' => 'Incluida en resumen; procesamiento simulado pendiente',
            'summary_uncertain' => 'Resultado simulado incierto; conserva numeración',
            'summary_failed' => 'Falló la simulación local; no fue comunicada a SUNAT',
            'reported' => 'Informada en resumen aceptado simulado localmente; no SUNAT',
            'summary_rejected' => 'Resumen rechazado en simulación; requiere revisión',
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
