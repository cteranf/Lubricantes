<?php

namespace App\Services;

use App\Models\ElectronicDocument;
use App\Models\ElectronicDocumentAttempt;
use App\Models\ElectronicDocumentEvent;
use App\Models\ElectronicDocumentSeries;
use App\Models\ElectronicInvoiceSetting;
use App\Models\ElectronicReceiptSummary;
use App\Models\ElectronicReceiptSummaryAttempt;
use App\Models\ElectronicReceiptSummaryEvent;
use App\Models\ElectronicReceiptSummaryItem;
use App\Models\ElectronicReceiptSummaryOutbox;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Freezes one day's selected boletas into an immutable, locally simulated summary. */
class ElectronicReceiptSummaryService
{
    public function __construct(private ElectronicInvoiceEligibility $eligibility, private SunatSchemaBundle $schemas) {}

    /** @param array<int, array{id:int,version:int}> $selection */
    public function confirm(array $selection, User $actor): ElectronicReceiptSummary
    {
        abort_unless((bool) config('electronic_invoicing.enabled'), 409, 'La facturación electrónica no está habilitada comercialmente.');
        abort_unless(app()->environment('testing', 'local'), 409, 'La comunicación de boletas permanece bloqueada fuera de QA local.');
        abort_if($selection === [] || count($selection) > 500, 422, 'Selecciona entre 1 y 500 boletas para el resumen diario.');
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $selection);
        abort_if(count(array_unique($ids)) !== count($ids), 422, 'La selección contiene boletas duplicadas.');
        $defaultQueueConnection = (string) config('database.default');
        $queueDatabaseConnection = config('queue.connections.database.connection') ?: $defaultQueueConnection;
        abort_unless($queueDatabaseConnection === $defaultQueueConnection && Schema::hasTable('jobs'), 409,
            'La cola durable de facturación debe compartir la conexión transaccional de la aplicación.');
        $versions = collect($selection)->mapWithKeys(static fn (array $row): array => [(int) $row['id'] => (int) $row['version']]);

