<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ElectronicDocumentResource;
use App\Models\ElectronicDocument;
use App\Models\Order;
use App\Services\ElectronicDocumentService;
use Illuminate\Http\Request;

class BillingElectronicDocumentController extends Controller
{
    public function __construct(private ElectronicDocumentService $documents) {}

    public function prepare(Request $request, int $order)
    {
        abort_if(array_diff(array_keys($request->all()), ['preparation_key', '_token']) !== [], 422, 'La solicitud contiene campos no permitidos.');
        $data = $request->validate(['preparation_key' => ['required', 'uuid']]);
        $document = $this->documents->prepare(Order::query()->findOrFail($order), $request->user(), $data['preparation_key']);

        return response()->json(['data' => (new ElectronicDocumentResource($document))->resolve()], 201);
    }

    public function status()
    {
        return response()->json(['data' => ['commercial_enabled' => $this->documents->enabled()]]);
    }

    public function show(int $id): ElectronicDocumentResource
    {
        return new ElectronicDocumentResource(ElectronicDocument::query()->with('events.actor:id,name')->findOrFail($id));
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

        return response()->json(['data' => (new ElectronicDocumentResource($document))->resolve()]);
    }

    public function issue(int $id)
    {
        abort_unless($this->documents->enabled(), 409, 'La facturación electrónica no está habilitada comercialmente.');
        $document = ElectronicDocument::query()->findOrFail($id);
        abort_unless($document->tax_calculation_status === 'complete', 409, 'El cálculo tributario está pendiente o requiere revisión. No se puede preparar una emisión hasta completar y validar el desglose.');

        return response()->json([
            'code' => 'connection_not_implemented',
            'message' => 'La conexión seleccionada no está implementada; el documento permanece como borrador y no se envió.',
        ], 503);
    }
}
