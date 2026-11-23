<?php

namespace App\Services;

use App\Contracts\ElectronicReceiptSummaryTransport;
use App\Models\ElectronicReceiptSummary;
use Illuminate\Support\Str;

/** Deterministic adapter for QA; it never opens a socket or creates a CDR. */
class LocalSimulatedElectronicReceiptSummaryTransport implements ElectronicReceiptSummaryTransport
{
    private const SCENARIOS = ['pending', 'accepted', 'accepted_observed', 'rejected', 'soap_fault', 'http_error', 'timeout_before_ticket', 'timeout_after_ticket', 'duplicate_response', 'late_response'];

    public function sendSummary(ElectronicReceiptSummary $summary, string $zipBytes): array
    {
        $this->assertQaOnly();
        abort_if($zipBytes === '', 422, 'El paquete simulado está vacío.');
        $scenario = $this->scenario();
        if ($scenario === 'soap_fault') {
            return $this->response('soap_fault', 'SIMULATED_SOAP_FAULT', 'Falla SOAP simulada localmente; no hubo llamada a SUNAT.');
        }
        if ($scenario === 'http_error') {
            return $this->response('http_error', 'SIMULATED_HTTP_503', 'Error HTTP simulado localmente; no hubo llamada a SUNAT.');
        }
        if ($scenario === 'timeout_before_ticket') {
            return $this->response('timeout', 'SIMULATED_TIMEOUT_BEFORE_TICKET', 'Timeout simulado antes de obtener identificador; no se reintentará el envío.');
        }

        $simulationId = 'LOCAL-SIM-'.Str::upper(Str::random(20));
        if ($scenario === 'timeout_after_ticket') {
            return $this->response('timeout_with_ticket', 'SIMULATED_TIMEOUT_AFTER_TICKET', 'Timeout simulado después de persistir el identificador local; solo se permite consulta simulada.', [
                'local_simulation_id' => $simulationId,
            ]);
        }

        return $this->response('ticket', 'LOCAL_SIM_TICKET_CREATED', 'Identificador sintético local. No es un ticket SUNAT ni hubo transmisión externa.', [
            'local_simulation_id' => $simulationId,
        ]);
    }

    public function query(string $localSimulationId, int $queryNumber): array
    {
        $this->assertQaOnly();
        abort_unless(preg_match('/\ALOCAL-SIM-[A-Z0-9]{20}\z/', $localSimulationId) === 1, 404, 'Solo se pueden consultar identificadores sintéticos locales.');
        $scenario = $this->scenario();
        if ($scenario === 'late_response' && $queryNumber < 2) {
            return $this->response('pending', 'SIMULATED_PROCESSING_PENDING', 'Procesamiento simulado pendiente.');
        }
        if ($scenario === 'timeout_after_ticket' && $queryNumber < 2) {
            return $this->response('pending', 'SIMULATED_PROCESSING_PENDING', 'El identificador local existe; el resultado simulado aún está pendiente.');
        }
        if ($scenario === 'pending') {
            return $this->response('pending', 'SIMULATED_PROCESSING_PENDING', 'Procesamiento simulado pendiente.');
        }
        if ($scenario === 'soap_fault') {
            return $this->response('soap_fault', 'SIMULATED_QUERY_SOAP_FAULT', 'Falla SOAP simulada durante la consulta local.');
        }
        if ($scenario === 'http_error') {
            return $this->response('http_error', 'SIMULATED_QUERY_HTTP_ERROR', 'Error HTTP simulado durante la consulta local.');
        }
        if ($scenario === 'accepted_observed') {
            return $this->response('accepted_observed', 'SIMULATED_ACCEPTED_WITH_OBSERVATIONS', 'Aceptación con observaciones simulada; no es respuesta SUNAT.');
        }
        if ($scenario === 'rejected') {
            return $this->response('rejected', 'SIMULATED_REJECTED', 'Rechazo simulado; no es respuesta SUNAT.');
        }
        if ($scenario === 'duplicate_response') {
            return $this->response('accepted', 'SIMULATED_ACCEPTED_DUPLICATE', 'Aceptación simulada duplicada para verificar idempotencia.', ['duplicate' => true]);
        }

        return $this->response('accepted', 'SIMULATED_ACCEPTED', 'Aceptación simulada local; no es respuesta SUNAT.');
    }

    private function scenario(): string
    {
        $scenario = (string) config('electronic_invoicing.receipt_simulation.scenario', 'pending');
        abort_unless(in_array($scenario, self::SCENARIOS, true), 409, 'El escenario de simulación local no está permitido.');

        return $scenario;
    }

    private function assertQaOnly(): void
    {
        abort_unless(app()->environment('testing', 'local'), 409, 'El transporte simulado está deshabilitado fuera de QA local.');
    }

    private function response(string $type, string $code, string $message, array $extra = []): array
    {
        return ['simulated' => true, 'type' => $type, 'code' => $code, 'message' => $message, ...$extra];
    }
}
