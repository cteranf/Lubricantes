<?php

namespace Tests\Feature;

use App\Jobs\ProcessElectronicReceiptSummary;
use App\Models\BusinessProfile;
use App\Models\ElectronicDocument;
use App\Models\ElectronicInvoiceSetting;
use App\Models\ElectronicReceiptSummary;
use App\Models\ElectronicReceiptSummaryEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\ElectronicReceiptIssuanceService;
use App\Services\ElectronicReceiptSummaryCommunicationService;
use App\Services\ElectronicReceiptSummaryOutboxPublisher;
use App\Services\ElectronicReceiptSummaryRecoveryService;
use App\Services\SunatBetaElectronicReceiptSummaryTransport;
use App\Services\SunatReceiptSummaryCdrProcessor;
use App\Services\SunatSchemaBundle;
use App\Services\SunatUblReceiptSummaryBuilder;
use App\Services\SunatXmlSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\SyntheticCertificate;
use Tests\TestCase;

class ElectronicReceiptSummaryTest extends TestCase
{
    use RefreshDatabase;

    private array $certificate;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        if (DB::connection()->getDriverName() === 'mysql') {
            $expectedDatabase = (string) getenv('ELECTRONIC_RECEIPT_MYSQL_TEST_DB');
            $connection = DB::selectOne('SELECT DATABASE() AS db, @@default_storage_engine AS engine');
            $this->assertNotSame('', $expectedDatabase, 'MySQL receipt tests require an explicitly approved isolated database name.');
            $this->assertSame($expectedDatabase, $connection->db);
            $this->assertStringEndsWith('_test', $connection->db);
            $this->assertNotContains($connection->db, ['lubricantes', 'lubristore_remote_payments_test']);
            $this->assertSame('innodb', strtolower($connection->engine));
        }
        config([
            'electronic_invoicing.enabled' => true,
            'electronic_invoicing.sunat.xsd_directory' => resource_path('sunat/xsd'),
            'electronic_invoicing.sunat.invoice_xsd' => resource_path('sunat/xsd/Archivos XSD/2.1/maindoc/UBL-Invoice-2.1.xsd'),
            'electronic_invoicing.sunat.summary_xsd' => resource_path('sunat/xsd/Archivos XSD/2.0/maindoc/UBLPE-SummaryDocuments-1.0.xsd'),
        ]);
        Storage::fake('local');
        $this->certificate = SyntheticCertificate::issue(['commonName' => '20123456789'], 'synthetic-pass');
        config(['electronic_invoicing.openssl_config' => $this->certificate['openssl_config']]);
        Storage::disk('local')->put('electronic-invoices/testing/summary-synthetic.p12', $this->certificate['pkcs12']);
        ElectronicInvoiceSetting::query()->create([
            'singleton_key' => 'primary', 'environment' => 'testing', 'connection_type' => 'direct_sunat',
            'certificate_path' => 'electronic-invoices/testing/summary-synthetic.p12',
            'certificate_password_encrypted' => 'synthetic-pass', 'certificate_expires_at' => now()->addDay(),
        ]);
        $this->app->instance(ElectronicReceiptSummaryOutboxPublisher::class, new class
        {
            public function publishPending(?int $summaryId = null, int $limit = 100): int
            {
                return 0;
            }
        });
    }

    public function test_summary_confirmation_freezes_only_eligible_receipts_and_local_job_validates_signature_xsd_and_archive(): void
    {
        $actor = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        $document = $this->issueReceipt($this->eligibleReceipt(), $actor);
        Sanctum::actingAs($actor);

        $response = $this->postJson('/api/v1/billing/receipt-summaries', [
            'documents' => [['id' => $document->id, 'version' => $document->version]],
        ])->assertAccepted()->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.communication_mode', 'local_simulation');

        $summary = ElectronicReceiptSummary::query()->with('items.document')->findOrFail($response->json('data.id'));
        $this->assertSame('queued', $summary->status);
        $this->assertSame('ready_for_summary', $document->fresh()->status);
        $this->assertDatabaseHas('electronic_receipt_summary_outbox', [
            'electronic_receipt_summary_attempt_id' => $summary->attempts()->value('id'), 'published_at' => null,
        ]);
        $publisher = new ElectronicReceiptSummaryOutboxPublisher;
        $this->assertSame(1, $publisher->publishPending($summary->id));
        $this->assertSame(0, $publisher->publishPending($summary->id));
        $this->assertDatabaseCount('jobs', 1);

        app(ProcessElectronicReceiptSummary::class, ['summaryId' => $summary->id, 'attemptId' => $summary->attempts()->value('id')])
            ->handle(app(SunatUblReceiptSummaryBuilder::class), app(SunatXmlSignatureService::class), app(SunatSchemaBundle::class));

        $summary->refresh()->load(['items.document', 'attempts', 'events']);
        $this->assertSame('simulated_processing_pending', $summary->status);
        $this->assertStringStartsWith('LOCAL-SIM-', $summary->local_simulation_id);
        $this->assertNull($summary->external_ticket);
        $this->assertSame('LOCAL_SIM_TICKET_CREATED', $summary->result_code);
        $this->assertDatabaseHas('electronic_documents', ['id' => $document->id, 'series' => 'B001', 'document_number' => 1, 'status' => 'summary_pending']);
        $this->assertDatabaseHas('electronic_receipt_summary_items', [
            'electronic_receipt_summary_id' => $summary->id, 'electronic_document_id' => $document->id,
            'result_status' => 'simulated_ticket',
        ]);
        $this->assertFalse($summary->items->first()->result_status === 'accepted');
        $this->assertStringContainsString('hubo transmisión externa', $summary->result_message);
        $eventCount = $summary->events->count();
        app(ProcessElectronicReceiptSummary::class, ['summaryId' => $summary->id, 'attemptId' => $summary->attempts->first()->id])
            ->handle(app(SunatUblReceiptSummaryBuilder::class), app(SunatXmlSignatureService::class), app(SunatSchemaBundle::class));
        $this->assertSame($eventCount, $summary->fresh()->events()->count());

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($summary->zip_path)));
        $this->assertSame(2, $zip->numFiles);
        $this->assertNotFalse($zip->locateName('dummy/'));
        $xmlIndex = $zip->locateName($summary->issuer_tax_id.'-'.$summary->document_id.'.xml');
        $this->assertNotFalse($xmlIndex);
        $xmlName = $zip->getNameIndex($xmlIndex);
        $this->assertSame($summary->xml_hash, hash('sha256', $zip->getFromIndex($xmlIndex)));
        $this->assertSame($summary->issuer_tax_id.'-'.$summary->document_id.'.xml', $xmlName);
        $zip->close();
        $this->assertDatabaseCount('electronic_document_attempts', 0);
        $this->assertSame('approved', Order::query()->findOrFail($document->order_id)->payment_status);
    }

    public function test_a_receipt_cannot_be_confirmed_twice_and_ineligible_tax_or_partial_payment_is_blocked(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $first = $this->issueReceipt($this->eligibleReceipt(), $actor);
        Sanctum::actingAs($actor);
        $payload = ['documents' => [['id' => $first->id, 'version' => $first->version]]];
        $this->postJson('/api/v1/billing/receipt-summaries', $payload)->assertAccepted();
        $this->postJson('/api/v1/billing/receipt-summaries', $payload)->assertStatus(409);

        $second = $this->eligibleReceipt(false);
        $this->postJson('/api/v1/billing/electronic-documents/'.$second->id.'/issue', ['version' => 1])->assertStatus(409);
        $this->assertDatabaseHas('electronic_documents', ['id' => $second->id, 'status' => 'draft', 'series' => null]);
        $this->assertDatabaseCount('electronic_receipt_summaries', 1);
    }

    public function test_partially_overlapping_selections_cannot_confirm_a_receipt_in_two_summaries(): void
    {
        $actor = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        $first = $this->eligibleReceipt();
        $shared = $this->eligibleReceipt();
        $last = $this->eligibleReceipt();
        $first = $this->issueReceipt($first, $actor);
        $shared = $this->issueReceipt($shared, $actor);
        $last = $this->issueReceipt($last, $actor);
        Sanctum::actingAs($actor);

        $firstResponse = $this->postJson('/api/v1/billing/receipt-summaries', [
            'documents' => [
                ['id' => $first->id, 'version' => $first->version],
                ['id' => $shared->id, 'version' => $shared->version],
            ],
        ])->assertAccepted();

        $summaryId = (int) $firstResponse->json('data.id');
        $this->postJson('/api/v1/billing/receipt-summaries', [
            'documents' => [
                ['id' => $shared->id, 'version' => $shared->version],
                ['id' => $last->id, 'version' => $last->version],
            ],
        ])->assertStatus(409);

        $this->assertDatabaseCount('electronic_receipt_summaries', 1);
        $this->assertDatabaseCount('electronic_receipt_summary_items', 2);
        $this->assertDatabaseHas('electronic_receipt_summary_items', [
            'electronic_receipt_summary_id' => $summaryId,
            'electronic_document_id' => $shared->id,
        ]);
        $this->assertDatabaseMissing('electronic_receipt_summary_items', ['electronic_document_id' => $last->id]);
        $this->assertDatabaseHas('electronic_documents', ['id' => $shared->id, 'status' => 'ready_for_summary', 'document_number' => 2]);
        $this->assertDatabaseHas('electronic_documents', ['id' => $last->id, 'status' => 'ready_for_summary', 'series' => 'B001']);
    }

    public function test_other_roles_cannot_read_or_prepare_receipt_summaries_and_disabled_module_blocks_writes(): void
    {
        $historicalSummary = ElectronicReceiptSummary::query()->create([
            'issuer_tax_id' => '20123456789', 'environment' => 'testing', 'issue_date' => now()->toDateString(),
            'sequence' => 1, 'document_id' => 'RC-'.now()->format('Ymd').'-1', 'status' => 'simulated_ticket_created',
            'document_count' => 1, 'snapshot' => ['communication_mode' => 'local_simulation'],
            'xml_path' => 'electronic-invoices/summaries/1/summary.xml', 'zip_path' => 'electronic-invoices/summaries/1/summary.zip',
            'confirmed_at' => now(),
        ]);
        Storage::disk('local')->put($historicalSummary->xml_path, '<summary/>');
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/billing/receipt-summaries')->assertForbidden();
        $this->getJson('/api/v1/billing/receipt-summaries/'.$historicalSummary->id)->assertForbidden();
        $this->get('/api/v1/billing/receipt-summaries/'.$historicalSummary->id.'/files/xml')->assertForbidden();
        $this->postJson('/api/v1/billing/receipt-summaries', ['documents' => [['id' => 1, 'version' => 1]]])->assertForbidden();

        $billing = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        Sanctum::actingAs($billing);
        config(['electronic_invoicing.enabled' => false]);
        $this->getJson('/api/v1/billing/receipt-summaries')->assertOk();
        $this->getJson('/api/v1/billing/receipt-summaries/'.$historicalSummary->id)->assertOk();
        $this->postJson('/api/v1/billing/receipt-summaries', ['documents' => [['id' => 1, 'version' => 1]]])->assertStatus(409);
    }

    public function test_confirmed_summary_migration_cannot_be_rolled_back_and_stale_local_worker_is_not_requeued(): void
    {
        $actor = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        $document = $this->issueReceipt($this->eligibleReceipt(), $actor);
        Sanctum::actingAs($actor);
        $response = $this->postJson('/api/v1/billing/receipt-summaries', [
            'documents' => [['id' => $document->id, 'version' => $document->version]],
        ])->assertAccepted();
        $summary = ElectronicReceiptSummary::query()->findOrFail($response->json('data.id'));
        $attempt = $summary->attempts()->firstOrFail();
        $summary->forceFill(['status' => 'processing', 'updated_at' => now()->subMinutes(10)])->save();
        $attempt->forceFill(['status' => 'processing', 'updated_at' => now()->subMinutes(10)])->save();

        $recovered = app(ElectronicReceiptSummaryRecoveryService::class)->recoverStaleProcessing();
        $this->assertSame(1, $recovered);
        $this->assertSame('local_preparation_interrupted', $summary->fresh()->status);
        $this->assertSame('local_preparation_interrupted', $attempt->fresh()->status);
        $this->assertSame(1, $document->fresh()->document_number);
        $this->assertSame('summary_failed', $document->fresh()->status);
        $this->assertDatabaseCount('electronic_receipt_summary_outbox', 1);

        $migration = require base_path('database/migrations/2026_11_22_071400_create_electronic_receipt_summaries.php');
        try {
            $migration->down();
            $this->fail('The migration must preserve confirmed summary and ticket history.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('resúmenes confirmados', $exception->getMessage());
        }
        $this->assertSame('local_preparation_interrupted', $summary->fresh()->status);
        $this->assertSame(1, $document->fresh()->document_number);
    }

    public function test_unsupported_boleta_recipient_identity_is_rejected_before_allocating_numbers(): void
    {
        $actor = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        $document = $this->eligibleReceipt();
        $document->recipient_snapshot = [
            ...$document->recipient_snapshot,
            'document_type' => 'UNSUPPORTED',
        ];
        $document->save();
        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/billing/electronic-documents/'.$document->id.'/issue', ['version' => 1])
            ->assertStatus(409)->assertJsonPath('message', 'La boleta requiere revisión antes de generar el documento: receipt_recipient_identity_type_unsupported.');

        $issuer = data_get($document->seller_snapshot, 'tax_id_number');
        $this->assertDatabaseHas('electronic_documents', ['id' => $document->id, 'status' => 'draft', 'series' => null, 'document_number' => null]);
        $this->assertSame(0, DB::table('electronic_document_series')->where('issuer_tax_id', $issuer)->count());
        $this->assertDatabaseCount('electronic_receipt_summaries', 0);
        $this->assertDatabaseCount('electronic_receipt_summary_attempts', 0);
    }

    public function test_summary_xml_has_official_version_dates_and_is_rejected_when_snapshot_contains_unsupported_data(): void
    {
        $actor = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        $document = $this->issueReceipt($this->eligibleReceipt(), $actor);
        Sanctum::actingAs($actor);
        $response = $this->postJson('/api/v1/billing/receipt-summaries', [
            'documents' => [['id' => $document->id, 'version' => $document->version]],
        ])->assertAccepted();
        $summary = ElectronicReceiptSummary::query()->findOrFail($response->json('data.id'));
        $xml = app(SunatUblReceiptSummaryBuilder::class)->build($summary)->saveXML();
        $this->assertIsString($xml);
        $this->assertStringContainsString('<cbc:UBLVersionID>2.0</cbc:UBLVersionID>', $xml);
        $this->assertStringContainsString('<cbc:CustomizationID>1.1</cbc:CustomizationID>', $xml);
        $dom = new \DOMDocument;
        $this->assertTrue($dom->loadXML($xml, LIBXML_NONET));
        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $bundle = app(SunatSchemaBundle::class)->inspect((string) config('electronic_invoicing.sunat.summary_xsd'));
        $valid = $dom->schemaValidate($bundle['root'], LIBXML_NONET);
        $errors = array_map(static fn (\LibXMLError $error): string => trim($error->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        $this->assertTrue($valid, implode('; ', $errors));
    }

    public function test_individual_boleta_is_numbered_signed_stored_privately_and_rendered_as_testing_pdf_before_summary(): void
    {
        $actor = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        $draft = $this->eligibleReceipt();
        Sanctum::actingAs($actor);

        $response = $this->postJson('/api/v1/billing/electronic-documents/'.$draft->id.'/issue', ['version' => 1])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'ready_for_summary')
            ->assertJsonPath('data.series', 'B001')
            ->assertJsonPath('data.document_number', 1)
            ->assertJsonPath('data.files.pdf', true);

        $issued = $draft->fresh();
        $this->assertSame('ready_for_summary', $issued->status);
        $this->assertTrue(Storage::disk('local')->exists($issued->xml_path));
        $this->assertTrue(Storage::disk('local')->exists($issued->zip_path));
        $xml = Storage::disk('local')->get($issued->xml_path);
        $this->assertSame(hash('sha256', $xml), $issued->xml_hash);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode', $xml);
        $this->assertSame(1, DB::table('electronic_document_series')->where('document_type', 'boleta')->value('next_number') - 1);
        $this->assertSame($issued->seller_snapshot, $response->json('data.seller'));
        $this->assertSame($issued->recipient_snapshot, $response->json('data.recipient'));

        $again = $this->postJson('/api/v1/billing/electronic-documents/'.$draft->id.'/issue', ['version' => 1]);
        $again->assertAccepted()->assertJsonPath('data.document_number', 1);
        $this->assertSame(1, DB::table('electronic_document_series')->where('document_type', 'boleta')->value('next_number') - 1);
        $this->assertSame(1, DB::table('electronic_document_events')->where('electronic_document_id', $draft->id)->where('event_type', 'numbered_and_frozen')->count());

        $pdf = $this->get('/api/v1/billing/electronic-documents/'.$draft->id.'/files/pdf')->assertOk()
            ->assertHeader('X-Receipt-Simulation', 'local-only')
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('SIMULACION-B001-00000001-PRUEBA.pdf', $pdf->headers->get('content-disposition'));
        $this->get('/api/v1/billing/electronic-documents/'.$draft->id.'/files/xml')->assertOk()
            ->assertHeader('X-Receipt-Simulation', 'local-only');
        $this->get('/api/v1/billing/electronic-documents/'.$draft->id.'/files/cdr')->assertNotFound();
    }

    public function test_simulated_summary_scenarios_update_communication_and_receipt_states_without_creating_cdrs(): void
    {
        $actor = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        Sanctum::actingAs($actor);
        $scenarios = [
            'pending' => ['simulated_processing_pending', 'summary_pending', 'ticket_pending', true],
            'accepted' => ['simulated_accepted', 'reported', 'accepted', true],
            'accepted_observed' => ['simulated_accepted_observed', 'reported', 'accepted_observed', true],
            'rejected' => ['simulated_rejected', 'summary_rejected', 'rejected', true],
            'soap_fault' => ['simulated_soap_fault', 'summary_failed', 'soap_fault', false],
            'http_error' => ['simulated_http_error', 'summary_failed', 'http_error', false],
            'timeout_before_ticket' => ['simulated_timeout_uncertain', 'summary_uncertain', 'uncertain', false],
        ];

        foreach ($scenarios as $scenario => [$summaryStatus, $documentStatus, $communicationStatus, $hasLocalId]) {
            config(['electronic_invoicing.receipt_simulation.scenario' => $scenario]);
            $document = $this->issueReceipt($this->eligibleReceipt(), $actor);
            $summary = $this->createAndProcessSummary($document, $actor);
            if (in_array($scenario, ['accepted', 'accepted_observed', 'rejected', 'pending'], true)) {
                $summary = ElectronicReceiptSummary::query()->findOrFail($summary->id);
                $this->postJson('/api/v1/billing/receipt-summaries/'.$summary->id.'/query')->assertOk();
                $summary->refresh();
            }
            $this->assertSame($summaryStatus, $summary->status, $scenario);
            $this->assertSame($communicationStatus, $summary->communication_status, $scenario);
            $this->assertSame($hasLocalId, is_string($summary->local_simulation_id), $scenario);
            $this->assertNull($summary->external_ticket, $scenario);
            $this->assertNull($summary->ticket, $scenario);
            $this->assertDatabaseHas('electronic_documents', ['id' => $document->id, 'status' => $documentStatus]);
            $this->assertDatabaseMissing('electronic_documents', ['id' => $document->id, 'cdr_path' => '']);
            $this->assertDatabaseMissing('electronic_receipt_summaries', ['id' => $summary->id, 'response_path' => 'synthetic-cdr.xml']);
        }

        foreach (['timeout_after_ticket' => 2, 'late_response' => 2, 'duplicate_response' => 1] as $scenario => $queries) {
            config(['electronic_invoicing.receipt_simulation.scenario' => $scenario]);
            $document = $this->issueReceipt($this->eligibleReceipt(), $actor);
            $summary = $this->createAndProcessSummary($document, $actor);
            $this->assertNotNull($summary->local_simulation_id, $scenario);
            for ($i = 0; $i < $queries; $i++) {
                $summary = ElectronicReceiptSummary::query()->findOrFail($summary->id);
                $this->postJson('/api/v1/billing/receipt-summaries/'.$summary->id.'/query')->assertOk()
                    ->assertJsonPath('data.simulated', true)
                    ->assertJsonPath('data.external_ticket', null);
            }
            $this->assertSame('simulated_accepted', $summary->fresh()->status, $scenario);
            $this->assertSame('reported', $document->fresh()->status, $scenario);
            $this->assertNull($document->fresh()->cdr_path, $scenario);
            $this->assertSame($queries, (int) $summary->attempts()->first()->query_count, $scenario);

            if ($scenario === 'late_response') {
                $terminal = $summary->fresh();
                $attempt = $terminal->attempts()->firstOrFail();
                app(ElectronicReceiptSummaryCommunicationService::class)->persistQueryResponse(
                    (int) $terminal->id,
                    (int) $attempt->id,
                    [
                        'simulated' => true,
                        'type' => 'rejected',
                        'code' => 'SIMULATED_LATE_REJECTION',
                        'message' => 'Respuesta tardía sintética para comprobar que el resultado terminal no se reescribe.',
                        'local_simulation_id' => $terminal->local_simulation_id,
                    ],
                );

                $this->assertSame('simulated_accepted', $terminal->fresh()->status);
                $this->assertSame('reported', $document->fresh()->status);
                $this->assertDatabaseHas('electronic_receipt_summary_events', [
                    'electronic_receipt_summary_id' => $terminal->id,
                    'event_type' => 'simulated_late_response_ignored',
                ]);
            }
        }
    }

    public function test_recovery_preserves_a_persisted_local_simulation_id_for_query_instead_of_leaving_documents_queued(): void
    {
        config(['electronic_invoicing.receipt_simulation.scenario' => 'timeout_after_ticket']);
        $actor = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        Sanctum::actingAs($actor);
        $document = $this->issueReceipt($this->eligibleReceipt(), $actor);
        $summary = $this->createAndProcessSummary($document, $actor);
        $attempt = $summary->attempts()->firstOrFail();
        $this->assertNotNull($summary->local_simulation_id);

        $summary->forceFill(['status' => 'processing', 'updated_at' => now()->subMinutes(10)])->save();
        $attempt->forceFill(['status' => 'processing', 'updated_at' => now()->subMinutes(10)])->save();

        $this->assertSame(1, app(ElectronicReceiptSummaryRecoveryService::class)->recoverStaleProcessing());
        $this->assertSame('simulated_processing_pending', $summary->fresh()->status);
        $this->assertSame('ticket_pending', $summary->fresh()->communication_status);
        $this->assertSame('summary_pending', $document->fresh()->status);
        $this->assertSame('simulated_ticket', $summary->fresh()->items()->firstOrFail()->result_status);
        $this->assertSame($summary->local_simulation_id, $summary->fresh()->local_simulation_id);

        $this->postJson('/api/v1/billing/receipt-summaries/'.$summary->id.'/query')->assertOk()
            ->assertJsonPath('data.communication_status', 'ticket_pending');
        $this->assertSame('simulated_processing_pending', $summary->fresh()->status);
        $this->assertSame('summary_pending', $document->fresh()->status);
    }

    public function test_beta_transport_uses_wsdl_soap_base64_and_persists_external_ticket_separately(): void
    {
        config(['electronic_invoicing.receipt_beta.enabled' => false]);
        $actor = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        Sanctum::actingAs($actor);
        $document = $this->issueReceipt($this->eligibleReceipt(), $actor);
        $summary = $this->createAndProcessSummary($document, $actor);
        $attempt = $summary->attempts()->firstOrFail();
        $this->setSummaryBetaMode($summary, $attempt, 'processing', 'processing');
        config(['electronic_invoicing.receipt_beta.enabled' => true]);
        $zip = Storage::disk('local')->get((string) $summary->zip_path);
        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request;

            return Http::response('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:s="http://service.sunat.gob.pe"><soap:Body><s:sendSummaryResponse><ticket>202611220000001</ticket></s:sendSummaryResponse></soap:Body></soap:Envelope>', 200, ['Content-Type' => 'text/xml; charset=utf-8']);
        });

        $result = app(SunatBetaElectronicReceiptSummaryTransport::class)->sendSummary($summary->fresh(), $zip);

        $this->assertSame('ticket', $result['type'], json_encode($result));
        $this->assertFalse($result['simulated']);
        $this->assertSame('sunat_beta', $result['mode']);
        $this->assertSame('202611220000001', $result['external_ticket']);
        $this->assertNull($result['local_simulation_id'] ?? null);
        $this->assertNotNull($captured);
        $this->assertSame('POST', $captured->method());
        $this->assertSame('urn:sendSummary', $captured->header('SOAPAction')[0] ?? null);
        $this->assertStringContainsString('text/xml', $captured->header('Content-Type')[0] ?? '');
        $soap = new \DOMDocument;
        $this->assertTrue($soap->loadXML($captured->body(), LIBXML_NONET));
        $this->assertSame('https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService', $captured->url());
        $this->assertSame('20123456789MODDATOS', $soap->getElementsByTagNameNS('http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd', 'Username')->item(0)->textContent);
        $this->assertSame('MODDATOS', $soap->getElementsByTagNameNS('http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd', 'Password')->item(0)->textContent);
        $this->assertSame($summary->issuer_tax_id.'-'.$summary->document_id.'.zip', $soap->getElementsByTagNameNS('http://service.sunat.gob.pe', 'fileName')->item(0)->textContent);
        $this->assertSame($zip, base64_decode($soap->getElementsByTagNameNS('http://service.sunat.gob.pe', 'contentFile')->item(0)->textContent, true));
        Http::assertSentCount(1);

        app(ElectronicReceiptSummaryCommunicationService::class)->persistSubmission((int) $summary->id, (int) $attempt->id, $result);
        $summary->refresh();
        $attempt->refresh();
        $this->assertSame('202611220000001', $summary->external_ticket);
        $this->assertSame('202611220000001', $attempt->external_ticket);
        $this->assertNull($summary->local_simulation_id);
        $this->assertNull($attempt->local_simulation_id);
        $this->assertNull($summary->ticket);
        $this->assertSame('beta_processing_pending', $summary->status);
        $this->assertSame('ticket_pending', $summary->communication_status);
        $evidence = $attempt->request_snapshot['transport_evidence'];
        $this->assertSame('https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService', $evidence['endpoint']);
        $this->assertSame('urn:sendSummary', $evidence['action']);
        $this->assertSame(hash('sha256', $zip), $evidence['zip_sha256']);
        $this->assertSame(strlen($zip), $evidence['zip_bytes']);
        $this->assertSame(strlen(base64_encode($zip)), $evidence['content_file_base64_bytes']);
        $this->assertArrayNotHasKey('username', $evidence);
        $this->assertArrayNotHasKey('password', $evidence);
    }

    public function test_beta_get_status_pending_uses_only_persisted_external_ticket_and_never_queries_local_sim_ids(): void
    {
        config(['electronic_invoicing.receipt_beta.enabled' => false]);
        $actor = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        Sanctum::actingAs($actor);
        $document = $this->issueReceipt($this->eligibleReceipt(), $actor);
        $summary = $this->createAndProcessSummary($document, $actor);
        $attempt = $summary->attempts()->firstOrFail();
        $this->setSummaryBetaMode($summary, $attempt, 'beta_processing_pending', 'beta_processing_pending', '202611220000002');
        config(['electronic_invoicing.receipt_beta.enabled' => true]);
        $summary->communication_status = 'ticket_pending';
        $attempt->communication_status = 'ticket_pending';
        $summary->save();
        $attempt->save();
        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request;

            return Http::response('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:s="http://service.sunat.gob.pe"><soap:Body><s:getStatusResponse><status><statusCode>98</statusCode></status></s:getStatusResponse></soap:Body></soap:Envelope>', 200, ['Content-Type' => 'text/xml; charset=utf-8']);
        });

        $updated = app(ElectronicReceiptSummaryCommunicationService::class)->query((int) $summary->id, $actor);

        $this->assertSame('beta_processing_pending', $updated->status);
        $this->assertSame('ticket_pending', $updated->communication_status);
        $this->assertSame('202611220000002', $updated->external_ticket);
        $this->assertSame('urn:getStatus', $captured->header('SOAPAction')[0] ?? null);
        $this->assertSame('202611220000002', $this->xmlElement($captured->body(), 'ticket'));
        Http::assertSentCount(1);

        config(['electronic_invoicing.receipt_beta.enabled' => false]);
        $localDocument = $this->issueReceipt($this->eligibleReceipt(), $actor);
        $localSummary = $this->createAndProcessSummary($localDocument, $actor);
        config(['electronic_invoicing.receipt_simulation.scenario' => 'pending']);
        $before = Http::recorded()->count();
        app(ElectronicReceiptSummaryCommunicationService::class)->query((int) $localSummary->id, $actor);
        $this->assertSame($before, Http::recorded()->count(), 'LOCAL-SIM must never be sent to the external adapter.');
    }

    public function test_beta_adapter_classifies_soap_fault_http_error_and_unbounded_or_invalid_responses_without_acceptance(): void
    {
        config(['electronic_invoicing.receipt_beta.enabled' => false]);
        $actor = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        Sanctum::actingAs($actor);
        $summary = $this->createAndProcessSummary($this->issueReceipt($this->eligibleReceipt(), $actor), $actor);
        $attempt = $summary->attempts()->firstOrFail();
        $this->setSummaryBetaMode($summary, $attempt, 'processing', 'processing');
        config(['electronic_invoicing.receipt_beta.enabled' => true]);
        $zip = Storage::disk('local')->get((string) $summary->zip_path);
        $transport = app(SunatBetaElectronicReceiptSummaryTransport::class);

        Http::fakeSequence()
            ->push('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultcode>soap:Client</faultcode><faultstring>sanitized fault</faultstring><detail><Password>synthetic-secret</Password></detail></soap:Fault></soap:Body></soap:Envelope>', 200)
            ->push('upstream unavailable', 503)
            ->push('<not-soap/>', 200)
            ->push(str_repeat('x', 6 * 1024 * 1024 + 10), 200)
            ->push('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:s="http://service.sunat.gob.pe"><soap:Body><s:sendSummaryResponse/></soap:Body></soap:Envelope>', 200);
        $fault = $transport->sendSummary($summary->fresh(), $zip);
        $this->assertSame('soap_fault', $fault['type']);
        $this->assertStringNotContainsString('synthetic-secret', (string) $fault['response_xml']);
        $this->assertStringContainsString('[dato omitido]', (string) $fault['response_xml']);

        $http = $transport->sendSummary($summary->fresh(), $zip);
        $this->assertSame('http_error', $http['type']);
        $this->assertNotSame('accepted', $http['type']);

        $invalid = $transport->sendSummary($summary->fresh(), $zip);
        $this->assertSame('uncertain', $invalid['type']);

        $oversized = $transport->sendSummary($summary->fresh(), $zip);
        $this->assertSame('uncertain', $oversized['type']);
        $missingTicket = $transport->sendSummary($summary->fresh(), $zip);
        $this->assertSame('uncertain', $missingTicket['type']);
        $this->assertSame('external_ticket_invalid', $missingTicket['code']);
        Http::assertSentCount(5);
    }

    public function test_beta_get_status_validates_cdr_correspondence_and_projects_documented_results_once(): void
    {
        config(['electronic_invoicing.receipt_beta.enabled' => false]);
        Sanctum::actingAs($actor = User::factory()->create(['role' => 'billing', 'is_active' => true]));
        $fingerprint = strtoupper(str_replace(':', '', (string) openssl_x509_fingerprint($this->certificate['certificate_pem'], 'sha256')));
        config(['electronic_invoicing.receipt_beta.cdr_certificate_sha256' => $fingerprint]);
        $cases = [
            ['0', '0', null, 'beta_accepted', 'accepted'],
            ['0', '0', 'Observación sintética', 'beta_accepted_observed', 'accepted_observed'],
            ['99', '2000', null, 'beta_rejected', 'rejected'],
        ];
        $sequence = Http::fakeSequence();
        $summaries = [];
        foreach ($cases as [$status, $code, $note, $expectedStatus, $expectedCommunication]) {
            config(['electronic_invoicing.receipt_beta.enabled' => false]);
            $summary = $this->createAndProcessSummary($this->issueReceipt($this->eligibleReceipt(), $actor), $actor);
            $attempt = $summary->attempts()->firstOrFail();
            $ticket = '20261122'.str_pad((string) (count($summaries) + 10), 6, '0', STR_PAD_LEFT);
            $this->setSummaryBetaMode($summary, $attempt, 'beta_processing_pending', 'beta_processing_pending', $ticket);
            config(['electronic_invoicing.receipt_beta.enabled' => true]);
            $summary->communication_status = 'ticket_pending';
            $summary->save();
            $attempt->communication_status = 'ticket_pending';
            $attempt->save();
            $cdr = $this->signedSummaryCdrZip($summary, $code, $note);
            $processedCdr = app(SunatReceiptSummaryCdrProcessor::class)->process($summary, $cdr);
            $this->assertSame($expectedCommunication, $processedCdr['type']);
            $soap = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:s="http://service.sunat.gob.pe"><soap:Body><s:getStatusResponse><status><statusCode>'.$status.'</statusCode><content>'.base64_encode($cdr).'</content></status></s:getStatusResponse></soap:Body></soap:Envelope>';
            $sequence->push($soap, 200, ['Content-Type' => 'text/xml; charset=utf-8']);
            $summaries[] = [$summary, $ticket, $expectedStatus, $expectedCommunication];
        }

        foreach ($summaries as [$summary, $ticket, $expectedStatus, $expectedCommunication]) {
            $result = app(ElectronicReceiptSummaryCommunicationService::class)->query((int) $summary->id, $actor);
            $this->assertSame($expectedStatus, $result->status, $result->result_code.' '.$result->result_message);
            $this->assertSame($expectedCommunication, $result->communication_status);
            $this->assertSame($ticket, $result->external_ticket);
            $this->assertNull($result->local_simulation_id);
            $this->assertNotNull($result->response_path);
            $this->assertDatabaseHas('electronic_documents', [
                'id' => $summary->items()->value('electronic_document_id'),
                'status' => $expectedCommunication === 'rejected' ? 'summary_rejected' : 'reported',
            ]);
            $afterTerminal = Http::recorded()->count();
            app(ElectronicReceiptSummaryCommunicationService::class)->query((int) $summary->id, $actor);
            $this->assertSame($afterTerminal, Http::recorded()->count(), 'Terminal Beta results must not be queried or overwritten.');
            $this->assertSame($expectedStatus, $summary->fresh()->status);
        }
        Http::assertSentCount(3);
    }

    public function test_beta_rejects_non_corresponding_cdr_and_keeps_the_summary_uncertain(): void
    {
        config(['electronic_invoicing.receipt_beta.enabled' => false]);
        Sanctum::actingAs($actor = User::factory()->create(['role' => 'billing', 'is_active' => true]));
        $fingerprint = strtoupper(str_replace(':', '', (string) openssl_x509_fingerprint($this->certificate['certificate_pem'], 'sha256')));
        config(['electronic_invoicing.receipt_beta.cdr_certificate_sha256' => $fingerprint]);
        $summary = $this->createAndProcessSummary($this->issueReceipt($this->eligibleReceipt(), $actor), $actor);
        $attempt = $summary->attempts()->firstOrFail();
        $ticket = '202611220000099';
        $this->setSummaryBetaMode($summary, $attempt, 'beta_processing_pending', 'beta_processing_pending', $ticket);
        config(['electronic_invoicing.receipt_beta.enabled' => true]);
        $summary->communication_status = 'ticket_pending';
        $summary->save();
        $attempt->communication_status = 'ticket_pending';
        $attempt->save();
        $mismatchedCdr = $this->signedSummaryCdrZip($summary, '0', null, 'RC-20990101-99999');
        Http::fake(fn () => Http::response('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:s="http://service.sunat.gob.pe"><soap:Body><s:getStatusResponse><status><statusCode>0</statusCode><content>'.base64_encode($mismatchedCdr).'</content></status></s:getStatusResponse></soap:Body></soap:Envelope>', 200));

        $updated = app(ElectronicReceiptSummaryCommunicationService::class)->query((int) $summary->id, $actor);

        $this->assertSame('beta_uncertain', $updated->status);
        $this->assertSame('uncertain', $updated->communication_status);
        $this->assertSame($ticket, $updated->external_ticket);
        $this->assertSame('cdr_validation_failed', $updated->result_code);
        $this->assertNull($updated->items()->firstOrFail()->document->cdr_path);
        Http::assertSentCount(1);
    }

    public function test_beta_transport_is_opt_in_and_blocked_outside_local_qa_without_http_requests(): void
    {
        config(['electronic_invoicing.receipt_beta.enabled' => false]);
        Sanctum::actingAs($actor = User::factory()->create(['role' => 'billing', 'is_active' => true]));
        $summary = $this->createAndProcessSummary($this->issueReceipt($this->eligibleReceipt(), $actor), $actor);
        $attempt = $summary->attempts()->firstOrFail();
        $this->setSummaryBetaMode($summary, $attempt, 'beta_processing_pending', 'beta_processing_pending', '202611220000088');
        $transport = app(SunatBetaElectronicReceiptSummaryTransport::class);
        try {
            $transport->query('202611220000088', 1);
            $this->fail('The external transport must remain disabled by default.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        config(['electronic_invoicing.receipt_beta.enabled' => true]);
        $this->app->detectEnvironment(static fn (): string => 'production');
        try {
            $transport->query('202611220000088', 1);
            $this->fail('The Beta adapter must reject production before HTTP.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_beta_preflight_failure_is_not_reported_as_transmitted_and_keeps_numbered_evidence(): void
    {
        config(['electronic_invoicing.receipt_beta.enabled' => false]);
        Sanctum::actingAs($actor = User::factory()->create(['role' => 'billing', 'is_active' => true]));
        $document = $this->issueReceipt($this->eligibleReceipt(), $actor);
        $response = $this->postJson('/api/v1/billing/receipt-summaries', [
            'documents' => [['id' => $document->id, 'version' => $document->version]],
        ])->assertAccepted();
        $summary = ElectronicReceiptSummary::query()->findOrFail($response->json('data.id'));
        $attempt = $summary->attempts()->firstOrFail();
        $snapshot = $summary->snapshot;
        $snapshot['communication_mode'] = 'sunat_beta';
        $summary->snapshot = $snapshot;
        $summary->save();

        app(ProcessElectronicReceiptSummary::class, ['summaryId' => $summary->id, 'attemptId' => $attempt->id])
            ->handle(app(SunatUblReceiptSummaryBuilder::class), app(SunatXmlSignatureService::class), app(SunatSchemaBundle::class));

        $summary->refresh();
        $this->assertSame('beta_not_sent', $summary->status);
        $this->assertSame('not_sent', $summary->communication_status);
        $this->assertNull($summary->external_ticket);
        $this->assertSame('beta_preflight_failed', $summary->result_code);
        $this->assertTrue(Storage::disk('local')->exists((string) $summary->xml_path));
        $this->assertTrue(Storage::disk('local')->exists((string) $summary->zip_path));
        $this->assertSame('B001', $document->fresh()->series);
        Http::assertNothingSent();
    }

    public function test_beta_recovery_uses_only_persisted_ticket_and_never_republishes_ambiguous_work(): void
    {
        config(['electronic_invoicing.receipt_beta.enabled' => false]);
        Sanctum::actingAs($actor = User::factory()->create(['role' => 'billing', 'is_active' => true]));
        $withTicket = $this->createAndProcessSummary($this->issueReceipt($this->eligibleReceipt(), $actor), $actor);
        $withoutTicket = $this->createAndProcessSummary($this->issueReceipt($this->eligibleReceipt(), $actor), $actor);
        foreach ([[$withTicket, '202611220000071'], [$withoutTicket, null]] as [$summary, $ticket]) {
            $attempt = $summary->attempts()->firstOrFail();
            $snapshot = $summary->snapshot;
            $snapshot['communication_mode'] = 'sunat_beta';
            $summary->forceFill([
                'snapshot' => $snapshot, 'status' => 'processing', 'communication_status' => 'uncertain',
                'local_simulation_id' => null, 'external_ticket' => $ticket,
                'updated_at' => now()->subMinutes(10),
            ])->save();
            $attempt->forceFill([
                'status' => 'processing', 'communication_status' => 'uncertain', 'local_simulation_id' => null,
                'external_ticket' => $ticket, 'updated_at' => now()->subMinutes(10),
            ])->save();
        }

        $eventCount = ElectronicReceiptSummaryEvent::query()->whereIn('electronic_receipt_summary_id', [$withTicket->id, $withoutTicket->id])->count();
        $this->assertSame(2, app(ElectronicReceiptSummaryRecoveryService::class)->recoverStaleProcessing());
        $this->assertSame('beta_processing_pending', $withTicket->fresh()->status);
        $this->assertSame('ticket_pending', $withTicket->fresh()->communication_status);
        $this->assertSame('202611220000071', $withTicket->fresh()->external_ticket);
        $this->assertSame('beta_uncertain', $withoutTicket->fresh()->status);
        $this->assertSame('uncertain', $withoutTicket->fresh()->communication_status);
        $this->assertNull($withoutTicket->fresh()->external_ticket);
        $this->assertSame($eventCount + 2, ElectronicReceiptSummaryEvent::query()->whereIn('electronic_receipt_summary_id', [$withTicket->id, $withoutTicket->id])->count());
        Http::assertNothingSent();
    }

    private function eligibleReceipt(bool $completeTax = true): ElectronicDocument
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $order = Order::query()->create([
            'user_id' => $customer->id, 'status' => 'paid', 'total' => '118.00', 'subtotal' => '118.00',
            'shipping_amount' => '0.00', 'discount_total' => '0.00', 'shipping_info' => [],
            'payment_method' => 'transferencia', 'payment_status' => 'approved', 'delivery_type' => 'pickup',
            'tracking_status' => 'picked_up', 'requested_receipt_type' => 'boleta',
            'requested_recipient_name' => 'Receptor sintético', 'requested_identity_type' => 'DNI',
            'requested_identity_number' => '12345678', 'requested_receipt_email' => 'qa@example.test',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id, 'product_id' => null, 'product_name' => 'Producto sintético',
            'quantity' => 1, 'regular_price' => '118.00', 'price' => '118.00', 'subtotal' => '118.00',
        ]);
        $profile = BusinessProfile::query()->firstOrCreate(['singleton_key' => 'primary'], [
            'singleton_key' => 'primary', 'commercial_name' => 'LubriStore QA', 'legal_name' => 'LubriStore QA SAC',
            'tax_id_type' => 'RUC', 'tax_id_number' => '20123456789', 'country_code' => 'PE',
            'fiscal_address' => 'Dirección sintética', 'boleta_enabled' => true,
        ]);
        $document = ElectronicDocument::query()->create([
            'order_id' => $order->id, 'draft_reference' => (string) Str::uuid(), 'preparation_key' => (string) Str::uuid(),
            'document_type' => 'boleta', 'status' => 'draft', 'environment' => 'testing', 'connection_type' => 'direct_sunat',
            'seller_snapshot' => [
                'commercial_name' => $profile->commercial_name, 'legal_name' => $profile->legal_name,
                'tax_id_type' => $profile->tax_id_type, 'tax_id_number' => $profile->tax_id_number,
                'country_code' => 'PE', 'fiscal_address' => $profile->fiscal_address,
            ],
            'recipient_snapshot' => [
                'document_type' => 'DNI', 'document_number' => '12345678', 'name' => 'Receptor sintético',
                'email' => 'qa@example.test', 'identity_required' => false,
            ],
            'lines_snapshot' => ['items' => [[
                'name' => 'Producto sintético', 'quantity' => 1, 'unit_price' => '118.00', 'subtotal' => '118.00',
                'tax' => ['calculation_status' => 'complete', 'tax_affectation' => ['code' => '10'], 'base' => '100.00', 'tax' => '18.00'],
            ]]],
            'amounts_snapshot' => [
                'total' => '118.00', 'operation_type' => '0101', 'payment_terms' => ['type' => 'cash', 'amount' => '118.00', 'source' => 'order_payment_status'],
                'missing_fields' => $completeTax ? [] : ['tax_snapshot_incomplete'], 'tax_snapshot' => [
                    'status' => $completeTax ? 'complete' : 'review_required', 'missing_fields' => [],
                    'totals' => ['taxable_base_minor' => '100.00', 'exempt_base_minor' => '0.00', 'unaffected_base_minor' => '0.00', 'tax_minor' => '18.00'],
                ],
            ],
            'currency' => 'PEN', 'tax_calculation_status' => $completeTax ? 'complete' : 'review_required', 'version' => 1, 'prepared_at' => now(),
        ]);

        return $document;
    }

    private function setSummaryBetaMode(ElectronicReceiptSummary $summary, $attempt, string $summaryStatus, string $attemptStatus, ?string $externalTicket = null): void
    {
        $snapshot = $summary->snapshot ?? [];
        $snapshot['communication_mode'] = 'sunat_beta';
        $summary->forceFill([
            'snapshot' => $snapshot,
            'status' => $summaryStatus,
            'communication_status' => 'uncertain',
            'external_ticket' => $externalTicket,
            'local_simulation_id' => null,
            'ticket' => null,
        ])->save();
        $attempt->forceFill([
            'status' => $attemptStatus,
            'communication_status' => 'uncertain',
            'external_ticket' => $externalTicket,
            'local_simulation_id' => null,
            'ticket' => null,
        ])->save();
    }

    private function xmlElement(string $xml, string $name): ?string
    {
        $document = new \DOMDocument;
        $this->assertTrue($document->loadXML($xml, LIBXML_NONET));
        $elements = $document->getElementsByTagNameNS('http://service.sunat.gob.pe', $name);

        return $elements->length ? $elements->item(0)->textContent : null;
    }

    private function signedSummaryCdrZip(ElectronicReceiptSummary $summary, string $responseCode, ?string $note, ?string $reference = null): string
    {
        $appNs = 'urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2';
        $extNs = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';
        $cbcNs = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
        $cacNs = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = false;
        $root = $xml->createElementNS($appNs, 'ApplicationResponse');
        $xmlNs = 'http://www.w3.org/2000/xmlns/';
        $root->setAttributeNS($xmlNs, 'xmlns:ext', $extNs);
        $root->setAttributeNS($xmlNs, 'xmlns:cbc', $cbcNs);
        $root->setAttributeNS($xmlNs, 'xmlns:cac', $cacNs);
        $xml->appendChild($root);
        $extensions = $root->appendChild($xml->createElementNS($extNs, 'ext:UBLExtensions'));
        $extension = $extensions->appendChild($xml->createElementNS($extNs, 'ext:UBLExtension'));
        $extension->appendChild($xml->createElementNS($extNs, 'ext:ExtensionContent'));
        $append = static function (\DOMNode $parent, string $namespace, string $qualifiedName, string $value) use ($xml): \DOMElement {
            $element = $xml->createElementNS($namespace, $qualifiedName);
            $element->appendChild($xml->createTextNode($value));
            $parent->appendChild($element);

            return $element;
        };
        $append($root, $cbcNs, 'cbc:UBLVersionID', '2.0');
        $append($root, $cbcNs, 'cbc:ID', 'R-'.$summary->document_id);
        $append($root, $cbcNs, 'cbc:IssueDate', now()->toDateString());
        if ($note !== null) {
            $append($root, $cbcNs, 'cbc:Note', $note);
        }
        foreach ([['SenderParty', '20131312955'], ['ReceiverParty', $summary->issuer_tax_id]] as [$partyName, $ruc]) {
            $party = $xml->createElementNS($cacNs, 'cac:'.$partyName);
            $root->appendChild($party);
            $identification = $xml->createElementNS($cacNs, 'cac:PartyIdentification');
            $party->appendChild($identification);
            $append($identification, $cbcNs, 'cbc:ID', $ruc);
        }
        $documentResponse = $xml->createElementNS($cacNs, 'cac:DocumentResponse');
        $root->appendChild($documentResponse);
        $response = $xml->createElementNS($cacNs, 'cac:Response');
        $documentResponse->appendChild($response);
        $append($response, $cbcNs, 'cbc:ReferenceID', $reference ?? $summary->document_id);
        $append($response, $cbcNs, 'cbc:ResponseCode', $responseCode);
        $append($response, $cbcNs, 'cbc:Description', $responseCode === '0' ? 'Resultado sintético' : 'Rechazo sintético');
        $documentReference = $xml->createElementNS($cacNs, 'cac:DocumentReference');
        $documentResponse->appendChild($documentReference);
        $append($documentReference, $cbcNs, 'cbc:ID', $summary->document_id);
        $signed = app(SunatXmlSignatureService::class)->signAndVerify(
            $xml,
            $this->certificate['pkcs12'],
            'synthetic-pass',
            $summary->issuer_tax_id,
            $this->certificate['openssl_config'],
        );
        $parsed = new \DOMDocument;
        $parsed->resolveExternals = false;
        $parsed->substituteEntities = false;
        $this->assertTrue($parsed->loadXML($signed, LIBXML_NONET));
        $cdrSchema = resource_path('sunat/xsd/Archivos XSD/2.0/maindoc/UBLPE-ApplicationResponse-1.0.xsd');
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $valid = $parsed->schemaValidate($cdrSchema, LIBXML_NONET);
        $errors = array_map(static fn (\LibXMLError $error): string => trim($error->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->assertTrue($valid, implode('; ', $errors));
        $tmp = tempnam(sys_get_temp_dir(), 'synthetic_cdr_');
        $this->assertNotFalse($tmp);
        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
            $this->assertTrue($zip->addFromString('R-'.$summary->issuer_tax_id.'-'.$summary->document_id.'.xml', $signed));
            $this->assertTrue($zip->close());
            $bytes = file_get_contents($tmp);
            $this->assertIsString($bytes);

            return $bytes;
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    private function issueReceipt(ElectronicDocument $document, User $actor): ElectronicDocument
    {
        return app(ElectronicReceiptIssuanceService::class)->issue($document, $actor, (int) $document->version);
    }

    private function createAndProcessSummary(ElectronicDocument $document, User $actor): ElectronicReceiptSummary
    {
        $response = $this->postJson('/api/v1/billing/receipt-summaries', [
            'documents' => [['id' => $document->id, 'version' => $document->version]],
        ])->assertAccepted();
        $summary = ElectronicReceiptSummary::query()->findOrFail($response->json('data.id'));
        app(ProcessElectronicReceiptSummary::class, ['summaryId' => $summary->id, 'attemptId' => $summary->attempts()->value('id')])
            ->handle(app(SunatUblReceiptSummaryBuilder::class), app(SunatXmlSignatureService::class), app(SunatSchemaBundle::class));

        return $summary->fresh()->load(['items.document', 'attempts', 'events']);
    }
}
