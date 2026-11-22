<?php

namespace Tests\Feature;

use App\Contracts\ElectronicInvoiceGateway;
use App\Http\Resources\ElectronicDocumentResource;
use App\Jobs\ProcessElectronicDocument;
use App\Models\BusinessProfile;
use App\Models\ElectronicDocument;
use App\Models\ElectronicDocumentAttempt;
use App\Models\ElectronicDocumentOutbox;
use App\Models\ElectronicInvoiceSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\DirectSunatGateway;
use App\Services\ElectronicDocumentEmissionService;
use App\Services\ElectronicDocumentJobOutboxPublisher;
use App\Services\ElectronicDocumentRecoveryService;
use App\Services\ElectronicInvoiceEligibility;
use App\Services\MoneyService;
use App\Services\SunatSchemaBundle;
use App\Services\SunatUblInvoiceBuilder;
use App\Services\SunatXmlSignatureService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SyntheticCertificate;
use Tests\TestCase;

class ElectronicInvoicingFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['electronic_invoicing.enabled' => true]);
    }

    public function test_billing_user_prepares_idempotent_historical_draft_and_can_correct_it_with_version_control(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($admin);
        BusinessProfile::create([
            'singleton_key' => 'primary', 'commercial_name' => 'LubriStore QA', 'legal_name' => 'LubriStore QA SAC',
            'tax_id_type' => 'RUC', 'tax_id_number' => '20123456789', 'country_code' => 'PE',
            'fiscal_address' => 'Dirección fiscal sintética', 'boleta_enabled' => true,
        ]);
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->order($customer, '600.00');
        OrderItem::create(['order_id' => $order->id, 'product_id' => null, 'product_name' => 'Aceite histórico', 'quantity' => 2, 'regular_price' => '310.00', 'price' => '300.00', 'subtotal' => '600.00']);

        $billing = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        Sanctum::actingAs($billing);
        $key = (string) Str::uuid();
        $response = $this->postJson('/api/v1/billing/orders/'.$order->id.'/electronic-documents', ['preparation_key' => $key])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.status_label', 'Borrador; no emitido')
            ->assertJsonPath('data.lines.items.0.name', 'Aceite histórico')->assertJsonPath('data.lines.items.0.unit_price', '300.00')
            ->assertJsonPath('data.amounts.total', '600.00')->assertJsonPath('data.tax_calculation_status', 'pending_configuration')
            ->assertJsonPath('data.readiness.status', 'draft')->assertJsonPath('data.readiness.version', 1)
            ->assertJsonPath('data.readiness.ready', false);
        $id = $response->json('data.id');
        $this->assertContains('signing_certificate_missing_or_expired', $response->json('data.readiness.blocking_reasons'));
        $this->assertSame('0101', $response->json('data.amounts.operation_type'));
        $this->assertSame('unsupported', $response->json('data.amounts.payment_terms.type'));
        $this->assertContains('payment_form_unsupported', app(ElectronicInvoiceEligibility::class)->reasons(ElectronicDocument::query()->findOrFail($id), $order));
        $this->postJson('/api/v1/billing/electronic-documents/'.$id.'/issue', ['version' => 1])->assertStatus(409)
            ->assertJsonPath('message', 'El cálculo tributario está pendiente o requiere revisión. No se puede preparar una emisión hasta completar y validar el desglose.');
        $this->assertDatabaseCount('electronic_document_attempts', 0);
        $this->postJson('/api/v1/billing/orders/'.$order->id.'/electronic-documents', ['preparation_key' => $key])
            ->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(1, ElectronicDocument::where('order_id', $order->id)->count());
        $this->postJson('/api/v1/billing/orders/'.$order->id.'/electronic-documents', ['preparation_key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame(2, ElectronicDocument::where('order_id', $order->id)->count());
        $this->assertSame('600.00', $order->fresh()->total);

        $corrected = $this->putJson('/api/v1/billing/electronic-documents/'.$id, [
            'version' => 1,
            'recipient' => ['name' => 'Corrección de prueba'],
        ])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.recipient.name', 'Corrección de prueba')
            ->assertJsonPath('data.readiness.status', 'draft')->assertJsonPath('data.readiness.version', 2)
            ->assertJsonPath('data.readiness.ready', false)
            ->assertJsonPath('data.history.1.event_type', 'updated')->assertJsonPath('data.history.1.actor_name', $billing->name);
        $this->assertContains('signing_certificate_missing_or_expired', $corrected->json('data.readiness.blocking_reasons'));
        $consulted = $this->getJson('/api/v1/billing/electronic-documents/'.$id)->assertOk();
        $this->assertSame($corrected->json('data.readiness'), $consulted->json('data.readiness'));
        $this->putJson('/api/v1/billing/electronic-documents/'.$id, ['version' => 1, 'recipient' => ['name' => 'Obsoleto']])->assertStatus(409);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'total' => '600.00']);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'price' => '300.00']);
    }

    public function test_a_second_draft_for_the_same_order_cannot_be_confirmed_after_an_attempt_exists(): void
    {
        $billing = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        $order = $this->order(User::factory()->create(['role' => 'customer']), '118.00');
        $issuedDocument = ElectronicDocument::query()->create([
            'order_id' => $order->id, 'draft_reference' => (string) Str::uuid(),
            'preparation_key' => (string) Str::uuid(), 'document_type' => 'boleta', 'status' => 'queued',
            'environment' => 'testing', 'connection_type' => 'direct_sunat', 'seller_snapshot' => [],
            'recipient_snapshot' => [], 'lines_snapshot' => [], 'amounts_snapshot' => ['total' => '118.00'],
            'currency' => 'PEN', 'tax_calculation_status' => 'complete', 'version' => 2, 'prepared_at' => now(),
        ]);
        ElectronicDocumentAttempt::query()->create([
            'electronic_document_id' => $issuedDocument->id, 'initiated_by' => $billing->id, 'attempt_number' => 1,
            'connection_type' => 'direct_sunat', 'environment' => 'testing', 'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('b', 64), 'request_snapshot' => ['type' => 'boleta'],
            'status' => 'queued', 'started_at' => now(),
        ]);
        $secondDraft = ElectronicDocument::query()->create([
            'order_id' => $order->id, 'draft_reference' => (string) Str::uuid(),
            'preparation_key' => (string) Str::uuid(), 'document_type' => 'boleta', 'status' => 'draft',
            'environment' => 'testing', 'connection_type' => 'direct_sunat', 'seller_snapshot' => [],
            'recipient_snapshot' => [], 'lines_snapshot' => [], 'amounts_snapshot' => ['total' => '118.00'],
            'currency' => 'PEN', 'tax_calculation_status' => 'complete', 'version' => 1, 'prepared_at' => now(),
        ]);
        Sanctum::actingAs($billing);

        $this->postJson('/api/v1/billing/electronic-documents/'.$secondDraft->id.'/issue', ['version' => 1])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Ya existe otro documento electrónico con un intento para esta venta. No se puede emitir un segundo comprobante.');

        $this->assertDatabaseHas('electronic_documents', ['id' => $secondDraft->id, 'status' => 'draft', 'series' => null, 'document_number' => null]);
        $this->assertDatabaseCount('electronic_document_attempts', 1);
        $this->assertDatabaseCount('electronic_document_outbox', 0);
        $this->assertDatabaseCount('electronic_document_series', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_commercial_enablement_is_read_only_on_business_profile_and_disabled_module_blocks_preparation(): void
    {
        config(['electronic_invoicing.enabled' => false]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/business-profile')->assertOk()->assertJsonPath('data.electronic_invoicing.enabled', false)->assertJsonPath('data.electronic_invoicing.editable_here', false);
        $this->putJson('/api/v1/admin/business-profile', ['electronic_invoicing_enabled' => true])->assertUnprocessable()->assertJsonValidationErrors('request');
        $order = $this->order(User::factory()->create(['role' => 'customer']), '25.00');
        config(['electronic_invoicing.enabled' => true]);
        Sanctum::actingAs(User::factory()->create(['role' => 'billing', 'is_active' => true]));
        $id = $this->postJson('/api/v1/billing/orders/'.$order->id.'/electronic-documents', ['preparation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        config(['electronic_invoicing.enabled' => false]);
        $this->getJson('/api/v1/billing/electronic-documents/'.$id)->assertOk()->assertJsonPath('data.id', $id);
        $this->putJson('/api/v1/billing/electronic-documents/'.$id, ['version' => 1, 'recipient' => ['name' => 'No debe guardarse']])->assertStatus(409);
        $this->postJson('/api/v1/billing/orders/'.$order->id.'/electronic-documents', ['preparation_key' => (string) Str::uuid()])->assertStatus(409);
        $this->postJson('/api/v1/billing/electronic-documents/'.$id.'/issue')->assertStatus(409);
        $this->postJson('/api/v1/billing/electronic-documents/'.$id.'/query', [])->assertStatus(409)
            ->assertJsonPath('message', 'La facturación electrónica no está habilitada comercialmente.');
        $this->assertDatabaseHas('electronic_documents', ['id' => $id, 'status' => 'draft', 'version' => 1]);
        $this->assertDatabaseCount('electronic_document_events', 1);
        $this->assertDatabaseCount('electronic_documents', 1);
    }

    public function test_missing_sunat_xsd_blocks_confirmation_before_series_or_attempt_creation(): void
    {
        config([
            'electronic_invoicing.enabled' => true,
            'electronic_invoicing.sunat.xsd_directory' => base_path('tests/Fixtures/OasisUBL21'),
            'electronic_invoicing.sunat.invoice_xsd' => null,
        ]);
        Storage::fake('local');
        $order = $this->order(User::factory()->create(['role' => 'customer']), '118.00');
        $order->update([
            'requested_receipt_type' => 'factura', 'requested_identity_type' => 'RUC',
            'requested_identity_number' => '20987654321', 'payment_status' => 'approved',
        ]);
        $path = 'electronic-invoices/testing/synthetic-cert.p12';
        Storage::disk('local')->put($path, 'synthetic');
        ElectronicInvoiceSetting::query()->create([
            'singleton_key' => 'primary', 'environment' => 'testing', 'connection_type' => 'direct_sunat',
            'certificate_path' => $path, 'certificate_expires_at' => now()->addDay(),
        ]);
        $document = ElectronicDocument::query()->create([
            'order_id' => $order->id, 'draft_reference' => (string) Str::uuid(), 'preparation_key' => (string) Str::uuid(),
            'document_type' => 'factura', 'status' => 'draft', 'environment' => 'testing', 'connection_type' => 'direct_sunat',
            'seller_snapshot' => ['commercial_name' => 'LubriStore QA', 'tax_id_number' => '20123456789', 'legal_name' => 'Emisor QA', 'country_code' => 'PE', 'fiscal_address' => 'Dirección QA'],
            'recipient_snapshot' => ['document_type' => 'RUC', 'document_number' => '20987654321', 'name' => 'Receptor QA'],
            'lines_snapshot' => ['items' => [[
                'name' => 'Producto QA', 'quantity' => 1, 'unit_price' => '118.00', 'subtotal' => '118.00',
                'tax' => ['calculation_status' => 'complete', 'tax_affectation' => ['code' => '10'], 'base' => '100.00', 'tax' => '18.00'],
            ]]],
            'amounts_snapshot' => ['total' => '118.00', 'operation_type' => '0101', 'payment_terms' => ['type' => 'cash', 'amount' => '118.00', 'source' => 'order_payment_status'], 'missing_fields' => [], 'tax_snapshot' => [
                'status' => 'complete', 'missing_fields' => [], 'totals' => ['taxable_base_minor' => '100.00', 'exempt_base_minor' => '0.00', 'unaffected_base_minor' => '0.00', 'tax_minor' => '18.00'],
            ]],
            'currency' => 'PEN', 'tax_calculation_status' => 'complete', 'version' => 1, 'prepared_at' => now(),
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'billing', 'is_active' => true]));

        $this->postJson('/api/v1/billing/electronic-documents/'.$document->id.'/issue', ['version' => 1])
            ->assertStatus(409)->assertJsonPath('message', 'Falta el esquema oficial UBL 2.1 completo o una dependencia local válida. No se asignó numeración.');
        $this->assertDatabaseHas('electronic_documents', ['id' => $document->id, 'status' => 'draft', 'series' => null, 'document_number' => null]);
        $this->assertDatabaseCount('electronic_document_attempts', 0);
        $this->assertDatabaseCount('electronic_document_series', 0);
        $this->assertDatabaseCount('electronic_document_outbox', 0);
    }

    public function test_signed_invoice_and_zip_pass_through_real_gateway_with_mocked_sunat_transport(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('The PHP ZIP extension is required for the synthetic gateway test.');
        }

        config([
            'electronic_invoicing.sunat.xsd_directory' => resource_path('sunat/xsd'),
            'electronic_invoicing.sunat.invoice_xsd' => resource_path('sunat/xsd/Archivos XSD/2.1/maindoc/UBL-Invoice-2.1.xsd'),
            'electronic_invoicing.sunat.testing_endpoint' => 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService',
        ]);
        Storage::fake('local');

        $syntheticCertificate = SyntheticCertificate::issue(['commonName' => '20123456789'], 'synthetic-pass');
        config(['electronic_invoicing.openssl_config' => $syntheticCertificate['openssl_config']]);
        $pkcs12 = $syntheticCertificate['pkcs12'];
        $certificatePem = $syntheticCertificate['certificate_pem'];
        Storage::disk('local')->put('electronic-invoices/testing/gateway-synthetic.p12', $pkcs12);
        ElectronicInvoiceSetting::query()->create([
            'singleton_key' => 'primary', 'environment' => 'testing', 'connection_type' => 'direct_sunat',
            'certificate_path' => 'electronic-invoices/testing/gateway-synthetic.p12',
            'certificate_password_encrypted' => 'synthetic-pass', 'certificate_expires_at' => now()->addDay(),
        ]);

        $document = new ElectronicDocument([
            'document_type' => 'factura', 'status' => 'queued', 'environment' => 'testing', 'connection_type' => 'direct_sunat',
            'series' => 'F009', 'document_number' => 17, 'confirmed_at' => now(), 'currency' => 'PEN',
            'seller_snapshot' => ['commercial_name' => 'LubriStore QA', 'tax_id_number' => '20123456789', 'legal_name' => 'LubriStore QA SAC', 'fiscal_address' => 'Dirección fiscal sintética', 'country_code' => 'PE'],
            'recipient_snapshot' => ['document_type' => 'RUC', 'document_number' => '20987654321', 'name' => 'Receptor QA'],
            'lines_snapshot' => ['items' => [[
                'name' => 'Aceite sintético QA', 'quantity' => 1, 'unit_price' => '118.00', 'subtotal' => '118.00',
                'tax' => ['tax_affectation' => ['code' => '10'], 'base' => '100.00', 'tax' => '18.00'],
            ]]],
            'amounts_snapshot' => [
                'total' => '118.00', 'operation_type' => '0101',
                'payment_terms' => ['type' => 'cash', 'amount' => '118.00', 'source' => 'order_payment_status'],
                'tax_snapshot' => ['totals' => [
                    'taxable_base_minor' => '100.00', 'exempt_base_minor' => '0.00',
                    'unaffected_base_minor' => '0.00', 'tax_minor' => '18.00', 'rate_basis_points' => 1800,
                ]],
            ],
        ]);
        $document->id = 789;
        $attempt = new ElectronicDocumentAttempt(['environment' => 'testing', 'connection_type' => 'direct_sunat']);

        $cdrPath = tempnam(sys_get_temp_dir(), 'synthetic-cdr-');
        $this->assertNotFalse($cdrPath);
        $cdrZip = new \ZipArchive;
        $this->assertTrue($cdrZip->open($cdrPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $this->assertTrue($cdrZip->addFromString('R-20123456789-F009-00000017.xml', '<ApplicationResponse><ResponseCode>0</ResponseCode><Description>CDR sintética aceptada</Description></ApplicationResponse>'));
        $this->assertTrue($cdrZip->close());
        $cdrBytes = file_get_contents($cdrPath);
        $this->assertIsString($cdrBytes);
        $soap = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><sendBillResponse xmlns="urn:test"><document>'.base64_encode($cdrBytes).'</document></sendBillResponse></soap:Body></soap:Envelope>';
        $history = [];
        $faultResponse = file_get_contents(base_path('tests/Fixtures/Sunat/beta-client-0160-fault.xml'));
        $this->assertIsString($faultResponse);
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'text/xml'], $soap),
            new Response(200, ['Content-Type' => 'text/xml; charset=utf-8'], $faultResponse),
            new Response(200, ['Content-Type' => 'text/xml'], str_repeat('x', 10 * 1024 * 1024 + 4096)),
        ]));
        $stack->push(Middleware::history($history));
        $gateway = new DirectSunatGateway(new SunatUblInvoiceBuilder(new MoneyService), new SunatXmlSignatureService, new SunatSchemaBundle, new Client(['handler' => $stack]));
        $tmpZipPath = null;

        try {
            $result = $gateway->send($document, $attempt);

            $this->assertSame('accepted', $result['status']);
            $this->assertSame('0', $result['response_code']);
            $this->assertSame('electronic-invoices/documents/789/20123456789-01-F009-00000017.xml', $result['xml_path']);
            $this->assertSame('electronic-invoices/documents/789/20123456789-01-F009-00000017.zip', $result['zip_path']);
            $this->assertSame('electronic-invoices/documents/789/20123456789-01-F009-00000017-response.xml', $result['response_path']);
            $this->assertCount(1, $history);
            $this->assertSame('https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService', (string) $history[0]['request']->getUri());
            $this->assertSame('text/xml; charset=utf-8', $history[0]['request']->getHeaderLine('Content-Type'));
            $this->assertSame('urn:sendBill', $history[0]['request']->getHeaderLine('SOAPAction'));
            $soapRequest = new \DOMDocument;
            $this->assertTrue($soapRequest->loadXML((string) $history[0]['request']->getBody(), LIBXML_NONET));
            $requestXPath = new \DOMXPath($soapRequest);
            $requestXPath->registerNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
            $this->assertSame('20123456789MODDATOS', $requestXPath->evaluate('string(//*[local-name()="Username"])'));
            $this->assertSame('MODDATOS', $requestXPath->evaluate('string(//*[local-name()="Password"])'));
            $this->assertSame('20123456789-01-F009-00000017.ZIP', $requestXPath->evaluate('string(//*[local-name()="fileName"])'));
            $sentZipBytes = base64_decode($requestXPath->evaluate('string(//*[local-name()="contentFile"])'), true);
            $this->assertIsString($sentZipBytes);
            $this->assertSame(hash('sha256', $sentZipBytes), hash_file('sha256', Storage::disk('local')->path($result['zip_path'])));
            $this->assertSame(hash('sha256', $sentZipBytes), hash('sha256', Storage::disk('local')->get($result['zip_path'])));
            $manifestPath = 'electronic-invoices/documents/789/20123456789-01-F009-00000017-transmission-evidence.json';
            $requestEvidencePath = 'electronic-invoices/documents/789/20123456789-01-F009-00000017-request-sanitized.xml';
            $manifest = json_decode(Storage::disk('local')->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
            $sanitizedSoap = Storage::disk('local')->get($requestEvidencePath);
            $this->assertSame('sendBill', $manifest['operation']);
            $this->assertSame('https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService', $manifest['endpoint']);
            $this->assertSame('urn:sendBill', $manifest['headers']['SOAPAction']);
            $this->assertSame('POST', $manifest['transmitted_request']['method']);
            $this->assertSame('https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService', $manifest['transmitted_request']['endpoint']);
            $this->assertSame('text/xml; charset=utf-8', $manifest['transmitted_request']['headers']['Content-Type']);
            $this->assertSame('urn:sendBill', $manifest['transmitted_request']['headers']['SOAPAction']);
            $this->assertSame(strlen((string) $history[0]['request']->getBody()), $manifest['transmitted_request']['body_bytes']);
            $this->assertSame(hash('sha256', $sentZipBytes), $manifest['request']['content_file_decoded_sha256']);
            $this->assertSame(hash('sha256', $sentZipBytes), $manifest['request']['zip_sha256']);
            $this->assertSame(hash('sha256', Storage::disk('local')->get($result['xml_path'])), $manifest['request']['xml_sha256']);
            $this->assertTrue($manifest['response_received']);
            $this->assertSame(200, $manifest['response']['http_status']);
            $this->assertSame(hash('sha256', Storage::disk('local')->get($result['response_path'])), $manifest['response']['sha256']);
            $this->assertStringContainsString('[REDACTED]', $sanitizedSoap);
            $this->assertStringNotContainsString('20123456789MODDATOS', $sanitizedSoap);
            $this->assertStringNotContainsString('MODDATOS</wsse:Password>', $sanitizedSoap);

            $tmpZipPath = tempnam(sys_get_temp_dir(), 'sent-invoice-');
            $this->assertNotFalse($tmpZipPath);
            $this->assertNotFalse(file_put_contents($tmpZipPath, $sentZipBytes));
            $archive = new \ZipArchive;
            $this->assertTrue($archive->open($tmpZipPath));
            $this->assertSame(2, $archive->numFiles);
            $this->assertNotFalse($archive->locateName('dummy/'));
            $xmlName = '20123456789-01-F009-00000017.xml';
            $this->assertNotFalse($archive->locateName($xmlName));
            $zippedXml = $archive->getFromName($xmlName);
            $archive->close();
            $this->assertIsString($zippedXml);
            $this->assertSame($zippedXml, Storage::disk('local')->get($result['xml_path']));
            $this->assertSame(hash('sha256', $zippedXml), hash_file('sha256', Storage::disk('local')->path($result['xml_path'])));
            $this->assertStringContainsString('F009-00000017', $zippedXml);

            $finalXml = new \DOMDocument;
            $finalXml->resolveExternals = false;
            $finalXml->substituteEntities = false;
            $this->assertTrue($finalXml->loadXML($zippedXml, LIBXML_NONET));
            $this->assertTrue((new SunatXmlSignatureService)->verifySerializedDocument($finalXml, $certificatePem));
            $this->assertTrue((new SunatSchemaBundle)->validate($finalXml));
            $xpath = new \DOMXPath($finalXml);
            $xpath->registerNamespace('inv', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
            $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
            $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
            $this->assertSame('LubriStore QA', $xpath->evaluate('string(/inv:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyName/cbc:Name)'));
            $this->assertSame('20123456789', $xpath->evaluate('string(/inv:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyIdentification/cbc:ID)'));
            $this->assertSame('20987654321', $xpath->evaluate('string(/inv:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyIdentification/cbc:ID)'));
            $this->assertSame('118.00', $xpath->evaluate('string(/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount)'));
            Storage::disk('local')->assertExists($result['cdr_path']);

            $faultDocument = clone $document;
            $faultDocument->id = 790;
            $faultAttempt = new ElectronicDocumentAttempt(['environment' => 'testing', 'connection_type' => 'direct_sunat']);
            $faultResult = $gateway->send($faultDocument, $faultAttempt);
            $this->assertSame('sunat_exception', $faultResult['status']);
            $this->assertSame('0160', $faultResult['response_code']);
            $this->assertStringContainsString('Client.0160', $faultResult['message']);
            $this->assertStringNotContainsString('details sanitized for tests', $faultResult['message']);
            $this->assertSame('electronic-invoices/documents/790/20123456789-01-F009-00000017-response.xml', $faultResult['response_path']);
            Storage::disk('local')->assertExists($faultResult['response_path']);
            $faultManifest = json_decode(Storage::disk('local')->get('electronic-invoices/documents/790/20123456789-01-F009-00000017-transmission-evidence.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('soap_fault', $faultManifest['result']['classification']);
            $this->assertSame('0160', $faultManifest['result']['response_code']);

            $largeResponseDocument = clone $document;
            $largeResponseDocument->id = 791;
            $largeResponseResult = $gateway->send($largeResponseDocument, new ElectronicDocumentAttempt(['environment' => 'testing', 'connection_type' => 'direct_sunat']));
            $this->assertSame('uncertain', $largeResponseResult['status']);
            $this->assertSame('response_too_large', $largeResponseResult['response_code']);
            $this->assertSame('electronic-invoices/documents/791/20123456789-01-F009-00000017-response.truncated.bin', $largeResponseResult['response_path']);
            $this->assertLessThanOrEqual(10 * 1024 * 1024, Storage::disk('local')->size($largeResponseResult['response_path']));
            $largeManifest = json_decode(Storage::disk('local')->get('electronic-invoices/documents/791/20123456789-01-F009-00000017-transmission-evidence.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($largeManifest['response']['truncated']);
            $this->assertSame(10 * 1024 * 1024, $largeManifest['response']['captured_bytes']);
            $this->assertSame(10 * 1024 * 1024 + 4096, $largeManifest['response']['total_bytes']);
            $this->assertSame('response_too_large', $largeManifest['result']['classification']);
            $this->assertCount(3, $history);
        } finally {
            if (is_file($cdrPath)) {
                unlink($cdrPath);
            }
            if (is_string($tmpZipPath) && is_file($tmpZipPath)) {
                unlink($tmpZipPath);
            }
        }
    }

    public function test_beta_cdr_query_is_controlled_without_calling_the_unsupported_operation(): void
    {
        $order = $this->order(User::factory()->create(['role' => 'customer']), '118.00');
        $document = ElectronicDocument::query()->create([
            'order_id' => $order->id, 'draft_reference' => (string) Str::uuid(), 'preparation_key' => (string) Str::uuid(),
            'document_type' => 'factura', 'status' => 'uncertain', 'environment' => 'testing', 'connection_type' => 'direct_sunat',
            'seller_snapshot' => ['tax_id_number' => 'synthetic'], 'recipient_snapshot' => [], 'lines_snapshot' => [], 'amounts_snapshot' => ['total' => '118.00'],
            'currency' => 'PEN', 'tax_calculation_status' => 'complete', 'version' => 1, 'prepared_at' => now(),
            'series' => 'F001', 'document_number' => 1,
        ]);
        $attempt = ElectronicDocumentAttempt::query()->create([
            'electronic_document_id' => $document->id, 'attempt_number' => 1, 'connection_type' => 'direct_sunat',
            'environment' => 'testing', 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
            'request_snapshot' => [], 'status' => 'uncertain', 'started_at' => now(),
        ]);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([]));
        $stack->push(Middleware::history($history));
        $gateway = new DirectSunatGateway(new SunatUblInvoiceBuilder(new MoneyService), new SunatXmlSignatureService, new SunatSchemaBundle, new Client(['handler' => $stack]));

        $result = $gateway->query($attempt);

        $this->assertSame('uncertain', $result['status']);
        $this->assertSame('beta_query_not_supported', $result['response_code']);
        $this->assertCount(0, $history);
        $this->assertSame('F001', $document->fresh()->series);
        $this->assertSame(1, (int) $document->fresh()->document_number);
    }

    public function test_beta_query_endpoint_does_not_record_a_fake_status_query(): void
    {
        $order = $this->order(User::factory()->create(['role' => 'customer']), '118.00');
        $document = ElectronicDocument::query()->create([
            'order_id' => $order->id, 'draft_reference' => (string) Str::uuid(), 'preparation_key' => (string) Str::uuid(),
            'document_type' => 'factura', 'status' => 'uncertain', 'environment' => 'testing', 'connection_type' => 'direct_sunat',
            'seller_snapshot' => [], 'recipient_snapshot' => [], 'lines_snapshot' => [], 'amounts_snapshot' => ['total' => '118.00'],
            'currency' => 'PEN', 'tax_calculation_status' => 'pending_definition', 'version' => 2, 'prepared_at' => now(),
            'series' => 'F001', 'document_number' => 95,
        ]);
        ElectronicDocumentAttempt::query()->create([
            'electronic_document_id' => $document->id, 'attempt_number' => 1, 'connection_type' => 'direct_sunat',
            'environment' => 'testing', 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('c', 64),
            'request_snapshot' => [], 'status' => 'uncertain', 'started_at' => now(),
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'billing', 'is_active' => true]));

        $this->postJson('/api/v1/billing/electronic-documents/'.$document->id.'/query', [])
            ->assertStatus(409)
            ->assertJsonPath('message', 'SUNAT Beta no ofrece consulta de estado para este envío síncrono. No se registró una consulta.');

        $this->assertDatabaseCount('electronic_document_events', 0);
        $this->assertDatabaseHas('electronic_documents', ['id' => $document->id, 'status' => 'uncertain', 'document_number' => 95]);
        $this->assertDatabaseHas('electronic_document_attempts', ['electronic_document_id' => $document->id, 'status' => 'uncertain']);
    }

    public function test_sunat_soap_exception_is_persisted_without_releasing_the_confirmed_number(): void
    {
        $actor = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        $order = $this->order(User::factory()->create(['role' => 'customer']), '118.00');
        $document = ElectronicDocument::query()->create([
            'order_id' => $order->id, 'draft_reference' => (string) Str::uuid(), 'preparation_key' => (string) Str::uuid(),
            'document_type' => 'factura', 'status' => 'queued', 'environment' => 'testing', 'connection_type' => 'direct_sunat',
            'seller_snapshot' => [], 'recipient_snapshot' => [], 'lines_snapshot' => [], 'amounts_snapshot' => ['total' => '118.00'],
            'currency' => 'PEN', 'tax_calculation_status' => 'complete', 'version' => 1, 'prepared_at' => now(),
            'series' => 'F001', 'document_number' => 94, 'confirmed_by' => $actor->id, 'confirmed_at' => now(),
        ]);
        $attempt = ElectronicDocumentAttempt::query()->create([
            'electronic_document_id' => $document->id, 'initiated_by' => $actor->id, 'attempt_number' => 1,
            'connection_type' => 'direct_sunat', 'environment' => 'testing', 'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('b', 64), 'request_snapshot' => [], 'status' => 'queued', 'started_at' => now(),
        ]);
        $gateway = \Mockery::mock(ElectronicInvoiceGateway::class);
        $gateway->shouldReceive('send')->once()->andReturn([
            'status' => 'sunat_exception', 'response_code' => '0160',
            'message' => 'SUNAT respondió con Client.0160 (XML vacío o error de tamaño). Se conserva la numeración; revise el ZIP y el XML antes de cualquier reintento.',
        ]);

        (new ProcessElectronicDocument($document->id, $attempt->id))->handle($gateway);

        $this->assertDatabaseHas('electronic_documents', ['id' => $document->id, 'status' => 'sunat_exception', 'result_code' => '0160', 'series' => 'F001', 'document_number' => 94]);
        $this->assertDatabaseHas('electronic_document_attempts', ['id' => $attempt->id, 'status' => 'sunat_exception', 'response_code' => '0160']);
        $this->assertDatabaseCount('electronic_document_attempts', 1);
        $resource = (new ElectronicDocumentResource($document->fresh()))->resolve();
        $this->assertSame('sunat_exception', $resource['status']);
        $this->assertSame('Excepción SOAP de SUNAT; numeración conservada', $resource['status_label']);
    }

    public function test_admin_configures_operational_mode_but_unsupported_issue_is_controlled_and_does_not_fake_acceptance(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/electronic-invoicing/settings')->assertOk()
            ->assertJsonPath('data.environment', 'testing')->assertJsonPath('data.connection_available', false)
            ->assertJsonPath('data.readiness.tax_rules', false)
            ->assertJsonPath('data.readiness.commercial_enabled', true)
            ->assertJsonPath('data.readiness.zip_runtime', class_exists(\ZipArchive::class))
            ->assertJsonPath('data.readiness.durable_queue', Schema::hasTable('jobs'))
            ->assertJsonPath('data.ready', false);
        $this->putJson('/api/v1/admin/electronic-invoicing/settings', [
            'connection_type' => 'provider_api', 'provider_name' => 'provider-test', 'environment' => 'testing', 'version' => 1,
        ])->assertOk()->assertJsonPath('data.connection_available', false)->assertJsonPath('data.environment', 'testing');

        $order = $this->order(User::factory()->create(['role' => 'customer']), '75.00');
        $order->update([
            'tax_calculation_status' => 'complete',
            'tax_calculation_version' => 'peruvian-inclusive-tax-v1',
            'tax_snapshot' => ['status' => 'complete', 'missing_fields' => [], 'totals' => ['gross_minor' => '75.00']],
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'billing', 'is_active' => true]));
        $id = $this->postJson('/api/v1/billing/orders/'.$order->id.'/electronic-documents', ['preparation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/billing/electronic-documents/'.$id.'/issue', ['version' => 1])->assertStatus(503)->assertJsonPath('code', 'connection_not_implemented');
        $this->assertDatabaseHas('electronic_documents', ['id' => $id, 'status' => 'draft']);
        $this->assertDatabaseCount('electronic_document_attempts', 0);
    }

    public function test_billing_draft_reads_the_order_tax_snapshot_without_recalculating_it(): void
    {
        config(['electronic_invoicing.enabled' => true]);
        BusinessProfile::create([
            'singleton_key' => 'primary', 'commercial_name' => 'LubriStore QA', 'legal_name' => 'LubriStore QA SAC',
            'tax_id_type' => 'RUC', 'tax_id_number' => '20123456789', 'country_code' => 'PE',
            'fiscal_address' => 'Dirección fiscal sintética', 'boleta_enabled' => true,
        ]);
        $order = $this->order(User::factory()->create(['role' => 'customer']), '118.00');
        $lineTax = [
            'calculation_status' => 'complete',
            'tax_affectation' => ['code' => '10', 'label' => 'Gravado: operación onerosa'],
            'quantity' => 1, 'regular_unit_gross' => '118.00', 'applied_unit_gross' => '118.00',
            'promotion_discount_gross' => '0.00', 'gross' => '118.00', 'base' => '100.00',
            'tax' => '18.00', 'igv' => '15.50', 'ipm' => '2.50', 'missing_fields' => [],
        ];
        $taxSnapshot = [
            'status' => 'complete', 'calculation_version' => 'peruvian-inclusive-tax-v1',
            'configuration_version' => 2, 'currency' => 'PEN', 'prices_include_tax' => true,
            'lines' => [$lineTax], 'shipping' => ['status' => 'complete', 'gross' => '0.00', 'base' => '0.00', 'tax' => '0.00'],
            'totals' => ['gross_minor' => '118.00', 'taxable_base_minor' => '100.00', 'igv_minor' => '15.50', 'ipm_minor' => '2.50', 'tax_minor' => '18.00'],
            'missing_fields' => [],
        ];
        $order->update(['tax_calculation_status' => 'complete', 'tax_calculation_version' => 'peruvian-inclusive-tax-v1', 'tax_snapshot' => $taxSnapshot]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => null, 'product_name' => 'Aceite snapshot', 'quantity' => 1,
            'regular_price' => '118.00', 'price' => '118.00', 'subtotal' => '118.00', 'tax_snapshot' => $lineTax,
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'billing', 'is_active' => true]));
        $response = $this->postJson('/api/v1/billing/orders/'.$order->id.'/electronic-documents', ['preparation_key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.tax_calculation_status', 'complete')
            ->assertJsonPath('data.lines.items.0.tax.base', '100.00')
            ->assertJsonPath('data.lines.items.0.tax.tax', '18.00')
            ->assertJsonPath('data.amounts.tax_snapshot.totals.igv_minor', '15.50')
            ->assertJsonPath('data.amounts.missing_fields', []);
        $this->assertSame('complete', $order->fresh()->tax_calculation_status);
        $this->assertSame('118.00', $order->fresh()->total);
        $this->assertDatabaseCount('electronic_document_attempts', 0);
        $this->assertNotEmpty($response->json('data.lines.items.0.tax'));
    }

    public function test_document_routes_remain_separate_from_treasury_and_customers(): void
    {
        $order = $this->order(User::factory()->create(['role' => 'customer']), '10.00');
        foreach (['customer', 'treasury'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
            $this->getJson('/api/v1/billing/orders/'.$order->id)->assertForbidden();
            $this->getJson('/api/v1/admin/electronic-invoicing/settings')->assertForbidden();
        }
        Sanctum::actingAs(User::factory()->create(['role' => 'billing', 'is_active' => true]));
        $this->getJson('/api/v1/admin/electronic-invoicing/settings')->assertForbidden();
    }

    public function test_private_test_artifacts_are_downloadable_only_inside_billing_scope(): void
    {
        Storage::fake('local');
        $order = $this->order(User::factory()->create(['role' => 'customer']), '40.00');
        $document = ElectronicDocument::query()->create([
            'order_id' => $order->id, 'draft_reference' => (string) Str::uuid(),
            'preparation_key' => (string) Str::uuid(), 'document_type' => 'factura', 'status' => 'accepted',
            'environment' => 'testing', 'connection_type' => 'direct_sunat', 'seller_snapshot' => [],
            'recipient_snapshot' => [], 'lines_snapshot' => [], 'amounts_snapshot' => [], 'currency' => 'PEN',
            'tax_calculation_status' => 'complete', 'version' => 2, 'prepared_at' => now(),
            'series' => 'F009', 'document_number' => 17,
        ]);
        $path = 'electronic-invoices/documents/'.$document->id.'/signed.xml';
        Storage::disk('local')->put($path, '<Invoice>synthetic</Invoice>');
        $document->update(['xml_path' => $path]);

        Sanctum::actingAs(User::factory()->create(['role' => 'billing', 'is_active' => true]));
        $detail = $this->getJson('/api/v1/billing/electronic-documents/'.$document->id)
            ->assertOk()->assertJsonPath('data.files.xml', true);
        $this->assertArrayNotHasKey('xml_path', $detail->json('data'));
        $this->get('/api/v1/billing/electronic-documents/'.$document->id.'/files/xml')
            ->assertOk()->assertStreamedContent('<Invoice>synthetic</Invoice>');
        $this->get('/api/v1/billing/electronic-documents/'.$document->id.'/files/archive')->assertNotFound();

        Sanctum::actingAs(User::factory()->create(['role' => 'customer', 'is_active' => true]));
        $this->getJson('/api/v1/billing/electronic-documents/'.$document->id)->assertForbidden();
        $this->get('/api/v1/billing/electronic-documents/'.$document->id.'/files/xml')->assertForbidden();
    }

    public function test_testing_invoice_pdf_is_rendered_from_snapshots_and_download_is_authorized(): void
    {
        $order = $this->order(User::factory()->create(['role' => 'customer']), '118.00');
        $document = ElectronicDocument::query()->create([
            'order_id' => $order->id, 'draft_reference' => (string) Str::uuid(),
            'preparation_key' => (string) Str::uuid(), 'document_type' => 'factura', 'status' => 'accepted_observed',
            'environment' => 'testing', 'connection_type' => 'direct_sunat',
            'seller_snapshot' => ['tax_id_number' => '20123456789', 'legal_name' => 'Emisor QA SAC', 'fiscal_address' => 'Dirección QA'],
            'recipient_snapshot' => ['document_type' => 'RUC', 'document_number' => '20987654321', 'name' => 'Receptor QA'],
            'lines_snapshot' => ['items' => [['name' => 'Aceite QA', 'quantity' => 1, 'unit_price' => '118.00', 'subtotal' => '118.00', 'tax' => ['base' => '100.00', 'tax' => '18.00']]]],
            'amounts_snapshot' => ['total' => '118.00', 'tax_snapshot' => ['totals' => ['taxable_base_minor' => '100.00', 'tax_minor' => '18.00']]],
            'currency' => 'PEN', 'tax_calculation_status' => 'complete', 'version' => 2, 'prepared_at' => now(),
            'series' => 'F009', 'document_number' => 17,
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'billing', 'is_active' => true]));
        $this->getJson('/api/v1/billing/electronic-documents/'.$document->id)->assertOk()->assertJsonPath('data.files.pdf', true);
        $response = $this->get('/api/v1/billing/electronic-documents/'.$document->id.'/files/pdf')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('F009-00000017-PRUEBA.pdf', $response->headers->get('Content-Disposition'));
        $html = view('electronic-invoices.testing-invoice', [
            'document' => $document, 'seller' => $document->seller_snapshot, 'recipient' => $document->recipient_snapshot,
            'items' => data_get($document->lines_snapshot, 'items', []), 'tax' => data_get($document->amounts_snapshot, 'tax_snapshot', []),
            'amounts' => $document->amounts_snapshot, 'statusLabel' => 'Aceptada con observaciones en ambiente de pruebas',
        ])->render();
        $this->assertStringContainsString('COPIA DE PRUEBA', $html);
        $this->assertStringContainsString('Receptor QA', $html);
        $this->assertStringContainsString('Aceite QA', $html);
        $this->assertStringContainsString('118.00', $html);

        Sanctum::actingAs(User::factory()->create(['role' => 'customer', 'is_active' => true]));
        $this->get('/api/v1/billing/electronic-documents/'.$document->id.'/files/pdf')->assertForbidden();
    }

    public function test_outbox_publisher_is_durable_and_duplicate_jobs_send_only_once(): void
    {
        $actor = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        $order = $this->order(User::factory()->create(['role' => 'customer']), '118.00');
        $document = ElectronicDocument::query()->create([
            'order_id' => $order->id, 'draft_reference' => (string) Str::uuid(),
            'preparation_key' => (string) Str::uuid(), 'document_type' => 'factura', 'status' => 'queued',
            'environment' => 'testing', 'connection_type' => 'direct_sunat', 'seller_snapshot' => [], 'recipient_snapshot' => [],
            'lines_snapshot' => [], 'amounts_snapshot' => ['total' => '118.00'], 'currency' => 'PEN',
            'tax_calculation_status' => 'complete', 'version' => 2, 'prepared_at' => now(),
            'series' => 'F001', 'document_number' => 91, 'confirmed_by' => $actor->id, 'confirmed_at' => now(),
        ]);
        $attempt = ElectronicDocumentAttempt::query()->create([
            'electronic_document_id' => $document->id, 'initiated_by' => $actor->id, 'attempt_number' => 1,
            'connection_type' => 'direct_sunat', 'environment' => 'testing', 'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('b', 64), 'request_snapshot' => ['series' => 'F001', 'number' => 91],
            'status' => 'queued', 'started_at' => now(),
        ]);
        $outbox = ElectronicDocumentOutbox::query()->create(['electronic_document_attempt_id' => $attempt->id]);

        $publisher = app(ElectronicDocumentJobOutboxPublisher::class);
        $this->assertSame(1, $publisher->publishPendingForDocument($document->id));
        $this->assertSame(0, $publisher->publishPendingForDocument($document->id));
        $this->assertNotNull($outbox->fresh()->published_at);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'electronic-invoices')->count());

        $gateway = \Mockery::mock(ElectronicInvoiceGateway::class);
        $gateway->shouldReceive('send')->once()->andReturn([
            'status' => 'accepted', 'response_code' => '0', 'message' => 'Aceptada sintética',
            'response_path' => 'electronic-invoices/documents/91/response.xml',
        ]);
        $gateway->shouldNotReceive('query');
        app()->instance(ElectronicInvoiceGateway::class, $gateway);
        $job = new ProcessElectronicDocument($document->id, $attempt->id);
        $job->handle($gateway);
        $job->handle($gateway);

        $this->assertDatabaseHas('electronic_documents', ['id' => $document->id, 'status' => 'accepted', 'series' => 'F001', 'document_number' => 91]);
        $this->assertDatabaseHas('electronic_document_attempts', [
            'id' => $attempt->id, 'status' => 'accepted', 'response_path' => 'electronic-invoices/documents/91/response.xml',
        ]);
        $this->assertSame(1, DB::table('electronic_document_events')->where('electronic_document_id', $document->id)->where('event_type', 'processing_result')->count());
    }

    public function test_commit_before_publication_leaves_recoverable_outbox_row(): void
    {
        config([
            'electronic_invoicing.enabled' => true,
            'electronic_invoicing.sunat.xsd_directory' => base_path('tests/Fixtures/OasisUBL21'),
            'electronic_invoicing.sunat.invoice_xsd' => base_path('tests/Fixtures/OasisUBL21/xsd/maindoc/UBL-Invoice-2.1.xsd'),
        ]);
        Storage::fake('local');
        $billing = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        $order = $this->order(User::factory()->create(['role' => 'customer']), '118.00');
        $order->update([
            'requested_receipt_type' => 'factura', 'requested_identity_type' => 'RUC',
            'requested_identity_number' => '20987654321', 'payment_status' => 'approved',
        ]);
        $certificatePath = 'electronic-invoices/testing/outbox-synthetic.p12';
        Storage::disk('local')->put($certificatePath, 'synthetic-test-only');
        ElectronicInvoiceSetting::query()->create([
            'singleton_key' => 'primary', 'environment' => 'testing', 'connection_type' => 'direct_sunat',
            'certificate_path' => $certificatePath, 'certificate_expires_at' => now()->addDay(),
        ]);
        $document = ElectronicDocument::query()->create([
            'order_id' => $order->id, 'draft_reference' => (string) Str::uuid(), 'preparation_key' => (string) Str::uuid(),
            'document_type' => 'factura', 'status' => 'draft', 'environment' => 'testing', 'connection_type' => 'direct_sunat',
            'seller_snapshot' => ['commercial_name' => 'LubriStore QA', 'tax_id_number' => '20123456789', 'legal_name' => 'Emisor QA', 'country_code' => 'PE', 'fiscal_address' => 'Dirección QA'],
            'recipient_snapshot' => ['document_type' => 'RUC', 'document_number' => '20987654321', 'name' => 'Receptor QA'],
            'lines_snapshot' => ['items' => [[
                'name' => 'Producto QA', 'quantity' => 1, 'unit_price' => '118.00', 'subtotal' => '118.00',
                'tax' => ['calculation_status' => 'complete', 'tax_affectation' => ['code' => '10'], 'base' => '100.00', 'tax' => '18.00'],
            ]]],
            'amounts_snapshot' => ['total' => '118.00', 'operation_type' => '0101', 'payment_terms' => ['type' => 'cash', 'amount' => '118.00', 'source' => 'order_payment_status'], 'missing_fields' => [], 'tax_snapshot' => [
                'status' => 'complete', 'missing_fields' => [], 'totals' => ['taxable_base_minor' => '100.00', 'exempt_base_minor' => '0.00', 'unaffected_base_minor' => '0.00', 'tax_minor' => '18.00'],
            ]],
            'currency' => 'PEN', 'tax_calculation_status' => 'complete', 'version' => 1, 'prepared_at' => now(),
        ]);
        $publisher = \Mockery::mock(ElectronicDocumentJobOutboxPublisher::class);
        $publisher->shouldReceive('publishPendingForDocument')->once()->with($document->id)->andThrow(new \RuntimeException('simulated crash after commit'));
        app()->instance(ElectronicDocumentJobOutboxPublisher::class, $publisher);

        $confirmed = app(ElectronicDocumentEmissionService::class)->confirm($document, $billing, 1);
        $this->assertSame('queued', $confirmed->status);
        $this->assertSame(1, $confirmed->attempts()->count());
        $this->assertDatabaseHas('electronic_documents', ['id' => $document->id, 'status' => 'queued', 'series' => 'F001']);
        $this->assertDatabaseHas('electronic_document_outbox', ['electronic_document_attempt_id' => $confirmed->attempts->first()->id, 'published_at' => null]);
        $this->assertDatabaseCount('jobs', 0);

        app()->instance(ElectronicDocumentJobOutboxPublisher::class, new ElectronicDocumentJobOutboxPublisher);
        $this->assertSame(1, app(ElectronicDocumentJobOutboxPublisher::class)->publishPending(100));
        $this->assertDatabaseHas('electronic_document_outbox', ['electronic_document_attempt_id' => $confirmed->attempts->first()->id]);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'electronic-invoices')->count());
        $this->assertSame(1, DB::table('electronic_document_attempts')->where('electronic_document_id', $document->id)->count());
    }

    public function test_timeout_query_recovers_late_cdr_without_changing_the_confirmed_number(): void
    {
        $actor = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        $order = $this->order(User::factory()->create(['role' => 'customer']), '118.00');
        $document = ElectronicDocument::query()->create([
            'order_id' => $order->id, 'draft_reference' => (string) Str::uuid(), 'preparation_key' => (string) Str::uuid(),
            'document_type' => 'factura', 'status' => 'queued', 'environment' => 'testing', 'connection_type' => 'direct_sunat',
            'seller_snapshot' => [], 'recipient_snapshot' => [], 'lines_snapshot' => [], 'amounts_snapshot' => ['total' => '118.00'],
            'currency' => 'PEN', 'tax_calculation_status' => 'complete', 'version' => 2, 'prepared_at' => now(),
            'series' => 'F001', 'document_number' => 93, 'confirmed_by' => $actor->id, 'confirmed_at' => now(),
        ]);
        $attempt = ElectronicDocumentAttempt::query()->create([
            'electronic_document_id' => $document->id, 'initiated_by' => $actor->id, 'attempt_number' => 1,
            'connection_type' => 'direct_sunat', 'environment' => 'testing', 'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('d', 64), 'request_snapshot' => ['series' => 'F001', 'number' => 93],
            'status' => 'queued', 'started_at' => now(),
        ]);
        $gateway = \Mockery::mock(ElectronicInvoiceGateway::class);
        $gateway->shouldReceive('send')->once()->andReturn([
            'status' => 'uncertain', 'response_code' => 'transport_timeout',
            'message' => 'Respuesta externa no recibida; consulta requerida.',
        ]);
        $gateway->shouldNotReceive('query');
        $job = new ProcessElectronicDocument($document->id, $attempt->id);
        $job->handle($gateway);
        $this->assertDatabaseHas('electronic_documents', ['id' => $document->id, 'status' => 'uncertain', 'document_number' => 93]);

        $queryGateway = \Mockery::mock(ElectronicInvoiceGateway::class);
        $queryGateway->shouldReceive('query')->once()->with(\Mockery::on(fn ($value) => (int) $value->id === (int) $attempt->id))->andReturn([
            'status' => 'accepted_observed', 'response_code' => '0', 'message' => 'CDR tardía localizada.',
            'observations' => 'Observación sintética',
        ]);
        $queryGateway->shouldNotReceive('send');
        app()->instance(ElectronicInvoiceGateway::class, $queryGateway);
        $recovered = app(ElectronicDocumentEmissionService::class)->query($document->fresh(), $actor);
        $this->assertSame('accepted_observed', $recovered->status);
        $this->assertSame('F001', $recovered->series);
        $this->assertSame(93, (int) $recovered->document_number);
        $this->assertDatabaseHas('electronic_document_attempts', ['id' => $attempt->id, 'status' => 'accepted_observed', 'response_code' => '0']);
        $this->assertDatabaseCount('electronic_document_attempts', 1);
        $this->assertSame(1, DB::table('electronic_document_events')->where('electronic_document_id', $document->id)->where('event_type', 'processing_result')->count());
        $this->assertSame(1, DB::table('electronic_document_events')->where('electronic_document_id', $document->id)->where('event_type', 'status_queried')->count());
        $job->handle($queryGateway);
        $this->assertDatabaseHas('electronic_documents', ['id' => $document->id, 'status' => 'accepted_observed', 'document_number' => 93]);
    }

    public function test_stale_worker_is_marked_uncertain_and_late_duplicate_does_not_overwrite_terminal_result(): void
    {
        $actor = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        $order = $this->order(User::factory()->create(['role' => 'customer']), '118.00');
        $document = ElectronicDocument::query()->create([
            'order_id' => $order->id, 'draft_reference' => (string) Str::uuid(), 'preparation_key' => (string) Str::uuid(),
            'document_type' => 'factura', 'status' => 'processing', 'environment' => 'testing', 'connection_type' => 'direct_sunat',
            'seller_snapshot' => [], 'recipient_snapshot' => [], 'lines_snapshot' => [], 'amounts_snapshot' => ['total' => '118.00'],
            'currency' => 'PEN', 'tax_calculation_status' => 'complete', 'version' => 2, 'prepared_at' => now(),
            'series' => 'F001', 'document_number' => 92, 'confirmed_by' => $actor->id, 'confirmed_at' => now(),
        ]);
        $attempt = ElectronicDocumentAttempt::query()->create([
            'electronic_document_id' => $document->id, 'initiated_by' => $actor->id, 'attempt_number' => 1,
            'connection_type' => 'direct_sunat', 'environment' => 'testing', 'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('c', 64), 'request_snapshot' => ['series' => 'F001', 'number' => 92],
            'status' => 'processing', 'started_at' => now()->subMinutes(10),
        ]);
        ElectronicDocument::query()->whereKey($document->id)->update(['updated_at' => now()->subMinutes(10)]);
        ElectronicDocumentAttempt::query()->whereKey($attempt->id)->update(['updated_at' => now()->subMinutes(10)]);

        $this->assertSame(1, app(ElectronicDocumentRecoveryService::class)->recoverStaleProcessing(5));
        $this->assertSame(0, app(ElectronicDocumentRecoveryService::class)->recoverStaleProcessing(5));
        $this->assertDatabaseHas('electronic_documents', ['id' => $document->id, 'status' => 'uncertain', 'series' => 'F001', 'document_number' => 92]);
        $this->assertDatabaseHas('electronic_document_attempts', ['id' => $attempt->id, 'status' => 'uncertain']);
        $this->assertDatabaseCount('electronic_document_events', 1);

        $gateway = \Mockery::mock(ElectronicInvoiceGateway::class);
        $gateway->shouldNotReceive('send');
        $job = new ProcessElectronicDocument($document->id, $attempt->id);
        $job->handle($gateway);
        $this->assertDatabaseHas('electronic_documents', ['id' => $document->id, 'status' => 'uncertain']);
    }

    public function test_repeated_issue_request_returns_the_existing_confirmation_without_another_attempt_or_job(): void
    {
        config(['electronic_invoicing.enabled' => true]);
        $billing = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        $order = $this->order(User::factory()->create(['role' => 'customer']), '118.00');
        $confirmedAt = now()->subMinute();
        $document = ElectronicDocument::query()->create([
            'order_id' => $order->id, 'draft_reference' => (string) Str::uuid(),
            'preparation_key' => (string) Str::uuid(), 'document_type' => 'factura', 'status' => 'queued',
            'environment' => 'testing', 'connection_type' => 'direct_sunat',
            'seller_snapshot' => ['tax_id_number' => '20123456789'], 'recipient_snapshot' => [],
            'lines_snapshot' => [], 'amounts_snapshot' => ['total' => '118.00'], 'currency' => 'PEN',
            'tax_calculation_status' => 'complete', 'version' => 2, 'prepared_at' => $confirmedAt,
            'series' => 'F001', 'document_number' => 1, 'confirmed_by' => $billing->id, 'confirmed_at' => $confirmedAt,
        ]);
        ElectronicDocumentAttempt::query()->create([
            'electronic_document_id' => $document->id, 'initiated_by' => $billing->id, 'attempt_number' => 1,
            'connection_type' => 'direct_sunat', 'environment' => 'testing', 'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('a', 64), 'request_snapshot' => ['series' => 'F001', 'number' => 1],
            'status' => 'queued', 'started_at' => $confirmedAt,
        ]);
        Sanctum::actingAs($billing);

        $first = $this->postJson('/api/v1/billing/electronic-documents/'.$document->id.'/issue', ['version' => 2])
            ->assertAccepted()->assertJsonPath('data.id', $document->id)->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.series', 'F001')->assertJsonPath('data.document_number', 1);
        $second = $this->postJson('/api/v1/billing/electronic-documents/'.$document->id.'/issue', ['version' => 2])
            ->assertAccepted()->assertJsonPath('data.id', $document->id)->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.series', 'F001')->assertJsonPath('data.document_number', 1);

        $this->assertSame($first->json('data.confirmed_at'), $second->json('data.confirmed_at'));
        $this->assertSame($billing->id, $document->fresh()->confirmed_by);
        $this->assertSame(1, $document->attempts()->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame('118.00', $order->fresh()->total);
    }

    private function order(User $customer, string $total): Order
    {
        return Order::create([
            'user_id' => $customer->id, 'status' => 'pending', 'total' => $total, 'subtotal' => $total,
            'shipping_amount' => '0.00', 'discount_total' => '0.00', 'shipping_info' => [],
            'payment_method' => 'transferencia', 'payment_status' => 'pending', 'delivery_type' => 'pickup', 'tracking_status' => 'pending',
            'requested_receipt_type' => 'boleta', 'requested_recipient_name' => 'Receptor histórico',
            'requested_identity_type' => 'DNI', 'requested_identity_number' => '12345678',
            'requested_receipt_email' => 'receptor@example.test',
        ]);
    }
}
