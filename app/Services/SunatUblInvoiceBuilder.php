<?php

namespace App\Services;

use App\Models\ElectronicDocument;
use DOMDocument;
use DOMElement;

/** Builds the supported domestic UBL 2.1 invoice document from frozen snapshots. */
class SunatUblInvoiceBuilder
{
    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    public function __construct(private MoneyService $money) {}

    public function build(ElectronicDocument $document): DOMDocument
    {
        if ($document->document_type !== 'factura') {
            throw new \DomainException('Boleta requires the official daily summary workflow.');
        }

        $ruc = (string) data_get($document->seller_snapshot, 'tax_id_number');
        $commercialName = trim((string) data_get($document->seller_snapshot, 'commercial_name'));
        if ($commercialName === '') {
            throw new \DomainException('The seller commercial name is required by the invoice profile.');
        }
        $seriesNumber = $document->series.'-'.str_pad((string) $document->document_number, 8, '0', STR_PAD_LEFT);
        $currency = $document->currency ?: 'PEN';
        $operationType = (string) data_get($document->amounts_snapshot, 'operation_type');
        if ($operationType !== '0101') {
            throw new \DomainException('Only domestic internal sales (operation type 0101) are supported.');
        }
        $paymentTerms = data_get($document->amounts_snapshot, 'payment_terms', []);
        if (data_get($paymentTerms, 'type') !== 'cash'
            || data_get($paymentTerms, 'source') !== 'order_payment_status'
            || round((float) data_get($paymentTerms, 'amount', -1), 2) !== round((float) data_get($document->amounts_snapshot, 'total', -2), 2)) {
            throw new \DomainException('The invoice requires a confirmed cash payment snapshot.');
        }
        $snapshot = $document->amounts_snapshot['tax_snapshot'] ?? [];
        $lines = $document->lines_snapshot['items'] ?? [];
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = false;
        $root = $doc->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', 'Invoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::CBC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ext', self::EXT);
        $doc->appendChild($root);
        $extensions = $this->add($doc, $root, self::EXT, 'ext:UBLExtensions');
        $extension = $this->add($doc, $extensions, self::EXT, 'ext:UBLExtension');
        $signatureTarget = $this->add($doc, $extension, self::EXT, 'ext:ExtensionContent');
        $this->text($doc, $root, self::CBC, 'cbc:UBLVersionID', '2.1');
        $this->text($doc, $root, self::CBC, 'cbc:CustomizationID', '2.0');
        $this->text($doc, $root, self::CBC, 'cbc:ProfileID', $operationType, [
            'schemeName' => 'SUNAT:Identificador de Tipo de Operación',
            'schemeAgencyName' => 'PE:SUNAT',
            'schemeURI' => 'urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo17',
        ]);
        $this->text($doc, $root, self::CBC, 'cbc:ID', $seriesNumber);
        $this->text($doc, $root, self::CBC, 'cbc:IssueDate', $document->confirmed_at->toDateString());
        $this->text($doc, $root, self::CBC, 'cbc:InvoiceTypeCode', '01', [
            'listID' => $operationType,
            'listAgencyName' => 'PE:SUNAT',
            'listName' => 'SUNAT:Identificador de Tipo de Documento',
            'listURI' => 'urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo01',
        ]);
        $this->text($doc, $root, self::CBC, 'cbc:DocumentCurrencyCode', $currency, [
            'listID' => 'ISO 4217 Alpha',
            'listName' => 'Currency',
            'listAgencyName' => 'United Nations Economic Commission for Europe',
        ]);
        $signature = $this->add($doc, $root, self::CAC, 'cac:Signature');
        $this->text($doc, $signature, self::CBC, 'cbc:ID', $ruc);
        $signatory = $this->add($doc, $signature, self::CAC, 'cac:SignatoryParty');
        $partyId = $this->add($doc, $signatory, self::CAC, 'cac:PartyIdentification');
        $this->text($doc, $partyId, self::CBC, 'cbc:ID', $ruc);
        $signatoryName = $this->add($doc, $signatory, self::CAC, 'cac:PartyName');
        $this->text($doc, $signatoryName, self::CBC, 'cbc:Name', (string) data_get($document->seller_snapshot, 'legal_name'));
        $attachment = $this->add($doc, $signature, self::CAC, 'cac:DigitalSignatureAttachment');
        $external = $this->add($doc, $attachment, self::CAC, 'cac:ExternalReference');
        $this->text($doc, $external, self::CBC, 'cbc:URI', '#SignatureSP');

        $supplier = $this->add($doc, $root, self::CAC, 'cac:AccountingSupplierParty');
        $supplierParty = $this->add($doc, $supplier, self::CAC, 'cac:Party');
        $identification = $this->add($doc, $supplierParty, self::CAC, 'cac:PartyIdentification');
        $this->text($doc, $identification, self::CBC, 'cbc:ID', $ruc, [
            'schemeID' => '6',
            'schemeName' => 'SUNAT:Identificador de Documento de Identidad',
            'schemeAgencyName' => 'PE:SUNAT',
            'schemeURI' => 'urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06',
        ]);
        $supplierName = $this->add($doc, $supplierParty, self::CAC, 'cac:PartyName');
        $this->text($doc, $supplierName, self::CBC, 'cbc:Name', $commercialName);
        $legalEntity = $this->add($doc, $supplierParty, self::CAC, 'cac:PartyLegalEntity');
        $this->text($doc, $legalEntity, self::CBC, 'cbc:RegistrationName', (string) data_get($document->seller_snapshot, 'legal_name'));
        $postal = $this->add($doc, $legalEntity, self::CAC, 'cac:RegistrationAddress');
        $this->text($doc, $postal, self::CBC, 'cbc:AddressTypeCode', '0000');
        $address = $this->add($doc, $postal, self::CAC, 'cac:AddressLine');
        $this->text($doc, $address, self::CBC, 'cbc:Line', (string) data_get($document->seller_snapshot, 'fiscal_address'));
        $country = $this->add($doc, $postal, self::CAC, 'cac:Country');
        $this->text($doc, $country, self::CBC, 'cbc:IdentificationCode', 'PE');

        $customer = $this->add($doc, $root, self::CAC, 'cac:AccountingCustomerParty');
        $customerParty = $this->add($doc, $customer, self::CAC, 'cac:Party');
        $customerIdentification = $this->add($doc, $customerParty, self::CAC, 'cac:PartyIdentification');
        $this->text($doc, $customerIdentification, self::CBC, 'cbc:ID', (string) data_get($document->recipient_snapshot, 'document_number'), [
            'schemeID' => '6',
            'schemeName' => 'SUNAT:Identificador de Documento de Identidad',
            'schemeAgencyName' => 'PE:SUNAT',
            'schemeURI' => 'urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06',
        ]);
        $customerPartyName = $this->add($doc, $customerParty, self::CAC, 'cac:PartyName');
        $this->text($doc, $customerPartyName, self::CBC, 'cbc:Name', (string) data_get($document->recipient_snapshot, 'name'));
        $customerName = $this->add($doc, $customerParty, self::CAC, 'cac:PartyLegalEntity');
        $this->text($doc, $customerName, self::CBC, 'cbc:RegistrationName', (string) data_get($document->recipient_snapshot, 'name'));
        $payment = $this->add($doc, $root, self::CAC, 'cac:PaymentTerms');
        $this->text($doc, $payment, self::CBC, 'cbc:ID', 'FormaPago');
        $this->text($doc, $payment, self::CBC, 'cbc:PaymentMeansID', 'Contado');

        $totals = $snapshot['totals'] ?? [];
        $this->addTaxTotal($doc, $root, $currency, (string) ($totals['tax_minor'] ?? '0.00'), [
            ['base' => $totals['taxable_base_minor'] ?? '0.00', 'tax' => $totals['tax_minor'] ?? '0.00', 'affectation' => '10', 'category' => 'S', 'percent' => '18.00'],
            ['base' => $totals['exempt_base_minor'] ?? '0.00', 'tax' => '0.00', 'affectation' => '20', 'category' => 'E', 'percent' => '0.00'],
            ['base' => $totals['unaffected_base_minor'] ?? '0.00', 'tax' => '0.00', 'affectation' => '30', 'category' => 'O', 'percent' => '0.00'],
        ]);
        $monetary = $this->add($doc, $root, self::CAC, 'cac:LegalMonetaryTotal');
        $baseMinor = $this->money->toMinor((string) ($totals['taxable_base_minor'] ?? '0.00'))
            + $this->money->toMinor((string) ($totals['exempt_base_minor'] ?? '0.00'))
            + $this->money->toMinor((string) ($totals['unaffected_base_minor'] ?? '0.00'));
        $base = $this->money->fromMinor($baseMinor);
        $gross = (string) data_get($document->amounts_snapshot, 'total', '0.00');
        $this->text($doc, $monetary, self::CBC, 'cbc:LineExtensionAmount', $base, ['currencyID' => $currency]);
        $this->text($doc, $monetary, self::CBC, 'cbc:TaxExclusiveAmount', $base, ['currencyID' => $currency]);
        $this->text($doc, $monetary, self::CBC, 'cbc:TaxInclusiveAmount', $gross, ['currencyID' => $currency]);
        $this->text($doc, $monetary, self::CBC, 'cbc:PayableAmount', $gross, ['currencyID' => $currency]);

        foreach ($lines as $index => $line) {
            $this->invoiceLine($doc, $root, $line, $index + 1, $currency, (int) ($snapshot['rate_basis_points'] ?? 1800));
        }
        $shipping = $snapshot['shipping'] ?? [];
        if ((float) ($shipping['gross'] ?? 0) > 0) {
            $this->invoiceLine($doc, $root, [
                'name' => 'Servicio de envío', 'quantity' => 1, 'unit_price' => $shipping['gross'],
                'subtotal' => $shipping['gross'], 'tax' => [
                    'calculation_status' => $shipping['status'] ?? 'pending',
                    'tax_affectation' => ['code' => $shipping['tax_affectation']['code'] ?? null],
                    'base' => $shipping['base'], 'tax' => $shipping['tax'],
                    'tax_rate_basis_points' => 1800,
                ],
            ], count($lines) + 1, $currency, (int) ($snapshot['rate_basis_points'] ?? 1800));
        }

        return $doc;
    }