        $connection = DB::connection();
        $summary = $connection->transaction(function () use ($ids, $versions, $actor): ElectronicReceiptSummary {
            // Canonical lock order: all orders by id, then all documents by id, then series.
            $candidateOrderIds = ElectronicDocument::query()->whereIn('id', $ids)->orderBy('order_id')->orderBy('id')->pluck('order_id')->unique()->values();
            abort_if($candidateOrderIds->count() !== count($ids), 409, 'Solo se admite una boleta por pedido en un resumen diario.');
            $orders = Order::query()->with(['paymentSubmissions:id,order_id,amount,status'])
                ->whereIn('id', $candidateOrderIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $documents = ElectronicDocument::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($documents->count() === count($ids) && $orders->count() === $candidateOrderIds->count(), 404, 'Una o más boletas ya no están disponibles.');
            $selectedIds = $documents->keys()->map(fn ($id): int => (int) $id)->all();
            $selectedOrderIds = $candidateOrderIds->map(fn ($id): int => (int) $id)->all();
            // These are locking reads: on MySQL REPEATABLE READ a plain SELECT
            // here could reuse a snapshot created before waiting for the order
            // lock and miss an inclusion committed by the winning transaction.
            $attemptedDocumentIds = ElectronicDocumentAttempt::query()->whereIn('electronic_document_id', $selectedIds)->orderBy('electronic_document_id')->lockForUpdate()->pluck('electronic_document_id')->map(fn ($id): int => (int) $id)->all();
            $summarizedDocumentIds = ElectronicReceiptSummaryItem::query()->whereIn('electronic_document_id', $selectedIds)->orderBy('electronic_document_id')->lockForUpdate()->pluck('electronic_document_id')->map(fn ($id): int => (int) $id)->all();
            $siblings = ElectronicDocument::query()->whereIn('order_id', $selectedOrderIds)->whereNotIn('id', $selectedIds)->orderBy('id')->lockForUpdate()->get(['id', 'order_id', 'status']);
            $siblingIds = $siblings->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $attemptedSiblingIds = $siblingIds === [] ? [] : ElectronicDocumentAttempt::query()->whereIn('electronic_document_id', $siblingIds)->orderBy('electronic_document_id')->lockForUpdate()->pluck('electronic_document_id')->map(fn ($id): int => (int) $id)->all();
            abort_if($siblings->contains(fn (ElectronicDocument $sibling): bool => $sibling->status !== 'draft' || in_array((int) $sibling->id, $attemptedSiblingIds, true)), 409,
                'Ya existe otro documento electrónico con un intento para una venta seleccionada.');

            $settings = ElectronicInvoiceSetting::query()->where('singleton_key', 'primary')->lockForUpdate()->first();
            abort_unless(($settings?->environment ?? 'testing') === 'testing', 409, 'La numeración de producción no está habilitada.');
            abort_unless(($settings?->connection_type ?? 'direct_sunat') === 'direct_sunat', 409, 'La comunicación configurada todavía no está disponible.');
            abort_unless($settings && filled($settings->certificate_path) && Storage::disk('local')->exists($settings->certificate_path)
                && $settings->certificate_expires_at?->isFuture() === true, 409, 'Configura un certificado vigente antes de preparar el resumen.');
            abort_unless(class_exists(\ZipArchive::class), 409, 'PHP no tiene habilitada la extensión ZIP requerida.');
            $summaryXsd = (string) config('electronic_invoicing.sunat.summary_xsd');
            abort_unless(filled($summaryXsd) && $this->schemas->inspect($summaryXsd)['available'], 409,
                'Falta configurar el XSD oficial completo del resumen diario. No se asignó numeración.');

            $now = now();
            $issuer = null;
            $frozen = [];
            foreach ($documents as $id => $document) {
                $order = $orders->get($document->order_id);
                abort_unless($order && $document->document_type === 'boleta' && $order->requested_receipt_type === 'boleta', 409, 'Solo se admiten boletas solicitadas para la venta correspondiente.');
                abort_unless($document->status === 'ready_for_summary'
                    && filled($document->series) && (int) $document->document_number > 0 && filled($document->xml_path)
                    && Storage::disk('local')->exists((string) $document->xml_path)
                    && ! in_array((int) $document->id, $attemptedDocumentIds, true)
                    && ! in_array((int) $document->id, $summarizedDocumentIds, true), 409, 'La boleta ya no es un borrador disponible.');
                abort_unless((int) $document->version === $versions->get((int) $id), 409, 'Una boleta cambió. Carga la versión vigente antes de confirmar.');
                $reasons = $this->eligibility->reasons($document, $order);
                abort_if($reasons !== [], 409, 'La boleta requiere revisión antes de incluirse: '.implode(', ', $reasons).'.');
                $documentIssuer = (string) data_get($document->seller_snapshot, 'tax_id_number');
                abort_if($issuer !== null && $issuer !== $documentIssuer, 409, 'Un resumen diario solo puede contener documentos del mismo emisor.');
                $issuer = $documentIssuer;
                $frozen[] = [
                    'document_id' => (int) $document->id,
                    'order_id' => (int) $order->id,
                    'series' => $document->series,
                    'document_number' => (int) $document->document_number,
                    'document_type' => '03',
                    'recipient_snapshot' => $document->recipient_snapshot,
                    'amounts_snapshot' => $document->amounts_snapshot,
                    'seller_snapshot' => $document->seller_snapshot,
                    'confirmed_at' => $now->toISOString(),
                ];
            }
            abort_unless(is_string($issuer) && preg_match('/\A\d{11}\z/', $issuer) === 1, 409, 'El RUC emisor de las boletas no es válido.');

            // Individual issuance already assigned/froze the B-series and signed XML.
            // Summary confirmation only includes those immutable documents.
            DB::table('electronic_document_series')->insertOrIgnore([
                'issuer_tax_id' => $issuer, 'environment' => 'testing', 'document_type' => 'daily_summary',
                'series' => 'RC', 'next_number' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $sequenceRow = ElectronicDocumentSeries::query()->where('issuer_tax_id', $issuer)
                ->where('environment', 'testing')->where('document_type', 'daily_summary')->lockForUpdate()->firstOrFail();
            $sequence = (int) $sequenceRow->next_number;
            abort_if($sequence < 1 || $sequence > 99999, 409, 'La numeración de resúmenes diarios superó el límite SUNAT.');
            $sequenceRow->next_number = $sequence + 1;
            $sequenceRow->save();
            $date = $now->toDateString();
            $documentId = 'RC-'.$now->format('Ymd').'-'.$sequence;
            $batch = ElectronicReceiptSummary::query()->create([
                'issuer_tax_id' => $issuer, 'environment' => 'testing', 'issue_date' => $date, 'sequence' => $sequence,
                'document_id' => $documentId, 'status' => 'queued', 'document_count' => count($frozen),
                'snapshot' => ['documents' => $frozen, 'communication_mode' => 'local_simulation', 'source' => 'locked_electronic_document_snapshots'],
                'confirmed_by' => $actor->id, 'confirmed_at' => $now,
            ]);
            ElectronicReceiptSummaryEvent::query()->create([
                'electronic_receipt_summary_id' => $batch->id, 'actor_id' => $actor->id,
                'event_type' => 'confirmed_for_local_simulation',
                'details' => ['document_count' => count($frozen), 'environment' => 'testing'], 'created_at' => $now,
            ]);
            foreach ($documents->values() as $index => $document) {
                ElectronicReceiptSummaryItem::query()->create([
                    'electronic_receipt_summary_id' => $batch->id, 'electronic_document_id' => $document->id,
                    'line_number' => $index + 1, 'result_status' => 'queued',
                ]);
                ElectronicDocumentEvent::query()->create([
                    'electronic_document_id' => $document->id, 'actor_id' => $actor->id,
                    'event_type' => 'included_in_receipt_summary', 'version_from' => $document->version,
                    'version_to' => $document->version,
                    'changed_fields' => ['summary_id' => $batch->id, 'series' => $document->series, 'document_number' => $document->document_number],
                    'created_at' => $now,
                ]);
            }
            $attempt = ElectronicReceiptSummaryAttempt::query()->create([
                'electronic_receipt_summary_id' => $batch->id, 'attempt_number' => 1,
                'idempotency_key' => (string) Str::uuid(),
                'payload_hash' => hash('sha256', json_encode($frozen, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                'request_snapshot' => ['document_id' => $documentId, 'document_count' => count($frozen), 'mode' => 'local_simulation'],
                'status' => 'queued', 'started_at' => $now,
            ]);
            ElectronicReceiptSummaryOutbox::query()->create(['electronic_receipt_summary_attempt_id' => $attempt->id]);

            return $batch->load(['items.document', 'attempts', 'events']);
        });

        try {
            app(ElectronicReceiptSummaryOutboxPublisher::class)->publishPending($summary->id);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $summary->fresh()->load(['items.document', 'attempts', 'events']);
    }
}
