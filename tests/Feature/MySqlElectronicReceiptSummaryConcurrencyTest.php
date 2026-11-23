<?php

namespace Tests\Feature;

use App\Models\ElectronicDocument;
use App\Models\ElectronicInvoiceSetting;
use App\Models\Order;
use App\Models\User;
use App\Services\ElectronicReceiptSummaryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\RequiresMySqlDriver;
use Tests\TestCase;

#[Group('mysql')]
class MySqlElectronicReceiptSummaryConcurrencyTest extends TestCase
{
    use RequiresMySqlDriver;

    public function test_independent_connections_reject_partially_overlapping_summary_selections_atomically(): void
    {
        $database = (string) getenv('ELECTRONIC_RECEIPT_MYSQL_TEST_DB');
        $this->assertMatchesRegularExpression('/\Alubristore_electronic_receipts_phase4_[0-9]{8}_test\z/', $database);
        $this->assertNotContains($database, ['lubricantes', 'lubristore_remote_payments_test']);
        config([
            'database.default' => 'mysql',
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'mysql',
            'electronic_invoicing.enabled' => true,
            'electronic_invoicing.sunat.xsd_directory' => resource_path('sunat/xsd'),
            'electronic_invoicing.sunat.summary_xsd' => resource_path('sunat/xsd/Archivos XSD/2.0/maindoc/UBLPE-SummaryDocuments-1.0.xsd'),
        ]);
        $connection = DB::connection('mysql');
        $server = $connection->selectOne('SELECT DATABASE() AS db, @@default_storage_engine AS engine');
        $this->assertSame($database, $server->db);
        $this->assertSame('innodb', strtolower((string) $server->engine));

        $actor = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        $issuer = sprintf('20%09d', random_int(0, 999999999));
        $orders = [];
        $documents = [];
        foreach (range(0, 2) as $index) {
            $customer = User::factory()->create(['role' => 'customer']);
            $order = Order::query()->create([
                'user_id' => $customer->id, 'status' => 'paid', 'total' => '118.00', 'subtotal' => '118.00',
                'shipping_amount' => '0.00', 'discount_total' => '0.00', 'shipping_info' => [],
                'payment_method' => 'transferencia', 'payment_status' => 'approved', 'delivery_type' => 'pickup',
                'tracking_status' => 'picked_up', 'requested_receipt_type' => 'boleta',
                'requested_recipient_name' => 'Receptor MySQL QA', 'requested_identity_type' => 'DNI',
                'requested_identity_number' => '12345678', 'requested_receipt_email' => 'qa@example.test',
            ]);
            $xmlPath = 'electronic-invoices/documents/mysql-race-'.$index.'/B001-'.str_pad((string) ($index + 1), 8, '0', STR_PAD_LEFT).'.xml';
            Storage::disk('local')->put($xmlPath, '<Invoice/>');
            $documents[] = ElectronicDocument::query()->create([
                'order_id' => $order->id, 'draft_reference' => (string) Str::uuid(), 'preparation_key' => (string) Str::uuid(),
                'document_type' => 'boleta', 'status' => 'ready_for_summary', 'environment' => 'testing', 'connection_type' => 'direct_sunat',
                'series' => 'B001', 'document_number' => $index + 1, 'xml_path' => $xmlPath,
                'seller_snapshot' => ['commercial_name' => 'LubriStore QA', 'legal_name' => 'LubriStore QA SAC', 'tax_id_type' => 'RUC', 'tax_id_number' => $issuer, 'country_code' => 'PE', 'fiscal_address' => 'Dirección QA'],
                'recipient_snapshot' => ['document_type' => 'DNI', 'document_number' => '12345678', 'name' => 'Receptor QA', 'email' => 'qa@example.test', 'identity_required' => false],
                'lines_snapshot' => ['items' => [[
                    'name' => 'Producto QA', 'quantity' => 1, 'unit_price' => '118.00', 'subtotal' => '118.00',
                    'tax' => ['calculation_status' => 'complete', 'tax_affectation' => ['code' => '10'], 'base' => '100.00', 'tax' => '18.00'],
                ]]],
                'amounts_snapshot' => ['total' => '118.00', 'operation_type' => '0101', 'payment_terms' => ['type' => 'cash', 'amount' => '118.00', 'source' => 'order_payment_status'], 'missing_fields' => [], 'tax_snapshot' => [
                    'status' => 'complete', 'missing_fields' => [], 'totals' => ['taxable_base_minor' => '100.00', 'exempt_base_minor' => '0.00', 'unaffected_base_minor' => '0.00', 'tax_minor' => '18.00'],
                ]],
                'currency' => 'PEN', 'tax_calculation_status' => 'complete', 'version' => 2, 'prepared_by' => $actor->id,
                'prepared_at' => now(), 'confirmed_by' => $actor->id, 'confirmed_at' => now(),
            ]);
            $orders[] = $order;
        }
        $connection->table('electronic_document_series')->insertOrIgnore([
            'issuer_tax_id' => $issuer, 'environment' => 'testing', 'document_type' => 'boleta',
            'series' => 'B001', 'next_number' => 4, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $settingsExisted = ElectronicInvoiceSetting::query()->where('singleton_key', 'primary')->exists();
        $settings = ElectronicInvoiceSetting::query()->firstOrCreate(['singleton_key' => 'primary'], ['environment' => 'testing', 'connection_type' => 'direct_sunat']);
        $previousSettings = $settings->getAttributes();
        $certificatePath = 'electronic-invoices/testing/summary-race-'.bin2hex(random_bytes(8)).'.p12';
        Storage::disk('local')->put($certificatePath, 'synthetic-not-used-by-summary-selection');
        $settings->forceFill([
            'environment' => 'testing', 'connection_type' => 'direct_sunat', 'certificate_path' => $certificatePath,
            'certificate_password_encrypted' => 'not-used', 'certificate_expires_at' => now()->addDay(),
        ])->save();

        $directory = storage_path('framework/testing/receipt-summary-overlap-'.bin2hex(random_bytes(8)));
        $this->assertTrue(mkdir($directory, 0700, true));
        $configPath = $directory.DIRECTORY_SEPARATOR.'connection.json';
        $readyPath = $directory.DIRECTORY_SEPARATOR.'worker.ready';
        $resultPath = $directory.DIRECTORY_SEPARATOR.'worker.result';
        $this->assertNotFalse(file_put_contents($configPath, json_encode(config('database.connections.mysql'), JSON_THROW_ON_ERROR)));
        @chmod($configPath, 0600);
        $parentConnectionId = (int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
        $priorJobs = $connection->table('jobs')->pluck('id')->all();
        $worker = null;
        $shared = $documents[1];
        $connection->beginTransaction();

        try {
            $connection->table('orders')->where('id', $orders[1]->id)->lockForUpdate()->first();
            $php = [PHP_BINARY];
            $ini = php_ini_loaded_file();
            if (is_string($ini) && $ini !== '') {
                array_push($php, '-c', $ini);
            }
            $selection = json_encode([
                ['id' => $documents[1]->id, 'version' => 2],
                ['id' => $documents[2]->id, 'version' => 2],
            ], JSON_THROW_ON_ERROR);
            $worker = new Process([...$php, base_path('tests/Support/electronic-receipt-summary-confirm-worker.php'), $configPath, $database, $selection, (string) $actor->id, $resultPath, $readyPath], base_path(), array_merge($_ENV, $_SERVER, [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database,
                'ELECTRONIC_RECEIPT_MYSQL_TEST_DB' => $database,
            ]));
            $worker->setTimeout(40);
            $worker->start();
            $deadline = microtime(true) + 20;
            while (! is_file($readyPath) && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(20000);
            }
            $this->assertFileExists($readyPath, 'Independent worker did not connect before attempting confirmation. '.$worker->getErrorOutput());
            $this->assertNotSame($parentConnectionId, (int) file_get_contents($readyPath));

            // The operator A confirms [A, B], while the worker is already trying [B, C].
            $first = app(ElectronicReceiptSummaryService::class)->confirm([
                ['id' => $documents[0]->id, 'version' => 2], ['id' => $documents[1]->id, 'version' => 2],
            ], $actor);
            usleep(350000);
            $this->assertTrue($worker->isRunning(), 'The independent selection must wait on the shared order lock.');
            $connection->commit();
            $worker->wait();
            $this->assertSame(1, $worker->getExitCode(), $worker->getErrorOutput());
            $second = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame('HttpException', class_basename($second['error']));
            $this->assertSame(2, (int) $first->document_count);
            $this->assertSame(1, $connection->table('electronic_receipt_summaries')->count());
            $this->assertSame(2, $connection->table('electronic_receipt_summary_items')->count());
            $this->assertSame(1, $connection->table('electronic_receipt_summary_items')->where('electronic_document_id', $shared->id)->count());
            $this->assertSame(0, $connection->table('electronic_receipt_summary_items')->where('electronic_document_id', $documents[2]->id)->count());
            $this->assertSame(1, $connection->table('electronic_receipt_summary_attempts')->count());
            $this->assertSame(1, $connection->table('electronic_receipt_summary_outbox')->count());
            $this->assertSame(1, $connection->table('jobs')->where('queue', 'electronic-invoices')->whereNotIn('id', $priorJobs)->count());
            $this->assertSame(1, $connection->table('electronic_document_series')->where('issuer_tax_id', $issuer)->where('document_type', 'daily_summary')->value('next_number') - 1);
            $this->assertSame(2, $connection->table('electronic_document_series')->where('issuer_tax_id', $issuer)->where('document_type', 'boleta')->value('next_number') - 2);
            $this->assertSame(2, $connection->table('electronic_document_events')->whereIn('electronic_document_id', [$documents[0]->id, $documents[1]->id])->where('event_type', 'included_in_receipt_summary')->count());
            $this->assertSame(0, $connection->table('electronic_document_events')->where('electronic_document_id', $documents[2]->id)->where('event_type', 'included_in_receipt_summary')->count());
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if ($worker instanceof Process && $worker->isRunning()) {
                $worker->stop(1);
            }
            $newJobs = $connection->table('jobs')->where('queue', 'electronic-invoices');
            if ($priorJobs !== []) {
                $newJobs->whereNotIn('id', $priorJobs);
            }
            $newJobs->delete();
            $documentIds = collect($documents)->pluck('id');
            $summaryIds = $connection->table('electronic_receipt_summary_items')->whereIn('electronic_document_id', $documentIds)->pluck('electronic_receipt_summary_id');
            $attemptIds = $connection->table('electronic_receipt_summary_attempts')->whereIn('electronic_receipt_summary_id', $summaryIds)->pluck('id');
            $connection->table('electronic_receipt_summary_outbox')->whereIn('electronic_receipt_summary_attempt_id', $attemptIds)->delete();
            $connection->table('electronic_receipt_summary_attempts')->whereIn('id', $attemptIds)->delete();
            $connection->table('electronic_receipt_summary_events')->whereIn('electronic_receipt_summary_id', $summaryIds)->delete();
            $connection->table('electronic_receipt_summary_items')->whereIn('electronic_receipt_summary_id', $summaryIds)->delete();
            $connection->table('electronic_receipt_summaries')->whereIn('id', $summaryIds)->delete();
            $connection->table('electronic_document_events')->whereIn('electronic_document_id', $documentIds)->delete();
            $connection->table('electronic_documents')->whereIn('id', $documentIds)->delete();
            foreach ($orders as $order) {
                $connection->table('orders')->where('id', $order->id)->delete();
                $connection->table('users')->where('id', $order->user_id)->delete();
            }
            $connection->table('electronic_document_series')->where('issuer_tax_id', $issuer)->delete();
            if ($settingsExisted) {
                $connection->table('electronic_invoice_settings')->where('id', $settings->id)->update(array_diff_key($previousSettings, array_flip(['id', 'created_at', 'updated_at'])));
            } else {
                $connection->table('electronic_invoice_settings')->where('id', $settings->id)->delete();
            }
            $connection->table('users')->where('id', $actor->id)->delete();
            Storage::disk('local')->delete([$certificatePath, ...collect($documents)->pluck('xml_path')->all()]);
            foreach ([$configPath, $readyPath, $resultPath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