    private function invoiceLine(DOMDocument $doc, DOMElement $root, array $line, int $id, string $currency, int $totalRateBasisPoints): void
    {
        $tax = $line['tax'] ?? [];
        $code = (string) data_get($tax, 'tax_affectation.code');
        $base = (string) ($tax['base'] ?? $line['subtotal'] ?? '0.00');
        $taxAmount = (string) ($tax['tax'] ?? '0.00');
        $quantity = (int) ($line['quantity'] ?? 0);
        $item = $this->add($doc, $root, self::CAC, 'cac:InvoiceLine');
        $this->text($doc, $item, self::CBC, 'cbc:ID', (string) $id);
        $this->text($doc, $item, self::CBC, 'cbc:InvoicedQuantity', (string) $quantity, ['unitCode' => 'NIU']);
        $this->text($doc, $item, self::CBC, 'cbc:LineExtensionAmount', $base, ['currencyID' => $currency]);
        $this->addTaxTotal($doc, $item, $currency, $taxAmount, [[
            'base' => $base, 'tax' => $taxAmount, 'code' => $code,
            'affectation' => $code,
            'category' => ['10' => 'S', '20' => 'E', '30' => 'O'][$code] ?? '',
            'percent' => $code === '10' ? $this->percent($totalRateBasisPoints) : '0.00',
        ]]);
        $product = $this->add($doc, $item, self::CAC, 'cac:Item');
        $this->text($doc, $product, self::CBC, 'cbc:Description', (string) ($line['name'] ?? 'Producto'));
        $price = $this->add($doc, $item, self::CAC, 'cac:Price');
        $unitMinor = $this->money->toMinor((string) ($line['unit_price'] ?? '0.00'));
        $denominator = $code === '10' ? 10000 + $totalRateBasisPoints : 10000;
        $this->text($doc, $price, self::CBC, 'cbc:PriceAmount', $this->unitBaseAmount($unitMinor, $denominator), ['currencyID' => $currency]);
    }

