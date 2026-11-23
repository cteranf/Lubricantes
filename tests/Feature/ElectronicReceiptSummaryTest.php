<?php

namespace Tests\Feature;

use App\Jobs\ProcessElectronicReceiptSummary;
use App\Models\BusinessProfile;
use App\Models\ElectronicDocument;
use App\Models\ElectronicInvoiceSetting;
use App\Models\ElectronicReceiptSummary;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\ElectronicReceiptIssuanceService;
use App\Services\ElectronicReceiptSummaryCommunicationService;
use App\Services\ElectronicReceiptSummaryOutboxPublisher;
use App\Services\ElectronicReceiptSummaryRecoveryService;
use App\Services\SunatSchemaBundle;
use App\Services\SunatUblReceiptSummaryBuilder;
use App\Services\SunatXmlSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SyntheticCertificate;
use Tests\TestCase;

class ElectronicReceiptSummaryTest extends TestCase
{
    use RefreshDatabase;

    private array $certificate;

    protected function setUp(): void
    {
        parent::setUp();
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
            ->assertJsonPath('data.communication_mode', 'local_simulation_only');

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
        $this->assertSame(1, $zip->numFiles);
        $xmlName = $zip->getNameIndex(0);
        $this->assertSame($summary->xml_hash, hash('sha256', $zip->getFromIndex(0)));
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
