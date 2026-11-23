<?php

namespace App\Services;

use App\Models\ElectronicDocument;
use App\Models\ElectronicDocumentAttempt;
use App\Models\ElectronicDocumentEvent;
use App\Models\ElectronicInvoiceSetting;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Numbers, freezes, signs and stores one boleta locally; it does not communicate it. */
class ElectronicReceiptIssuanceService
{
    public function __construct(
        private ElectronicInvoiceEligibility $eligibility,
        private ElectronicDocumentSeriesAllocator $seriesAllocator,
        private SunatUblInvoiceBuilder $builder,
        private SunatXmlSignatureService $signer,
        private SunatSchemaBundle $schemas,
    ) {}

    public function issue(ElectronicDocument $candidate, User $actor, int $expectedVersion): ElectronicDocument
    {
        abort_unless((bool) config('electronic_invoicing.enabled'), 409, 'La facturación electrónica no está habilitada comercialmente.');
        abort_unless(app()->environment('testing', 'local'), 409, 'La generación de boletas de prueba está deshabilitada fuera de QA local.');
        abort_unless($candidate->document_type === 'boleta', 409, 'Esta operación solo genera boletas.');

        $createdFiles = [];
        try {
            return DB::connection($candidate->getConnectionName())->transaction(function () use ($candidate, $actor, $expectedVersion, &$createdFiles): ElectronicDocument {
                $order = Order::query()->whereKey($candidate->order_id)->lockForUpdate()->firstOrFail();
                $document = ElectronicDocument::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                abort_unless((int) $document->order_id === (int) $order->id && $document->document_type === 'boleta', 409, 'La boleta ya no corresponde al pedido solicitado.');
                if ($document->status === 'ready_for_summary' && filled($document->xml_path) && Storage::disk('local')->exists($document->xml_path)) {
                    return $document->load(['events.actor:id,name', 'attempts']);
                }
                abort_unless($document->status === 'draft', 409, 'La boleta ya no es un borrador editable.');
                abort_if($document->attempts()->exists(), 409, 'La boleta ya tiene un intento de comunicación.');
                abort_unless((int) $document->version === $expectedVersion, 409, 'El borrador cambió. Carga la versión vigente antes de generar la boleta.');

                $siblings = ElectronicDocument::query()->where('order_id', $order->id)->whereKeyNot($document->id)->orderBy('id')->lockForUpdate()->get(['id', 'status']);
                $siblingIds = $siblings->modelKeys();
                $hasSiblingAttempt = $siblingIds !== [] && ElectronicDocumentAttempt::query()->whereIn('electronic_document_id', $siblingIds)->exists();
                abort_if($siblings->contains(static fn (ElectronicDocument $sibling): bool => $sibling->status !== 'draft') || $hasSiblingAttempt,
                    409, 'Ya existe otro documento emitido o en proceso para esta venta.');

                $reasons = $this->eligibility->reasons($document, $order);
                abort_if($reasons !== [], 409, 'La boleta requiere revisión antes de generar el documento: '.implode(', ', $reasons).'.');
                $settings = ElectronicInvoiceSetting::query()->where('singleton_key', 'primary')->lockForUpdate()->first();
                abort_unless(($settings?->environment ?? 'testing') === 'testing' && ($settings?->connection_type ?? 'direct_sunat') === 'direct_sunat', 409, 'La generación local solo admite configuración de pruebas.');
                abort_unless($settings && filled($settings->certificate_path) && Storage::disk('local')->exists($settings->certificate_path)
                    && $settings->certificate_expires_at?->isFuture() === true, 409, 'Configura un certificado vigente en almacenamiento privado antes de generar la boleta.');
                abort_unless(class_exists(\ZipArchive::class), 409, 'PHP requiere la extensión ZIP para generar la boleta.');
                abort_unless($this->schemas->isAvailable(), 409, 'Falta el XSD local configurado para validar el XML de boleta. No se asignó numeración.');

                $issuer = (string) data_get($document->seller_snapshot, 'tax_id_number');
                $reserved = $this->seriesAllocator->reserve(DB::connection($document->getConnectionName()), $issuer, 'testing', 'boleta', 'B001');
                $now = now();
                $document->series = $reserved['series'];
                $document->document_number = $reserved['document_number'];
                $document->status = 'generating';
                $document->confirmed_by = $actor->id;
                $document->confirmed_at = $now;
                $document->version++;
                $document->save();
                ElectronicDocumentEvent::query()->create([
                    'electronic_document_id' => $document->id, 'actor_id' => $actor->id,
                    'event_type' => 'numbered_and_frozen', 'version_from' => $document->version - 1,
                    'version_to' => $document->version, 'changed_fields' => ['series', 'document_number', 'snapshots_frozen'], 'created_at' => $now,
                ]);

                $xmlDocument = $this->builder->build($document);
                $xmlDocument->formatOutput = false;
                $password = (string) $settings->certificate_password_encrypted;
                $certificate = Storage::disk('local')->get((string) $settings->certificate_path);
                $xml = $this->signer->signAndVerify($xmlDocument, $certificate, $password,
                    (string) data_get($document->seller_snapshot, 'tax_id_number'), config('electronic_invoicing.openssl_config'));
                $serialized = new \DOMDocument;
                $serialized->resolveExternals = false;
                $serialized->substituteEntities = false;
                abort_unless($serialized->loadXML($xml, LIBXML_NONET)
                    && $this->signer->verifySerializedDocument($serialized, $this->certificate($certificate, $password))
                    && $this->schemas->validate($serialized), 409, 'El XML firmado de la boleta no pasó la validación XSD local.');

                $base = $document->series.'-'.str_pad((string) $document->document_number, 8, '0', STR_PAD_LEFT);
                $folder = 'electronic-invoices/documents/'.$document->id;
                $xmlPath = $folder.'/'.$base.'.xml';
                $zipPath = $folder.'/'.$base.'.zip';
                if (! Storage::disk('local')->put($xmlPath, $xml)) {
                    throw new \RuntimeException('El XML de la boleta no pudo guardarse en almacenamiento privado.');
                }
                $createdFiles[] = $xmlPath;
                $tmp = tempnam(sys_get_temp_dir(), 'receipt_xml_');
                if ($tmp === false) {
                    throw new \RuntimeException('No se pudo crear el archivo temporal del paquete de boleta.');
                }
                try {
                    $zip = new \ZipArchive;
                    if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true
                        || ! $zip->addFromString($base.'.xml', $xml) || ! $zip->close()) {
                        throw new \RuntimeException('No se pudo crear el ZIP privado de la boleta.');
                    }
                    $zipBytes = file_get_contents($tmp);
                    if (! is_string($zipBytes) || $zipBytes === '' || ! Storage::disk('local')->put($zipPath, $zipBytes)) {
                        throw new \RuntimeException('El ZIP de la boleta no pudo guardarse en almacenamiento privado.');
                    }
                    $createdFiles[] = $zipPath;
                } finally {
                    if (is_file($tmp)) {
                        unlink($tmp);
                    }
                }

                $document->xml_path = $xmlPath;
                $document->zip_path = $zipPath;
                $document->xml_hash = hash('sha256', $xml);
                $document->status = 'ready_for_summary';
                $document->result_code = 'LOCAL_XML_SIGNED_XSD_VALIDATED';
                $document->result_message = 'Documento individual firmado y validado localmente. Aún no fue incluido ni comunicado mediante un resumen.';
                $document->save();
                ElectronicDocumentEvent::query()->create([
                    'electronic_document_id' => $document->id, 'actor_id' => $actor->id,
                    'event_type' => 'local_xml_generated', 'version_from' => $document->version,
                    'version_to' => $document->version, 'changed_fields' => ['xml_path', 'zip_path', 'xml_hash', 'status'], 'created_at' => now(),
                ]);

                return $document->load(['events.actor:id,name', 'attempts']);
            });
        } catch (\Throwable $exception) {
            if ($createdFiles !== []) {
                Storage::disk('local')->delete($createdFiles);
            }
            throw $exception;
        }
    }

    private function certificate(string $pkcs12, string $password): string
    {
        $bundle = [];
        if (! openssl_pkcs12_read($pkcs12, $bundle, $password) || empty($bundle['cert'])) {
            throw new \RuntimeException('El certificado de firma no pudo inspeccionarse.');
        }

        return $bundle['cert'];
    }
}