    private function addTaxTotal(DOMDocument $doc, DOMElement $parent, string $currency, string $total, array $groups): void
    {
        $taxTotal = $this->add($doc, $parent, self::CAC, 'cac:TaxTotal');
        $this->text($doc, $taxTotal, self::CBC, 'cbc:TaxAmount', $total, ['currencyID' => $currency]);
        foreach ($groups as $group) {
            if ((float) $group['base'] === 0.0 && (float) $group['tax'] === 0.0) {
                continue;
            }
            $subtotal = $this->add($doc, $taxTotal, self::CAC, 'cac:TaxSubtotal');
            $this->text($doc, $subtotal, self::CBC, 'cbc:TaxableAmount', (string) $group['base'], ['currencyID' => $currency]);
            $this->text($doc, $subtotal, self::CBC, 'cbc:TaxAmount', (string) $group['tax'], ['currencyID' => $currency]);
            $category = $this->add($doc, $subtotal, self::CAC, 'cac:TaxCategory');
            $this->text($doc, $category, self::CBC, 'cbc:ID', (string) $group['category'], ['schemeID' => 'UN/ECE 5305', 'schemeName' => 'Tax Category Identifier', 'schemeAgencyName' => 'United Nations Economic Commission for Europe']);
            $this->text($doc, $category, self::CBC, 'cbc:Percent', (string) $group['percent']);
            $this->text($doc, $category, self::CBC, 'cbc:TaxExemptionReasonCode', (string) $group['affectation'], ['listAgencyName' => 'PE:SUNAT', 'listName' => 'Afectacion del IGV', 'listURI' => 'urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo07']);
            $scheme = $this->add($doc, $category, self::CAC, 'cac:TaxScheme');
            [$schemeId, $schemeName, $taxTypeCode] = $this->taxScheme((string) $group['affectation']);
            $this->text($doc, $scheme, self::CBC, 'cbc:ID', $schemeId, [
                'schemeID' => 'UN/ECE 5153',
                'schemeName' => 'Tax Scheme Identifier',
                'schemeAgencyName' => 'United Nations Economic Commission for Europe',
            ]);
            $this->text($doc, $scheme, self::CBC, 'cbc:Name', $schemeName);
            $this->text($doc, $scheme, self::CBC, 'cbc:TaxTypeCode', $taxTypeCode);
        }
    }

