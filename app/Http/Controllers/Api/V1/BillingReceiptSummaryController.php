<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ElectronicReceiptSummaryResource;
use App\Models\ElectronicDocument;
use App\Models\ElectronicInvoiceSetting;
use App\Models\ElectronicReceiptSummary;
use App\Services\ElectronicInvoiceEligibility;
use App\Services\ElectronicReceiptSummaryCommunicationService;
use App\Services\ElectronicReceiptSummaryService;
use App\Services\SunatSchemaBundle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class BillingReceiptSummaryController extends Controller
{
    public function __construct(private ElectronicReceiptSummaryService $summaries, private ElectronicReceiptSummaryCommunicationService $communication) {}

    public function eligible(ElectronicInvoiceEligibility $eligibility)
    {
        $settings = ElectronicInvoiceSetting::query()->where('singleton_key', 'primary')->first();
        $setupBlockers = [];
        if (! app()->environment('testing', 'local')) {
            $setupBlockers[] = 'local_qa_only';
        }
        if (! (bool) config('electronic_invoicing.enabled')) {
            $setupBlockers[] = 'commercial_module_disabled';
        }
        if (($settings?->environment ?? 'testing') !== 'testing' || ($settings?->connection_type ?? 'direct_sunat') !== 'direct_sunat') {
            $setupBlockers[] = 'testing_direct_mode_required';
        }
        if (! $settings || blank($settings->certificate_path) || ! Storage::disk('local')->exists((string) $settings->certificate_path)
            || $settings->certificate_expires_at?->isFuture() !== true) {
            $setupBlockers[] = 'signing_certificate_missing_or_expired';
        }
        if (! class_exists(\ZipArchive::class)) {
            $setupBlockers[] = 'php_zip_extension_missing';
        }
        $summaryXsd = (string) config('electronic_invoicing.sunat.summary_xsd');
        if (! filled($summaryXsd) || ! app(SunatSchemaBundle::class)->inspect($summaryXsd)['available']) {
            $setupBlockers[] = 'official_summary_schema_missing_or_invalid';
        }
        if (! Schema::hasTable('jobs')) {
            $setupBlockers[] = 'durable_queue_unavailable';
        }
        $defaultQueueConnection = (string) config('database.default');
        if ((config('queue.connections.database.connection') ?: $defaultQueueConnection) !== $defaultQueueConnection) {
            $setupBlockers[] = 'durable_queue_connection_mismatch';
        }
        $betaEnabled = (bool) config('electronic_invoicing.receipt_beta.enabled');
        if ($betaEnabled) {
            if (! app()->environment('testing', 'local')) {
                $setupBlockers[] = 'beta_transport_qa_only';
            }
            if (! filled(config('electronic_invoicing.receipt_beta.cdr_certificate_sha256'))) {
                $setupBlockers[] = 'beta_cdr_trust_pin_missing';
            }
            $cdrXsd = (string) config('electronic_invoicing.sunat.cdr_xsd');
            if (! filled($cdrXsd) || ! app(SunatSchemaBundle::class)->inspect($cdrXsd)['available']) {
                $setupBlockers[] = 'beta_cdr_schema_missing';
            }
        }
        $rows = ElectronicDocument::query()->with(['order:id,total,payment_status,created_at,requested_receipt_type', 'order.paymentSubmissions:id,order_id,amount,status'])
            ->where('document_type', 'boleta')->where('status', 'ready_for_summary')
            ->whereDoesntHave('attempts')
            ->whereDoesntHave('receiptSummaryItem')
            ->whereHas('order', fn ($query) => $query->where('payment_status', 'approved')->where('requested_receipt_type', 'boleta'))
            ->orderBy('confirmed_at')->orderBy('id')->limit(500)->get();

        return response()->json([
            'data' => $rows->map(function (ElectronicDocument $document) use ($eligibility, $setupBlockers): array {
                $reasons = $document->order ? $eligibility->reasons($document, $document->order) : ['order_missing'];
                $reasons = array_values(array_unique([...$setupBlockers, ...$reasons]));

                return [
                    'id' => (int) $document->id,
                    'version' => (int) $document->version,
                    'order_id' => (int) $document->order_id,
                    'order_number' => '#'.str_pad((string) $document->order_id, 6, '0', STR_PAD_LEFT),
                    'prepared_at' => $document->prepared_at?->toISOString(),
                    'series' => $document->series,
                    'document_number' => $document->document_number === null ? null : (int) $document->document_number,
                    'total' => data_get($document->amounts_snapshot, 'total'),
                    'tax_calculation_status' => $document->tax_calculation_status,
                    'files' => ['xml' => filled($document->xml_path), 'archive' => filled($document->zip_path), 'pdf' => true],
                    'eligible' => $reasons === [],
                    'blocking_reasons' => $reasons,
                ];
            })->values(),
            'meta' => [
                'preparation_available' => $setupBlockers === [],
                'blocking_reasons' => $setupBlockers,
                'communication_mode' => $betaEnabled ? 'sunat_beta' : 'local_simulation',
            ],
        ]);
    }

    public function index()
    {
        $page = ElectronicReceiptSummary::query()->withCount('items')->latest('id')->paginate(20);

        return response()->json([
            'data' => $page->getCollection()->map(fn (ElectronicReceiptSummary $summary): array => [
                'id' => (int) $summary->id, 'document_id' => $summary->document_id,
                'issue_date' => $summary->issue_date?->toDateString(), 'status' => $summary->status,
                'status_label' => (new ElectronicReceiptSummaryResource($summary))->resolve()['status_label'],
                'document_count' => (int) $summary->items_count,
                'local_simulation_id' => $summary->local_simulation_id,
                'external_ticket' => $summary->external_ticket,
                'communication_status' => $summary->communication_status,
                'communication_mode' => data_get($summary->snapshot, 'communication_mode', 'local_simulation'),
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request)
    {
        abort_if(array_diff(array_keys($request->all()), ['documents', '_token']) !== [], 422, 'La solicitud contiene campos no permitidos.');
        $data = $request->validate([
            'documents' => ['required', 'array', 'min:1', 'max:500'],
            'documents.*' => ['required', 'array:id,version'],
            'documents.*.id' => ['required', 'integer', 'min:1'],
            'documents.*.version' => ['required', 'integer', 'min:1'],
        ]);
        $summary = $this->summaries->confirm($data['documents'], $request->user());

        $mode = data_get($summary->snapshot, 'communication_mode', 'local_simulation');
        $message = $mode === 'sunat_beta'
            ? 'Resumen confirmado para el transporte de pruebas SUNAT Beta; aún no se ha procesado.'
            : 'Resumen preparado en modo local simulado. No se envió a SUNAT.';

        return response()->json(['message' => $message, 'data' => new ElectronicReceiptSummaryResource($summary)], 202);
    }

    public function show(int $id)
    {
        $summary = ElectronicReceiptSummary::query()->with(['items.document', 'attempts', 'events'])->findOrFail($id);

        return response()->json(['data' => new ElectronicReceiptSummaryResource($summary)]);
    }

    public function query(Request $request, int $id)
    {
        abort_if(array_diff(array_keys($request->all()), ['_token']) !== [], 422, 'La solicitud contiene campos no permitidos.');
        $summary = $this->communication->query($id, $request->user());

        $mode = data_get($summary->snapshot, 'communication_mode', 'local_simulation');

        return response()->json([
            'message' => $mode === 'sunat_beta' ? 'Respuesta de consulta Beta registrada; revise el tipo de resultado recibido.' : 'Consulta procesada por el adaptador local simulado; no se consultó SUNAT.',
            'data' => new ElectronicReceiptSummaryResource($summary),
        ]);
    }

    public function download(int $id, string $type)
    {
        abort_unless(in_array($type, ['xml', 'archive', 'cdr'], true), 404);
        $summary = ElectronicReceiptSummary::query()->findOrFail($id);
        $mode = data_get($summary->snapshot, 'communication_mode', 'local_simulation');
        abort_unless($type !== 'cdr' || $mode === 'sunat_beta', 404);
        $path = match ($type) {
            'xml' => $summary->xml_path,
            'archive' => $summary->zip_path,
            default => $summary->response_path,
        };
        $extension = $type === 'archive' ? 'zip' : 'xml';
        $expected = $type === 'cdr'
            ? '#\Aelectronic-invoices/summaries/'.preg_quote((string) $summary->id, '#').'/beta-CDR-[a-f0-9]{16}\.xml\z#D'
            : '#\Aelectronic-invoices/summaries/'.preg_quote((string) $summary->id, '#').'/[A-Za-z0-9._-]+\.'.preg_quote($extension, '#').'\z#D';
        abort_unless(is_string($path)
            && preg_match($expected, $path) === 1
            && Storage::disk('local')->exists($path), 404);

        $filename = match ($type) {
            'cdr' => 'CDR-SUNAT-BETA-'.$summary->document_id.'.xml',
            'xml', 'archive' => ($mode === 'local_simulation' ? 'SIMULACION-' : 'BETA-').$summary->document_id.'.'.$extension,
        };
        $headers = ['Cache-Control' => 'private, no-store'];
        if ($mode === 'local_simulation') {
            $headers['X-Receipt-Simulation'] = 'local-only';
        } else {
            $headers['X-Receipt-Environment'] = 'SUNAT-BETA-TESTING';
        }

        return Storage::disk('local')->download($path, $filename, $headers);
    }
}
