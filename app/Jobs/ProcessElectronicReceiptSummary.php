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
use App\Services\SunatBetaElectronicReceiptSummaryTransport;
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
        $transmissionStarted = false;
        $betaMode = false;
        try {
            abort_unless(app()->environment('testing', 'local'), 409, 'Daily receipt summary communication is disabled outside local QA.');
            $summary = ElectronicReceiptSummary::query()->with('items.document')->findOrFail($this->summaryId);
            $mode = (string) data_get($summary->snapshot, 'communication_mode', 'local_simulation');
            $betaMode = $mode === 'sunat_beta';
            abort_unless(in_array($mode, ['local_simulation', 'sunat_beta'], true), 409, 'The summary communication mode is not supported.');
            if ($betaMode) {
                abort_unless((bool) config('electronic_invoicing.receipt_beta.enabled') && $summary->environment === 'testing', 409,
                    'SUNAT Beta transport is disabled; preserve the confirmed summary without sending.');
            }
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
                    || ! $zip->addEmptyDir('dummy')
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
            $transport = $betaMode
                ? app(SunatBetaElectronicReceiptSummaryTransport::class)
                : app(ElectronicReceiptSummaryTransport::class);
            if ($betaMode) {
                $result = $transport->sendSummary($summary, $bytes, static function () use (&$transmissionStarted): void {
                    $transmissionStarted = true;
                });
            } else {
                $result = $transport->sendSummary($summary, $bytes);
            }
            app(ElectronicReceiptSummaryCommunicationService::class)->persistSubmission($summary->id, $this->attemptId, $result);
        } catch (\Throwable) {
            DB::transaction(function () use ($transmissionStarted, $betaMode): void {
                $summary = ElectronicReceiptSummary::query()->whereKey($this->summaryId)->lockForUpdate()->first();
                $attempt = ElectronicReceiptSummaryAttempt::query()->whereKey($this->attemptId)->lockForUpdate()->first();
                if (! $summary || ! $attempt || $summary->status !== 'processing') {
                    return;
                }
                $uncertainBeta = $betaMode && $transmissionStarted;
                $notSentBeta = $betaMode && ! $transmissionStarted;
                $status = $uncertainBeta ? 'beta_uncertain' : ($notSentBeta ? 'beta_not_sent' : 'local_preparation_failed');
                $code = $uncertainBeta ? 'beta_worker_interrupted_after_send_start' : ($notSentBeta ? 'beta_preflight_failed' : 'local_preparation_failed');
                $message = $uncertainBeta
                    ? 'El worker se interrumpió durante la comunicación Beta. Conserve ticket, numeración y evidencia; no reenvíe automáticamente.'
                    : ($notSentBeta ? 'La validación local previa impidió iniciar la solicitud Beta. No se envió a SUNAT; corrija la configuración antes de preparar un nuevo resumen.'
                        : 'No se pudo preparar el resumen firmado local. No se envió a SUNAT.');
                $summary->status = $status;
                $summary->communication_status = $uncertainBeta ? 'uncertain' : ($notSentBeta ? 'not_sent' : $summary->communication_status);
                $summary->result_code = $code;
                $summary->result_message = $message;
                $summary->save();
                $attempt->status = $status;
                $attempt->communication_status = $uncertainBeta ? 'uncertain' : ($notSentBeta ? 'not_sent' : $attempt->communication_status);
                $attempt->response_code = $code;
                $attempt->safe_observations = $message;
                $attempt->completed_at = $uncertainBeta ? null : now();
                $attempt->save();
                $items = ElectronicReceiptSummaryItem::query()->where('electronic_receipt_summary_id', $summary->id)
                    ->orderBy('electronic_document_id')->lockForUpdate()->get();
                foreach ($items as $item) {
                    ElectronicDocument::query()->whereKey($item->electronic_document_id)
                        ->whereIn('status', ['ready_for_summary', 'summary_pending', 'summary_uncertain'])
                        ->lockForUpdate()->update([
                            'status' => $uncertainBeta ? 'summary_uncertain' : 'summary_failed', 'result_code' => $code,
                            'result_message' => $message,
                            'updated_at' => now(),
                        ]);
                    $item->result_status = $uncertainBeta ? 'beta_uncertain' : ($notSentBeta ? 'beta_not_sent' : 'local_preparation_failed');
                    $item->result_code = $code;
                    $item->result_message = $message;
                    $item->save();
                }
                ElectronicReceiptSummaryEvent::query()->create([
                    'electronic_receipt_summary_id' => $summary->id, 'actor_id' => null,
                    'event_type' => $uncertainBeta ? 'beta_worker_interrupted' : ($notSentBeta ? 'beta_preflight_failed' : 'local_preparation_failed'),
                    'details' => ['mode' => $betaMode ? 'sunat_beta' : 'local_simulation', 'transmission_started' => $transmissionStarted], 'created_at' => now(),
                ]);
            });
            if (! $betaMode && $ownedPaths !== []) {
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