    /** @return array{string, string, string} */
    private function taxScheme(string $affectation): array
    {
        return match ($affectation) {
            '10' => ['1000', 'IGV', 'VAT'],
            '20' => ['9997', 'EXONERADO', 'VAT'],
            '30' => ['9998', 'INAFECTO', 'FRE'],
            default => throw new \DomainException('The tax affectation has no supported SUNAT tax scheme.'),
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
            $node->setAttribute($key, $attribute);
        }

        return $node;
    }

    private function percent(int $basisPoints): string
    {
        return sprintf('%d.%02d', intdiv($basisPoints, 100), $basisPoints % 100);
    }

    private function unitBaseAmount(int $grossMinor, int $denominator): string
    {
        if ($grossMinor < 0 || $denominator < 1 || $grossMinor > intdiv(PHP_INT_MAX, 100)) {
            throw new \DomainException('The unit price cannot be represented safely.');
        }

        $numerator = $grossMinor * 100;
        $whole = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;
        $scale = 100000000;
        $fraction = intdiv($remainder * $scale + intdiv($denominator, 2), $denominator);
        if ($fraction >= $scale) {
            $whole++;
            $fraction = 0;
        }

        return $whole.'.'.str_pad((string) $fraction, 8, '0', STR_PAD_LEFT);
    }
}
