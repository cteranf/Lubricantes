<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ElectronicDocumentResource;
use App\Models\ElectronicDocument;
use App\Models\Order;
use App\Services\ElectronicDocumentEmissionService;
use App\Services\ElectronicDocumentPdfRenderer;
use App\Services\ElectronicDocumentService;
use App\Services\ElectronicReceiptIssuanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BillingElectronicDocumentController extends Controller
{
    public function __construct(private ElectronicDocumentService $documents, private ElectronicDocumentEmissionService $emissions, private ElectronicReceiptIssuanceService $receipts, private ElectronicDocumentPdfRenderer $pdf) {}

    public function prepare(Request $request, int $order)
    {
        abort_if(array_diff(array_keys($request->all()), ['preparation_key', '_token']) !== [], 422, 'La solicitud contiene campos no permitidos.');
        $data = $request->validate(['preparation_key' => ['required', 'uuid']]);
        $document = $this->documents->prepare(Order::query()->findOrFail($order), $request->user(), $data['preparation_key']);

        return response()->json(['data' => $this->documentPayload($document)], 201);
    }

    public function status()
    {
        return response()->json(['data' => ['commercial_enabled' => $this->documents->enabled()]]);
    }

    public function show(int $id)
    {
        $document = ElectronicDocument::query()->with(['events.actor:id,name', 'attempts'])->findOrFail($id);

        return response()->json(['data' => $this->documentPayload($document)]);
    }

    public function update(Request $request, int $id)
    {
        abort_if(array_diff(array_keys($request->all()), ['version', 'seller', 'recipient', '_token']) !== [], 422, 'La solicitud contiene campos no permitidos.');
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'seller' => ['sometimes', 'array:commercial_name,legal_name,tax_id_type,tax_id_number,country_code,fiscal_address'],
            'seller.commercial_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'seller.legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seller.tax_id_type' => ['sometimes', 'nullable', 'string', 'max:20'],
            'seller.tax_id_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'seller.country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'seller.fiscal_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'recipient' => ['sometimes', 'array:document_type,document_number,name,billing_address,email'],
            'recipient.document_type' => ['sometimes', 'nullable', 'string', 'max:20'],
            'recipient.document_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'recipient.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'recipient.billing_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'recipient.email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);
        $document = $this->documents->updateDraft(
            ElectronicDocument::query()->findOrFail($id),
            $request->user(),
            $data['version'],
            $data['seller'] ?? [],
            $data['recipient'] ?? []
        );

        return response()->json(['data' => $this->documentPayload($document)]);
    }

    public function issue(Request $request, int $id)
    {
        abort_unless($this->documents->enabled(), 409, 'La facturación electrónica no está habilitada comercialmente.');
        abort_if(array_diff(array_keys($request->all()), ['version', '_token']) !== [], 422, 'La solicitud contiene campos no permitidos.');
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $document = ElectronicDocument::query()->findOrFail($id);
        if ($document->document_type === 'boleta') {
            $savedReceipt = $this->receipts->issue($document, $request->user(), (int) $data['version']);

            return response()->json(['message' => 'Boleta de prueba generada y firmada localmente. Aún no está incluida ni comunicada mediante un resumen.', 'data' => $this->documentPayload($savedReceipt)], 202);
        }
        if ($document->connection_type !== 'direct_sunat') {
            return response()->json(['code' => 'connection_not_implemented', 'message' => 'La conexión seleccionada todavía no está implementada. No se creó un intento.'], 503);
        }
        $saved = $this->emissions->confirm($document, $request->user(), (int) $data['version']);

        return response()->json(['message' => 'Documento confirmado para procesamiento en el ambiente de pruebas.', 'data' => (new ElectronicDocumentResource($saved))->resolve()], 202);
    }

    public function readiness(int $id)
    {
        $document = ElectronicDocument::query()->findOrFail($id);

        return response()->json(['data' => $this->readinessPayload($document)]);
    }

    public function query(Request $request, int $id)
    {
        abort_unless($this->documents->enabled(), 409, 'La facturación electrónica no está habilitada comercialmente.');
        abort_if(array_diff(array_keys($request->all()), ['_token']) !== [], 422, 'La solicitud contiene campos no permitidos.');
        $candidate = ElectronicDocument::query()->findOrFail($id);
        abort_if($candidate->environment === 'testing' && $candidate->connection_type === 'direct_sunat', 409, 'SUNAT Beta no ofrece consulta de estado para este envío síncrono. No se registró una consulta.');
        $document = $this->emissions->query($candidate, $request->user());

        return response()->json(['data' => $this->documentPayload($document)]);
    }

    public function download(int $id, string $type)
    {
        if ($type === 'pdf') {
            $document = ElectronicDocument::query()->findOrFail($id);
            $bytes = $this->pdf->renderTestingInvoice($document);
            $series = preg_replace('/[^A-Z0-9-]/i', '', (string) $document->series) ?: 'document';
            $number = str_pad((string) $document->document_number, 8, '0', STR_PAD_LEFT);

            return response($bytes, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.($document->document_type === 'boleta' ? 'SIMULACION-' : '').$series.'-'.$number.'-PRUEBA.pdf"',
                'Cache-Control' => 'private, no-store',
                ...($document->document_type === 'boleta' ? ['X-Receipt-Simulation' => 'local-only'] : []),
            ]);
        }

        $fields = ['xml' => 'xml_path', 'archive' => 'zip_path', 'cdr' => 'cdr_path'];
        abort_unless(isset($fields[$type]), 404);
        $document = ElectronicDocument::query()->findOrFail($id);
        $path = $document->{$fields[$type]};
        $extension = $type === 'archive' ? 'zip' : 'xml';
        abort_unless(is_string($path)
            && preg_match('#\Aelectronic-invoices/documents/'.preg_quote((string) $document->id, '#').'/[A-Za-z0-9._-]+\.'.preg_quote($extension, '#').'\z#D', $path) === 1
            && Storage::disk('local')->exists($path), 404);

        $series = preg_replace('/[^A-Z0-9-]/i', '', (string) $document->series) ?: 'document';
        $number = str_pad((string) $document->document_number, 8, '0', STR_PAD_LEFT);
        $suffix = $extension;
        $prefix = $type === 'cdr' ? 'R-' : ($document->document_type === 'boleta' ? 'SIMULACION-' : '');

        return Storage::disk('local')->download($path, $prefix.$series.'-'.$number.'.'.$suffix, [
            'Cache-Control' => 'private, no-store',
            ...($document->document_type === 'boleta' ? ['X-Receipt-Simulation' => 'local-only'] : []),
        ]);
    }

    /** @return array<string, mixed> */
    private function documentPayload(ElectronicDocument $document): array
    {
        return [
            ...(new ElectronicDocumentResource($document))->resolve(),
            'readiness' => $this->readinessPayload($document),
        ];
    }

    /** @return array{status: string, version: int, ready: bool, blocking_reasons: array<int, string>} */
    private function readinessPayload(ElectronicDocument $document): array
    {
        return [
            'status' => $document->status,
            'version' => (int) $document->version,
            ...$this->emissions->readiness($document),
        ];
    }
}
