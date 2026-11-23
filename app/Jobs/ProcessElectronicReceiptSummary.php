<?php

namespace App\Jobs;

use App\Contracts\ElectronicReceiptSummaryTransport;
use App\Models\ElectronicDocument;
use App\Models\ElectronicInvoiceSetting;
use App\Models\ElectronicReceiptSummary;
use App\Models\ElectronicReceiptSummaryAttempt;
use App\Models\ElectronicReceiptSummaryEvent;
use App\Models\ElectronicReceiptSummaryItem;
use App\Services\ElectronicReceiptSummaryCommunicationService;
use App\Services\SunatSchemaBundle;
use App\Services\SunatUblReceiptSummaryBuilder;
use App\Services\SunatXmlSignatureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Packages a summary and creates a clearly synthetic local ticket; it never connects to SUNAT. */
class ProcessElectronicReceiptSummary implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $summaryId, public int $attemptId) {}

    public function handle(SunatUblReceiptSummaryBuilder $builder, SunatXmlSignatureService $signer, SunatSchemaBundle $schemas): void
    {
        $claimed = DB::transaction(function (): bool {
            $summary = ElectronicReceiptSummary::query()->whereKey($this->summaryId)->lockForUpdate()->first();
            $attempt = ElectronicReceiptSummaryAttempt::query()->whereKey($this->attemptId)->lockForUpdate()->first();
            if (! $summary || ! $attempt || (int) $attempt->electronic_receipt_summary_id !== (int) $summary->id
                || $summary->status !== 'queued' || $attempt->status !== 'queued') {
                return false;
            }
            $summary->status = 'processing';
            $summary->save();
            $attempt->status = 'processing';
            $attempt->save();

            return true;
        });
        if (! $claimed) {
            return;
        }

        $ownedPaths = [];
        try {
            abort_unless(app()->environment('testing', 'local'), 409, 'Daily receipt summary communication is disabled outside local QA.');
            $summary = ElectronicReceiptSummary::query()->with('items.document')->findOrFail($this->summaryId);
            $settings = ElectronicInvoiceSetting::query()->where('singleton_key', 'primary')->firstOrFail();
            $certificate = $settings->certificate_path ? Storage::disk('local')->get($settings->certificate_path) : null;
            if (! is_string($certificate) || $certificate === '') {
                throw new \RuntimeException('A private test signing certificate is unavailable.');
            }
            $xmlDocument = $builder->build($summary);
            $xmlDocument->formatOutput = false;
            $xml = $signer->signAndVerify($xmlDocument, $certificate, (string) $settings->certificate_password_encrypted,
                $summary->issuer_tax_id, config('electronic_invoicing.openssl_config'));
            $serialized = new \DOMDocument;
            $serialized->resolveExternals = false;
            $serialized->substituteEntities = false;
            if (! $serialized->loadXML($xml, LIBXML_NONET)
                || ! $signer->verifySerializedDocument($serialized, $this->certificate($certificate, (string) $settings->certificate_password_encrypted))) {
                throw new \RuntimeException('The signed daily summary failed its serialization signature check.');
            }
            $summaryXsd = (string) config('electronic_invoicing.sunat.summary_xsd');
            if (! $schemas->validateWith($serialized, $summaryXsd)) {
                throw new \RuntimeException('The configured SUNAT daily summary XSD validation failed.');
            }
            $base = $summary->issuer_tax_id.'-'.$summary->document_id;
            $folder = 'electronic-invoices/summaries/'.$summary->id;
            $xmlPath = $folder.'/'.$base.'.xml';
            $zipPath = $folder.'/'.$base.'.zip';
            $ownedPaths = [$xmlPath, $zipPath];
            if (! Storage::disk('local')->put($xmlPath, $xml)) {
                throw new \RuntimeException('Signed receipt summary XML could not be stored privately.');
            }
            $tmp = tempnam(sys_get_temp_dir(), 'receipt_summary_');
            if ($tmp === false) {
                throw new \RuntimeException('Summary ZIP temporary storage is unavailable.');
            }
            try {
                $zip = new \ZipArchive;
                if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true
                    || ! $zip->addFromString($base.'.xml', $xml) || ! $zip->close()) {
                    throw new \RuntimeException('The daily summary ZIP could not be created.');
                }
                $bytes = file_get_contents($tmp);
                if (! is_string($bytes) || $bytes === '' || ! Storage::disk('local')->put($zipPath, $bytes)) {
                    throw new \RuntimeException('The daily summary ZIP could not be stored privately.');
                }
            } finally {
                if (is_file($tmp)) {
                    unlink($tmp);
                }
            }
            $summary->xml_path = $xmlPath;
            $summary->zip_path = $zipPath;
            $summary->xml_hash = hash('sha256', $xml);
            $summary->save();
            $result = app(ElectronicReceiptSummaryTransport::class)->sendSummary($summary, $bytes);
            app(ElectronicReceiptSummaryCommunicationService::class)->persistSubmission($summary->id, $this->attemptId, $result);
        } catch (\Throwable) {
            DB::transaction(function (): void {
                $summary = ElectronicReceiptSummary::query()->whereKey($this->summaryId)->lockForUpdate()->first();
                $attempt = ElectronicReceiptSummaryAttempt::query()->whereKey($this->attemptId)->lockForUpdate()->first();
                if (! $summary || ! $attempt || $summary->status !== 'processing') {
                    return;
                }
                $summary->status = 'local_preparation_failed';
                $summary->result_code = 'local_preparation_failed';
                $summary->result_message = 'No se pudo preparar el resumen firmado local. No se envió a SUNAT.';
                $summary->save();
                $attempt->status = 'local_preparation_failed';
                $attempt->response_code = 'local_preparation_failed';
                $attempt->safe_observations = 'Falló la preparación local. No hubo transmisión a SUNAT; revise certificado, XSD y almacenamiento.';
                $attempt->completed_at = now();
                $attempt->save();
                $items = ElectronicReceiptSummaryItem::query()->where('electronic_receipt_summary_id', $summary->id)
                    ->orderBy('electronic_document_id')->lockForUpdate()->get();
                foreach ($items as $item) {
                    ElectronicDocument::query()->whereKey($item->electronic_document_id)
                        ->whereIn('status', ['ready_for_summary', 'summary_pending', 'summary_uncertain'])
                        ->lockForUpdate()->update([
                            'status' => 'summary_failed', 'result_code' => 'local_preparation_failed',
                            'result_message' => 'Falló la preparación local del resumen; conserve la numeración y revise el historial.',
                            'updated_at' => now(),
                        ]);
                    $item->result_status = 'local_preparation_failed';
                    $item->result_code = 'local_preparation_failed';
                    $item->result_message = 'Falló el procesamiento local; no se confirmó comunicación.';
                    $item->save();
                }
                ElectronicReceiptSummaryEvent::query()->create([
                    'electronic_receipt_summary_id' => $summary->id, 'actor_id' => null,
                    'event_type' => 'local_preparation_failed', 'details' => ['mode' => 'local_simulation'], 'created_at' => now(),
                ]);
            });
            if ($ownedPaths !== []) {
                try {
                    Storage::disk('local')->delete($ownedPaths);
                } catch (\Throwable) {
                    // The fixed per-summary folder remains private; status and history take precedence over cleanup.
                }
            }
        }
    }

    private function certificate(string $pkcs12, string $password): string
    {
        $bundle = [];
        if (! openssl_pkcs12_read($pkcs12, $bundle, $password) || empty($bundle['cert'])) {
            throw new \RuntimeException('The synthetic signing certificate could not be inspected.');
        }

        return $bundle['cert'];
    }
}
