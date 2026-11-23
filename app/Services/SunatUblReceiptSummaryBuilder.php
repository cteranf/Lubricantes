<?php

namespace App\Services;

use App\Models\ElectronicReceiptSummary;
use DOMDocument;
use DOMElement;

/** Builds the supported SUNAT daily SummaryDocuments UBL 2.0/Customization 1.1. */
class SunatUblReceiptSummaryBuilder
{
    private const NS = 'urn:sunat:names:specification:ubl:peru:schema:xsd:SummaryDocuments-1';

    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    private const SAC = 'urn:sunat:names:specification:ubl:peru:schema:xsd:SunatAggregateComponents-1';

    public function build(ElectronicReceiptSummary $summary): DOMDocument
    {
        $snapshot = $summary->snapshot ?? [];
        $documents = $snapshot['documents'] ?? [];
        if ($documents === [] || count($documents) > 500) {
            throw new \DomainException('A daily summary must contain between 1 and 500 frozen receipts.');
        }
        $seller = $documents[0]['seller_snapshot'] ?? [];
        $legalName = trim((string) data_get($seller, 'legal_name'));
        if (preg_match('/\A\d{11}\z/', (string) $summary->issuer_tax_id) !== 1 || $legalName === '') {
            throw new \DomainException('A valid frozen issuer RUC and legal name are required.');
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = false;
        $root = $doc->createElementNS(self::NS, 'SummaryDocuments');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::CBC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ext', self::EXT);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sac', self::SAC);
        $doc->appendChild($root);
        $extensions = $this->add($doc, $root, self::EXT, 'ext:UBLExtensions');
        $extension = $this->add($doc, $extensions, self::EXT, 'ext:UBLExtension');
        $this->add($doc, $extension, self::EXT, 'ext:ExtensionContent');
        $this->text($doc, $root, self::CBC, 'cbc:UBLVersionID', '2.0');
        $this->text($doc, $root, self::CBC, 'cbc:CustomizationID', '1.1');
        $this->text($doc, $root, self::CBC, 'cbc:ID', $summary->document_id);
        $this->text($doc, $root, self::CBC, 'cbc:ReferenceDate', $summary->issue_date->toDateString());
        $this->text($doc, $root, self::CBC, 'cbc:IssueDate', $summary->issue_date->toDateString());
        $signature = $this->add($doc, $root, self::CAC, 'cac:Signature');
        $this->text($doc, $signature, self::CBC, 'cbc:ID', $summary->issuer_tax_id);
        $signatory = $this->add($doc, $signature, self::CAC, 'cac:SignatoryParty');
        $signatoryEntity = $this->add($doc, $signatory, self::CAC, 'cac:PartyLegalEntity');
        $this->text($doc, $signatoryEntity, self::CBC, 'cbc:RegistrationName', $legalName);
        $supplier = $this->add($doc, $root, self::CAC, 'cac:AccountingSupplierParty');
        $this->text($doc, $supplier, self::CBC, 'cbc:CustomerAssignedAccountID', $summary->issuer_tax_id);
        $this->text($doc, $supplier, self::CBC, 'cbc:AdditionalAccountID', '6');
        $party = $this->add($doc, $supplier, self::CAC, 'cac:Party');
        $legalEntity = $this->add($doc, $party, self::CAC, 'cac:PartyLegalEntity');
        $this->text($doc, $legalEntity, self::CBC, 'cbc:RegistrationName', $legalName);

        foreach ($documents as $index => $row) {
            $line = $this->add($doc, $root, self::SAC, 'sac:SummaryDocumentsLine');
            $amounts = $row['amounts_snapshot'] ?? [];
            $tax = $amounts['tax_snapshot'] ?? [];
            $totals = $tax['totals'] ?? [];
            $recipient = $row['recipient_snapshot'] ?? [];
            $this->text($doc, $line, self::CBC, 'cbc:LineID', (string) ($index + 1));
            $this->text($doc, $line, self::CBC, 'cbc:DocumentTypeCode', '03');
            $this->text($doc, $line, self::CBC, 'cbc:ID', $row['series'].'-'.$row['document_number']);
            if (filled($recipient['document_type'] ?? null) && filled($recipient['document_number'] ?? null)) {
                $customer = $this->add($doc, $line, self::CAC, 'cac:AccountingCustomerParty');
                $this->text($doc, $customer, self::CBC, 'cbc:CustomerAssignedAccountID', (string) $recipient['document_number']);
                $this->text($doc, $customer, self::CBC, 'cbc:AdditionalAccountID', $this->recipientCode((string) $recipient['document_type']));
                if (filled($recipient['name'] ?? null)) {
                    $customerParty = $this->add($doc, $customer, self::CAC, 'cac:Party');
                    $customerLegal = $this->add($doc, $customerParty, self::CAC, 'cac:PartyLegalEntity');
                    $this->text($doc, $customerLegal, self::CBC, 'cbc:RegistrationName', (string) $recipient['name']);
                }
            }
            $status = $this->add($doc, $line, self::CAC, 'cac:Status');
            $this->text($doc, $status, self::CBC, 'cbc:ConditionCode', '1');
            $total = (string) ($amounts['total'] ?? '0.00');
            $this->text($doc, $line, self::SAC, 'sac:TotalAmount', $total, ['currencyID' => 'PEN']);
            $payment = $this->add($doc, $line, self::SAC, 'sac:BillingPayment');
            $this->text($doc, $payment, self::CBC, 'cbc:PaidAmount', $total, ['currencyID' => 'PEN']);
            foreach ([
                ['1000', $totals['taxable_base_minor'] ?? '0.00', $totals['tax_minor'] ?? '0.00', 'S', 'IGV', 'VAT', '18.00'],
                ['9997', $totals['exempt_base_minor'] ?? '0.00', '0.00', 'E', 'EXONERADO', 'VAT', '0.00'],
                ['9998', $totals['unaffected_base_minor'] ?? '0.00', '0.00', 'O', 'INAFECTO', 'FRE', '0.00'],
            ] as [$schemeId, $base, $taxAmount, $categoryId, $name, $typeCode, $percent]) {
                if ((float) $base === 0.0 && (float) $taxAmount === 0.0) {
                    continue;
                }
                $taxTotal = $this->add($doc, $line, self::CAC, 'cac:TaxTotal');
                $this->text($doc, $taxTotal, self::CBC, 'cbc:TaxAmount', (string) $taxAmount, ['currencyID' => 'PEN']);
                $subtotal = $this->add($doc, $taxTotal, self::CAC, 'cac:TaxSubtotal');
                $this->text($doc, $subtotal, self::CBC, 'cbc:TaxableAmount', (string) $base, ['currencyID' => 'PEN']);
                $this->text($doc, $subtotal, self::CBC, 'cbc:TaxAmount', (string) $taxAmount, ['currencyID' => 'PEN']);
                $category = $this->add($doc, $subtotal, self::CAC, 'cac:TaxCategory');
                $this->text($doc, $category, self::CBC, 'cbc:ID', $categoryId);
                $this->text($doc, $category, self::CBC, 'cbc:Percent', $percent);
                $scheme = $this->add($doc, $category, self::CAC, 'cac:TaxScheme');
                $this->text($doc, $scheme, self::CBC, 'cbc:ID', $schemeId);
                $this->text($doc, $scheme, self::CBC, 'cbc:Name', $name);
                $this->text($doc, $scheme, self::CBC, 'cbc:TaxTypeCode', $typeCode);
            }
        }

        return $doc;
    }

    private function recipientCode(string $type): string
    {
        return match (strtoupper($type)) {
            'DNI' => '1', 'RUC' => '6', 'CARNET DE EXTRANJERIA', 'CE' => '4', 'PASAPORTE' => '7', 'OTROS', 'OTHER' => '0',
            default => throw new \DomainException('The receipt recipient identity type is not supported by the daily summary.'),
        };
    }

    private function add(DOMDocument $doc, DOMElement $parent, string $namespace, string $name): DOMElement
    {
        $node = $doc->createElementNS($namespace, $name);
        $parent->appendChild($node);

        return $node;
    }

    private function text(DOMDocument $doc, DOMElement $parent, string $namespace, string $name, string $value, array $attributes = []): DOMElement
    {
        $node = $this->add($doc, $parent, $namespace, $name);
        $node->appendChild($doc->createTextNode($value));
        foreach ($attributes as $key => $attribute) {
            $node->setAttribute($key, (string) $attribute);
        }

        return $node;
    }
}
