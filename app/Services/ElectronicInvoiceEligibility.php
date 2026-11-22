<?php

namespace App\Services;

use App\Models\ElectronicDocument;
use App\Models\Order;

class ElectronicInvoiceEligibility
{
    public function reasons(ElectronicDocument $document, Order $order): array
    {
        $reasons = [];
        $amounts = $document->amounts_snapshot ?? [];
        $tax = $amounts['tax_snapshot'] ?? [];
        $lines = $document->lines_snapshot['items'] ?? [];

        if ($document->tax_calculation_status !== 'complete' || ($amounts['missing_fields'] ?? []) !== [] || ($tax['status'] ?? null) !== 'complete') {
            $reasons[] = 'tax_snapshot_incomplete';
        }
        if (in_array('shipping_mixed_affectations', $tax['missing_fields'] ?? [], true)) {
            $reasons[] = 'shipping_mixed_affectations';
        }
        if (in_array('shipping_treatment_unresolved', $tax['missing_fields'] ?? [], true)) {
            $reasons[] = 'shipping_treatment_unresolved';
        }
        if ($lines === []) {
            $reasons[] = 'lines_missing';
        }
        foreach ($lines as $line) {
            $lineTax = $line['tax'] ?? [];
            if (($lineTax['calculation_status'] ?? null) !== 'complete'
                || ! in_array($lineTax['tax_affectation']['code'] ?? null, ['10', '20', '30'], true)
                || ($line['quantity'] ?? 0) < 1
                || ($line['unit_price'] ?? null) === null) {
                $reasons[] = 'line_snapshot_unsupported';
                break;
            }
        }
        if (preg_match('/\A\d{11}\z/', (string) data_get($document->seller_snapshot, 'tax_id_number')) !== 1) {
            $reasons[] = 'seller_ruc_invalid';
        }
        if (blank(data_get($document->seller_snapshot, 'commercial_name'))) {
            $reasons[] = 'seller_commercial_name_missing';
        }
        if (data_get($document->seller_snapshot, 'country_code') !== 'PE') {
            $reasons[] = 'seller_country_unsupported';
        }
        if (data_get($document->amounts_snapshot, 'operation_type') !== '0101') {
            $reasons[] = 'operation_type_unsupported';
        }
        $paymentTerms = data_get($document->amounts_snapshot, 'payment_terms', []);
        if (data_get($paymentTerms, 'type') !== 'cash'
            || data_get($paymentTerms, 'source') !== 'order_payment_status'
            || round((float) data_get($paymentTerms, 'amount', -1), 2) !== round((float) data_get($document->amounts_snapshot, 'total', -2), 2)
            || $order->payment_status !== 'approved') {
            $reasons[] = 'payment_form_unsupported';
        }
        if ($document->document_type === 'factura'
            && (data_get($document->recipient_snapshot, 'document_type') !== 'RUC'
                || preg_match('/\A\d{11}\z/', (string) data_get($document->recipient_snapshot, 'document_number')) !== 1)) {
            $reasons[] = 'invoice_recipient_ruc_required';
        }
        if (! in_array($document->document_type, ['boleta', 'factura'], true)) {
            $reasons[] = 'document_type_unsupported';
        }
        if ($document->document_type === 'boleta') {
            $reasons[] = 'daily_summary_workflow_required';
        }
        if ($order->requested_receipt_type !== $document->document_type) {
            $reasons[] = 'requested_document_type_mismatch';
        }

        // Fase 2 does not allocate or apply advances to the final tax snapshot.
        // A single full payment is allowed; multiple or partial registered operations are held.
        $payments = $order->paymentSubmissions()->get(['id', 'amount', 'status']);
        if ($order->payment_status === 'partial'
            || $payments->count() > 1
            || ($payments->count() === 1 && (float) $payments->first()->amount > 0 && round((float) $payments->first()->amount, 2) < round((float) $order->total, 2))) {
            $reasons[] = 'advance_treatment_pending';
        }

        return array_values(array_unique($reasons));
    }
}
