<?php

namespace App\Services;

use App\Contracts\ElectronicInvoiceGateway;
use App\Models\ElectronicDocument;
use App\Models\ElectronicDocumentAttempt;
use App\Models\ElectronicDocumentEvent;
use App\Models\ElectronicDocumentOutbox;
use App\Models\ElectronicInvoiceSetting;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ElectronicDocumentEmissionService
{
    public function __construct(private ElectronicInvoiceEligibility $eligibility, private ElectronicDocumentSeriesAllocator $seriesAllocator, private SunatSchemaBundle $schemas) {}

    public function confirm(ElectronicDocument $candidate, User $actor, int $expectedVersion): ElectronicDocument
    {
        abort_unless((bool) config('electronic_invoicing.enabled'), 409, 'La facturación electrónica no está habilitada comercialmente.');

        $connection = DB::connection($candidate->getConnectionName());

        $document = $connection->transaction(function () use ($candidate, $actor, $expectedVersion, $connection): ElectronicDocument {
            // Serialize every issuance for a sale on the order row, matching draft preparation's lock order.
            $order = Order::query()->whereKey($candidate->order_id)->lockForUpdate()->firstOrFail();
            $document = ElectronicDocument::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $document->order_id === (int) $order->id, 409, 'El documento ya no corresponde al pedido solicitado.');
            if ($document->status !== 'draft') {
                return $document->load(['events.actor:id,name', 'attempts']);
            }
            abort_if($document->attempts()->exists(), 409, 'El documento ya tiene un intento y no puede confirmarse de nuevo.');
            abort_if((int) $document->version !== $expectedVersion, 409, 'El borrador cambió. Carga la versión vigente antes de confirmar.');

            $otherDocuments = ElectronicDocument::query()
                ->where('order_id', $order->id)
                ->whereKeyNot($document->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'status']);
            $otherDocumentIds = $otherDocuments->pluck('id');
            $hasOtherAttempt = $otherDocumentIds->isNotEmpty()
                && ElectronicDocumentAttempt::query()->whereIn('electronic_document_id', $otherDocumentIds)->exists();
            abort_if(
                $otherDocuments->contains(static fn (ElectronicDocument $other): bool => $other->status !== 'draft') || $hasOtherAttempt,
                409,
                'Ya existe otro documento electrónico con un intento para esta venta. No se puede emitir un segundo comprobante.'
            );

            $settings = ElectronicInvoiceSetting::query()->where('singleton_key', 'primary')->first();
            abort_unless(($settings?->connection_type ?? 'direct_sunat') === 'direct_sunat', 409, 'La conexión de proveedor todavía no está implementada.');
            abort_unless(($settings?->environment ?? 'testing') === 'testing', 409, 'Esta fase permite únicamente el ambiente de pruebas de SUNAT.');
            $reasons = $this->eligibility->reasons($document, $order);
            if (in_array('tax_snapshot_incomplete', $reasons, true)) {
                abort(409, 'El cálculo tributario está pendiente o requiere revisión. No se puede preparar una emisión hasta completar y validar el desglose.');
            }
            abort_if($reasons !== [], 409, 'El documento requiere revisión antes de enviarse: '.implode(', ', $reasons).'.');
            abort_unless($settings && filled($settings->certificate_path) && Storage::disk('local')->exists($settings->certificate_path)
                && $settings->certificate_expires_at?->isFuture() === true, 409, 'Configura un certificado de firma vigente en almacenamiento privado antes de confirmar.');
            abort_unless(class_exists(\ZipArchive::class), 409, 'El runtime PHP no tiene habilitada la extensión ZIP requerida.');
            abort_unless($this->schemas->isAvailable(), 409, 'Falta el esquema oficial UBL 2.1 completo o una dependencia local válida. No se asignó numeración.');
            $issuer = (string) data_get($document->seller_snapshot, 'tax_id_number');
            $prefix = $document->document_type === 'factura' ? 'F' : 'B';
            $defaultSeries = $prefix.'001';
            $reservedNumber = $this->seriesAllocator->reserve($connection, $issuer, 'testing', $document->document_type, $defaultSeries);
            $seriesCode = $reservedNumber['series'];
            $number = $reservedNumber['document_number'];

            $now = now();
            $document->series = $seriesCode;
            $document->document_number = $number;
            $document->status = 'queued';
            $document->confirmed_by = $actor->id;
            $document->confirmed_at = $now;
            $document->version++;
            $document->save();
            $attempt = ElectronicDocumentAttempt::query()->create([
                'electronic_document_id' => $document->id,
                'initiated_by' => $actor->id,
                'attempt_number' => 1,
                'connection_type' => 'direct_sunat',
                'environment' => 'testing',
                'idempotency_key' => (string) Str::uuid(),
                'payload_hash' => hash('sha256', json_encode([
                    $document->seller_snapshot, $document->recipient_snapshot,
                    $document->lines_snapshot, $document->amounts_snapshot,
                    $document->series, $document->document_number,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                'request_snapshot' => ['series' => $seriesCode, 'number' => $number, 'type' => $document->document_type],
                'status' => 'queued',
                'started_at' => $now,
            ]);
            ElectronicDocumentEvent::query()->create([
                'electronic_document_id' => $document->id, 'actor_id' => $actor->id,
                'event_type' => 'confirmed', 'version_from' => $document->version - 1,
                'version_to' => $document->version, 'changed_fields' => ['series', 'document_number'], 'created_at' => $now,
            ]);

            // Force a durable database queue. Never fall back to the application's sync default.
            ElectronicDocumentOutbox::query()->create(['electronic_document_attempt_id' => $attempt->id]);

            return $document->load(['events.actor:id,name', 'attempts']);
        });

        try {
            app(ElectronicDocumentJobOutboxPublisher::class)->publishPendingForDocument($document->id);
        } catch (\Throwable $exception) {
            Log::warning('Electronic invoice outbox remains pending for scheduled recovery.', ['document_id' => $document->id]);
        }

        return $document->fresh()->load(['events.actor:id,name', 'attempts']);
    }

    public function readiness(ElectronicDocument $candidate): array
    {
        $document = $candidate->loadMissing('order');
        $reasons = $this->eligibility->reasons($document, $document->order);
        $settings = ElectronicInvoiceSetting::query()->where('singleton_key', 'primary')->first();
        if (($settings?->connection_type ?? 'direct_sunat') !== 'direct_sunat') {
            $reasons[] = 'provider_adapter_unavailable';
        }
        if (($settings?->environment ?? 'testing') !== 'testing') {
            $reasons[] = 'test_environment_only';
        }
        if (! $settings || blank($settings->certificate_path) || ! Storage::disk('local')->exists((string) $settings->certificate_path)
            || $settings->certificate_expires_at?->isFuture() !== true) {
            $reasons[] = 'signing_certificate_missing_or_expired';
        }
        if (! class_exists(\ZipArchive::class)) {
            $reasons[] = 'php_zip_extension_missing';
        }
        if (! extension_loaded('openssl') || ! class_exists(\DOMDocument::class)) {
            $reasons[] = 'php_xml_or_openssl_extension_missing';
        }
        if (! Schema::hasTable('jobs')) {
            $reasons[] = 'durable_queue_unavailable';
        }
        if (! $this->schemas->isAvailable()) {
            $reasons[] = 'official_ubl_schema_missing_or_invalid';
        }
        if (! (bool) config('electronic_invoicing.enabled')) {
            $reasons[] = 'commercial_module_disabled';
        }

        return ['ready' => $reasons === [], 'blocking_reasons' => array_values(array_unique($reasons))];
    }

    public function query(ElectronicDocument $candidate, User $actor): ElectronicDocument
    {
        abort_unless(in_array($candidate->status, ['uncertain', 'submitted'], true), 409, 'Solo se pueden consultar intentos enviados o inciertos.');
        $attempt = $candidate->attempts()->latest('id')->firstOrFail();
        $result = app(ElectronicInvoiceGateway::class)->query($attempt);

        return DB::transaction(function () use ($candidate, $attempt, $result, $actor): ElectronicDocument {
            $document = ElectronicDocument::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $lockedAttempt = ElectronicDocumentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if (in_array($document->status, ['accepted', 'accepted_observed', 'rejected', 'sunat_exception'], true)) {
                return $document->load(['events.actor:id,name', 'attempts']);
            }
            $status = in_array($result['status'] ?? null, ['accepted', 'accepted_observed', 'rejected', 'uncertain', 'submitted', 'sunat_exception'], true) ? $result['status'] : 'uncertain';
            $document->status = $status;
            $document->result_code = $result['response_code'] ?? 'result_unavailable';
            $document->result_message = mb_substr((string) ($result['message'] ?? 'Resultado no disponible.'), 0, 1000);
            if (isset($result['cdr_path'])) {
                $document->cdr_path = $result['cdr_path'];
            }
            if (in_array($status, ['accepted', 'accepted_observed'], true) && ! $document->accepted_at) {
                $document->accepted_at = now();
            }
            $document->save();
            $lockedAttempt->status = $status;
            $lockedAttempt->response_code = $result['response_code'] ?? null;
            $lockedAttempt->safe_observations = isset($result['observations']) ? mb_substr((string) $result['observations'], 0, 2000) : null;
            $lockedAttempt->completed_at = in_array($status, ['uncertain', 'submitted'], true) ? null : now();
            $lockedAttempt->save();
            ElectronicDocumentEvent::query()->create([
                'electronic_document_id' => $document->id, 'actor_id' => $actor->id, 'event_type' => 'status_queried',
                'version_from' => $document->version, 'version_to' => $document->version,
                'changed_fields' => ['status', 'result_code'], 'created_at' => now(),
            ]);

            return $document->load(['events.actor:id,name', 'attempts']);
        });
    }
}
