<?php

namespace App\Services;

use App\Models\ElectronicDocument;
use Barryvdh\DomPDF\Facade\Pdf;

class ElectronicDocumentPdfRenderer
{
    public function renderTestingInvoice(ElectronicDocument $document): string
    {
        $allowedStatus = $document->document_type === 'boleta'
            ? in_array($document->status, ['ready_for_summary', 'summary_pending', 'summary_uncertain', 'summary_failed', 'reported', 'summary_rejected'], true) && filled($document->xml_path)
            : in_array($document->status, ['accepted', 'accepted_observed', 'rejected'], true);
        abort_unless(in_array($document->document_type, ['factura', 'boleta'], true)
            && $allowedStatus
            && $document->environment === 'testing'
            && filled($document->series) && filled($document->document_number), 404);

        return Pdf::loadView('electronic-invoices.testing-invoice', [
            'document' => $document,
            'seller' => $document->seller_snapshot ?? [],
            'recipient' => $document->recipient_snapshot ?? [],
            'items' => data_get($document->lines_snapshot, 'items', []),
            'tax' => data_get($document->amounts_snapshot, 'tax_snapshot', []),
            'amounts' => $document->amounts_snapshot ?? [],
            'statusLabel' => $document->document_type === 'boleta' ? 'Firmada localmente; pendiente de resumen' : $this->statusLabel($document->status),
        ])->setPaper('a4')->setOption([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isHtml5ParserEnabled' => true,
        ])->output();
    }

    private function statusLabel(string $status): string
    {
        return [
            'accepted' => 'Aceptada en ambiente de pruebas',
            'accepted_observed' => 'Aceptada con observaciones en ambiente de pruebas',
            'rejected' => 'Rechazada en ambiente de pruebas',
        ][$status] ?? 'Estado no disponible';
    }
}
