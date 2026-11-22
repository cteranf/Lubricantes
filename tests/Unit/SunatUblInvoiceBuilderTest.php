<?php

namespace Tests\Unit;

use App\Models\ElectronicDocument;
use App\Services\MoneyService;
use App\Services\SunatSchemaBundle;
use App\Services\SunatUblInvoiceBuilder;
use App\Services\SunatXmlSignatureService;
use Illuminate\Support\Facades\File;
use Tests\Support\SyntheticCertificate;
use Tests\TestCase;

class SunatUblInvoiceBuilderTest extends TestCase
{
    public function test_builder_preserves_fractional_unit_price_and_f2_igv_ipm_total_without_rounding_drift(): void
    {
        $document = new ElectronicDocument([
            'document_type' => 'factura', 'series' => 'F009', 'document_number' => 17,
            'confirmed_at' => '2026-11-19 10:00:00', 'currency' => 'PEN',
            'seller_snapshot' => ['commercial_name' => 'LubriStore QA', 'tax_id_number' => '20123456789', 'legal_name' => 'LubriStore QA SAC', 'fiscal_address' => 'Dirección fiscal QA'],
            'recipient_snapshot' => ['document_type' => 'RUC', 'document_number' => '20987654321', 'name' => 'Cliente QA'],
            'lines_snapshot' => ['items' => [[
                'name' => 'Producto QA', 'quantity' => 3, 'unit_price' => '0.05', 'subtotal' => '0.15',
                'tax' => ['tax_affectation' => ['code' => '10'], 'base' => '0.13', 'tax' => '0.02'],
            ]]],
            'amounts_snapshot' => [
                'total' => '0.15', 'operation_type' => '0101',
                'payment_terms' => ['type' => 'cash', 'amount' => '0.15', 'source' => 'order_payment_status'],
                'tax_snapshot' => ['totals' => [
                    'taxable_base_minor' => '0.13', 'exempt_base_minor' => '0.00',
                    'unaffected_base_minor' => '0.00', 'tax_minor' => '0.02',
                    'rate_basis_points' => 1800,
                ]],
            ],
        ]);

        $xml = (new SunatUblInvoiceBuilder(new MoneyService))->build($document);
        $xml = $this->assertInvoiceStructureValidates($xml);
        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('inv', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

        $this->assertSame('F009-00000017', $xpath->evaluate('string(/inv:Invoice/cbc:ID)'));
        $this->assertSame('0.13', $xpath->evaluate('string(//cac:InvoiceLine/cbc:LineExtensionAmount)'));
        $this->assertSame('0.04237288', $xpath->evaluate('string(//cac:InvoiceLine/cac:Price/cbc:PriceAmount)'));
        $this->assertSame('0.02', $xpath->evaluate('string(//cac:InvoiceLine/cac:TaxTotal/cbc:TaxAmount)'));
        $this->assertSame('0.15', $xpath->evaluate('string(/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount)'));
        $this->assertSame('18.00', $xpath->evaluate('string(//cac:InvoiceLine/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:Percent)'));
        $this->assertSame('0101', $xpath->evaluate('string(/inv:Invoice/cbc:InvoiceTypeCode/@listID)'));
        $this->assertSame('0101', $xpath->evaluate('string(/inv:Invoice/cbc:ProfileID)'));
        $this->assertSame('Contado', $xpath->evaluate('string(/inv:Invoice/cac:PaymentTerms/cbc:PaymentMeansID)'));
    }

    public function test_builder_maps_tax_affectations_to_the_sunat_tax_catalog(): void
    {
        $items = [];
        foreach ([
            ['10', '118.00', '100.00', '18.00'],
            ['20', '20.00', '20.00', '0.00'],
            ['30', '7.00', '7.00', '0.00'],
        ] as [$affectation, $gross, $base, $tax]) {
            $items[] = [
                'name' => 'Producto QA '.$affectation,
                'quantity' => 1,
                'unit_price' => $gross,
                'subtotal' => $gross,
                'tax' => [
                    'tax_affectation' => ['code' => $affectation],
                    'base' => $base,
                    'tax' => $tax,
                ],
            ];
        }

        $document = new ElectronicDocument([
            'document_type' => 'factura', 'series' => 'F009', 'document_number' => 18,
            'confirmed_at' => '2026-11-19 10:00:00', 'currency' => 'PEN',
            'seller_snapshot' => ['commercial_name' => 'LubriStore QA', 'tax_id_number' => '20123456789', 'legal_name' => 'LubriStore QA SAC', 'fiscal_address' => 'Dirección fiscal QA'],
            'recipient_snapshot' => ['document_type' => 'RUC', 'document_number' => '20987654321', 'name' => 'Cliente QA'],
            'lines_snapshot' => ['items' => $items],
            'amounts_snapshot' => ['total' => '145.00', 'operation_type' => '0101',
                'payment_terms' => ['type' => 'cash', 'amount' => '145.00', 'source' => 'order_payment_status'],
                'tax_snapshot' => ['totals' => [
                    'taxable_base_minor' => '100.00', 'exempt_base_minor' => '20.00',
                    'unaffected_base_minor' => '7.00', 'tax_minor' => '18.00', 'rate_basis_points' => 1800,
                ]]],
        ]);

        $xml = (new SunatUblInvoiceBuilder(new MoneyService))->build($document);
        $xml = $this->assertInvoiceStructureValidates($xml);
        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('inv', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

        $this->assertSame('1000', $xpath->evaluate('string(/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal[cbc:TaxableAmount="100.00"]/cac:TaxCategory/cac:TaxScheme/cbc:ID)'));
        $this->assertSame('IGV', $xpath->evaluate('string(/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal[cbc:TaxableAmount="100.00"]/cac:TaxCategory/cac:TaxScheme/cbc:Name)'));
        $this->assertSame('9997', $xpath->evaluate('string(/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal[cbc:TaxableAmount="20.00"]/cac:TaxCategory/cac:TaxScheme/cbc:ID)'));
        $this->assertSame('EXONERADO', $xpath->evaluate('string(/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal[cbc:TaxableAmount="20.00"]/cac:TaxCategory/cac:TaxScheme/cbc:Name)'));
        $this->assertSame('9998', $xpath->evaluate('string(/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal[cbc:TaxableAmount="7.00"]/cac:TaxCategory/cac:TaxScheme/cbc:ID)'));
        $this->assertSame('INAFECTO', $xpath->evaluate('string(/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal[cbc:TaxableAmount="7.00"]/cac:TaxCategory/cac:TaxScheme/cbc:Name)'));
        $this->assertSame('FRE', $xpath->evaluate('string(/inv:Invoice/cac:InvoiceLine[3]/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cac:TaxScheme/cbc:TaxTypeCode)'));
    }

    private function assertInvoiceStructureValidates(\DOMDocument $xml): \DOMDocument
    {
        config([
            'electronic_invoicing.sunat.xsd_directory' => base_path('tests/Fixtures/OasisUBL21'),
            'electronic_invoicing.sunat.invoice_xsd' => base_path('tests/Fixtures/OasisUBL21/xsd/maindoc/UBL-Invoice-2.1.xsd'),
        ]);
        $extensionContent = $xml->getElementsByTagNameNS(
            'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
            'ExtensionContent'
        )->item(0);
        $this->assertNotNull($extensionContent);
        $syntheticCertificate = SyntheticCertificate::issue(['commonName' => '20123456789'], 'synthetic-pass');
        $pkcs12 = $syntheticCertificate['pkcs12'];
        $certificatePem = $syntheticCertificate['certificate_pem'];
        $signedXml = (new SunatXmlSignatureService)->signAndVerify($xml, $pkcs12, 'synthetic-pass', '20123456789', $syntheticCertificate['openssl_config']);
        $serialized = new \DOMDocument;
        $serialized->resolveExternals = false;
        $serialized->substituteEntities = false;
        $this->assertTrue($serialized->loadXML($signedXml, LIBXML_NONET));
        $this->assertTrue((new SunatXmlSignatureService)->verifySerializedDocument($serialized, $certificatePem), 'The signature must verify after parsing the final serialized XML.');
        $previousLibxmlErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $oasysValid = $serialized->schemaValidate(base_path('tests/Fixtures/OasisUBL21/xsd/maindoc/UBL-Invoice-2.1.xsd'), LIBXML_NONET);
        $schemaErrors = array_map(static fn (\LibXMLError $error): string => trim($error->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlErrors);
        $this->assertTrue($oasysValid, 'Signed XML must satisfy the OASIS fixture: '.implode(' | ', $schemaErrors));
        $this->assertTrue((new SunatSchemaBundle)->validate($serialized), 'Signed XML must satisfy the isolated OASIS UBL 2.1 fixture.');

        config([
            'electronic_invoicing.sunat.xsd_directory' => resource_path('sunat/xsd'),
            'electronic_invoicing.sunat.invoice_xsd' => resource_path('sunat/xsd/Archivos XSD/2.1/maindoc/UBL-Invoice-2.1.xsd'),
        ]);
        $official = new SunatSchemaBundle;
        $inspection = $official->inspect();
        $this->assertTrue($inspection['available'], 'The installed SUNAT bundle and its local schema dependencies must resolve.');
        $this->assertGreaterThanOrEqual(14, count($inspection['files']));
        $this->assertTrue($official->validate($serialized), 'The final signed invoice must satisfy the installed SUNAT UBL 2.1 XSD.');

        return $serialized;
    }

    public function test_official_bundle_rejects_invalid_invoice_and_reports_an_incomplete_copy(): void
    {
        $root = resource_path('sunat/xsd/Archivos XSD/2.1/maindoc/UBL-Invoice-2.1.xsd');
        config([
            'electronic_invoicing.sunat.xsd_directory' => resource_path('sunat/xsd'),
            'electronic_invoicing.sunat.invoice_xsd' => $root,
        ]);
        $bundle = new SunatSchemaBundle;
        $invalid = new \DOMDocument;
        $this->assertTrue($invalid->loadXML('<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"/>', LIBXML_NONET));
        $this->assertFalse($bundle->validate($invalid));

        $temporary = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sunat-xsd-incomplete-'.bin2hex(random_bytes(8));
        $temporaryRoot = $temporary.DIRECTORY_SEPARATOR.'2.1'.DIRECTORY_SEPARATOR.'maindoc';
        mkdir($temporaryRoot, 0777, true);
        try {
            $copiedRoot = $temporaryRoot.DIRECTORY_SEPARATOR.'UBL-Invoice-2.1.xsd';
            $this->assertTrue(copy($root, $copiedRoot));
            config([
                'electronic_invoicing.sunat.xsd_directory' => $temporary,
                'electronic_invoicing.sunat.invoice_xsd' => $copiedRoot,
            ]);
            $incomplete = (new SunatSchemaBundle)->inspect();
            $this->assertFalse($incomplete['available']);
            $this->assertSame('official_ubl_schema_dependency_missing_or_external', $incomplete['reason']);

            $escapeDirectory = $temporary.DIRECTORY_SEPARATOR.'bundle';
            $escapeRootDirectory = $escapeDirectory.DIRECTORY_SEPARATOR.'2.1'.DIRECTORY_SEPARATOR.'maindoc';
            mkdir($escapeRootDirectory, 0777, true);
            $escapeRoot = $escapeRootDirectory.DIRECTORY_SEPARATOR.'UBL-Invoice-2.1.xsd';
            $outsideSchema = $temporary.DIRECTORY_SEPARATOR.'outside.xsd';
            file_put_contents($outsideSchema, '<?xml version="1.0"?><xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema" targetNamespace="urn:outside"/>');
            file_put_contents($escapeRoot, '<?xml version="1.0"?><xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema" targetNamespace="urn:invoice"><xsd:import namespace="urn:outside" schemaLocation="../../../outside.xsd"/></xsd:schema>');
            config([
                'electronic_invoicing.sunat.xsd_directory' => $escapeDirectory,
                'electronic_invoicing.sunat.invoice_xsd' => $escapeRoot,
            ]);
            $escaped = (new SunatSchemaBundle)->inspect();
            $this->assertFalse($escaped['available']);
            $this->assertSame('official_ubl_schema_dependency_missing_or_external', $escaped['reason']);

            file_put_contents($escapeRoot, '<?xml version="1.0"?><xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema" targetNamespace="urn:invoice"><xsd:import namespace="urn:external" schemaLocation="https://example.invalid/external.xsd"/></xsd:schema>');
            $network = (new SunatSchemaBundle)->inspect();
            $this->assertFalse($network['available']);
            $this->assertSame('official_ubl_schema_dependency_missing_or_external', $network['reason']);
        } finally {
            File::deleteDirectory($temporary);
        }
    }

    public function test_sunat_schema_gate_stays_closed_until_explicitly_configured(): void
    {
        config([
            'electronic_invoicing.sunat.xsd_directory' => base_path('tests/Fixtures/OasisUBL21'),
            'electronic_invoicing.sunat.invoice_xsd' => null,
        ]);

        $bundle = new SunatSchemaBundle;
        $this->assertFalse($bundle->isAvailable());
        $this->assertSame('official_ubl_schema_missing', $bundle->inspect()['reason']);
    }

    public function test_schema_bundle_resolves_paths_relative_to_the_application_root(): void
    {
        config([
            'electronic_invoicing.sunat.xsd_directory' => 'tests/Fixtures/OasisUBL21',
            'electronic_invoicing.sunat.invoice_xsd' => 'tests/Fixtures/OasisUBL21/xsd/maindoc/UBL-Invoice-2.1.xsd',
        ]);

        $inspection = (new SunatSchemaBundle)->inspect();

        $this->assertTrue($inspection['available']);
        $this->assertSame(
            realpath(base_path('tests/Fixtures/OasisUBL21/xsd/maindoc/UBL-Invoice-2.1.xsd')),
            $inspection['root']
        );
    }
}
