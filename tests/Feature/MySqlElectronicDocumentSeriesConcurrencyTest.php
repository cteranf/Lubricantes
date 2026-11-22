<?php

namespace Tests\Feature;

use App\Models\ElectronicDocument;
use App\Models\ElectronicInvoiceSetting;
use App\Models\Order;
use App\Models\User;
use App\Services\ElectronicDocumentEmissionService;
use App\Services\ElectronicDocumentSeriesAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\RequiresMySqlDriver;
use Tests\TestCase;

#[Group('mysql')]
class MySqlElectronicDocumentSeriesConcurrencyTest extends TestCase
{
    use RequiresMySqlDriver;

    private function workerCommand(string $script, string ...$arguments): array
    {
        $command = [PHP_BINARY];
        $ini = php_ini_loaded_file();

        if (is_string($ini) && $ini !== '') {
            array_push($command, '-c', $ini);
        }

        return [...$command, $script, ...$arguments];
    }

    private function isolatedDatabaseName(): string
    {
        $database = getenv('LARAVEL13_MYSQL_TEST_DATABASE');
        $this->assertIsString($database, 'Set LARAVEL13_MYSQL_TEST_DATABASE to the dedicated PHP 8.5/Laravel 13 test schema.');
        $this->assertMatchesRegularExpression('/\\Alubristore_php85_laravel13_[a-z0-9_]+\\z/', $database);
        $this->assertSame($database, config('database.connections.mysql.database'));

        return $database;
    }

    public function test_two_independent_connections_allocate_distinct_numbers_under_innodb_lock(): void
    {
        $database = $this->isolatedDatabaseName();
        $connection = DB::connection('mysql');
        $server = $connection->selectOne('SELECT DATABASE() AS database_name, @@default_storage_engine AS engine_name');
        $this->assertSame($database, $server->database_name);
        $this->assertSame('INNODB', strtoupper((string) $server->engine_name));

        $issuerTaxId = sprintf('20%09d', random_int(0, 999999999));
        $connectionId = (int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
        $initial = storage_path('framework/testing/sunat-series-'.bin2hex(random_bytes(8)));
        $this->assertTrue(mkdir($initial, 0700, true));
        $configPath = $initial.DIRECTORY_SEPARATOR.'connection.json';
        $readyPath = $initial.DIRECTORY_SEPARATOR.'worker.ready';
        $resultPath = $initial.DIRECTORY_SEPARATOR.'worker.result';
        $connectionConfig = config('database.connections.mysql');
        $this->assertSame($database, $connectionConfig['database'] ?? null);
        $this->assertSame(false, file_put_contents($configPath, json_encode($connectionConfig, JSON_THROW_ON_ERROR)) === false);
        @chmod($configPath, 0600);

        $connection->beginTransaction();
        $worker = null;

        try {
            $first = app(ElectronicDocumentSeriesAllocator::class)->reserve($connection, $issuerTaxId, 'testing', 'factura', 'F001');
            $this->assertSame(['series' => 'F001', 'document_number' => 1], $first);

            $worker = new Process($this->workerCommand(
                base_path('tests/Support/sunat-series-allocation-worker.php'),
                $configPath,
                $database,
                $issuerTaxId,
                $resultPath,
                $readyPath
            ), base_path(), array_merge($_ENV, $_SERVER, [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => $database,
            ]));
            $worker->setTimeout(30);
            $worker->start();

            $deadline = microtime(true) + 20;
            while (! is_file($readyPath) && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(20000);
            }
            $this->assertFileExists($readyPath, 'The independent worker must connect before it attempts allocation. '.$worker->getErrorOutput());
            $workerConnectionId = (int) file_get_contents($readyPath);
            $this->assertNotSame($connectionId, $workerConnectionId);

            usleep(350000);
            $this->assertTrue($worker->isRunning(), 'The second connection should remain blocked on the InnoDB series row until the first transaction commits.');
            $connection->commit();

            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            $second = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('F001', $second['series']);
            $this->assertSame(2, (int) $second['document_number']);
            $this->assertNotSame($connectionId, (int) $second['connection_id']);
            $this->assertSame(3, (int) $connection->table('electronic_document_series')
                ->where('issuer_tax_id', $issuerTaxId)->where('environment', 'testing')->where('document_type', 'factura')->value('next_number'));
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if ($worker instanceof Process && $worker->isRunning()) {
                $worker->stop(1);
            }
            $connection->table('electronic_document_series')->where('issuer_tax_id', $issuerTaxId)->delete();
            foreach ([$configPath, $readyPath, $resultPath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($initial)) {
                rmdir($initial);
            }
        }
    }

    public function test_two_independent_connections_confirm_the_same_draft_once_and_publish_one_job(): void
    {
        $database = $this->isolatedDatabaseName();
        $connection = DB::connection('mysql');
        $server = $connection->selectOne('SELECT DATABASE() AS database_name, @@default_storage_engine AS engine_name');
        $this->assertSame($database, $server->database_name);
        $this->assertSame('INNODB', strtoupper((string) $server->engine_name));
        config([
            'database.default' => 'mysql',
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'mysql',
            'electronic_invoicing.enabled' => true,
            'electronic_invoicing.sunat.xsd_directory' => base_path('tests/Fixtures/OasisUBL21'),
            'electronic_invoicing.sunat.invoice_xsd' => base_path('tests/Fixtures/OasisUBL21/xsd/maindoc/UBL-Invoice-2.1.xsd'),
        ]);

        $issuer = sprintf('20%09d', random_int(0, 999999999));
        $customer = User::factory()->create(['role' => 'customer']);
        $actor = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        $order = Order::query()->create([
            'user_id' => $customer->id, 'status' => 'paid', 'total' => '118.00', 'subtotal' => '118.00',
            'discount_total' => '0.00', 'shipping_amount' => '0.00', 'shipping_info' => [],
            'payment_method' => 'transferencia', 'payment_status' => 'approved', 'delivery_type' => 'pickup',
            'tracking_status' => 'pending', 'requested_receipt_type' => 'factura',
            'requested_recipient_name' => 'Cliente MySQL QA', 'requested_identity_type' => 'RUC',
            'requested_identity_number' => '20987654321', 'requested_receipt_email' => 'qa@example.test',
            'tax_calculation_status' => 'complete', 'tax_calculation_version' => 'peruvian-inclusive-tax-v1',
            'tax_snapshot' => ['status' => 'complete', 'missing_fields' => []],
        ]);
        $document = ElectronicDocument::query()->create([
            'order_id' => $order->id, 'draft_reference' => (string) Str::uuid(),
            'preparation_key' => (string) Str::uuid(), 'document_type' => 'factura',
            'status' => 'draft', 'environment' => 'testing', 'connection_type' => 'direct_sunat',
            'seller_snapshot' => ['commercial_name' => 'LubriStore QA', 'tax_id_number' => $issuer, 'legal_name' => 'Emisor QA SAC', 'country_code' => 'PE', 'fiscal_address' => 'Dirección QA'],
            'recipient_snapshot' => ['document_type' => 'RUC', 'document_number' => '20987654321', 'name' => 'Cliente QA'],
            'lines_snapshot' => ['items' => [[
                'name' => 'Producto QA', 'quantity' => 1, 'unit_price' => '118.00', 'subtotal' => '118.00',
                'tax' => ['calculation_status' => 'complete', 'tax_affectation' => ['code' => '10'], 'base' => '100.00', 'tax' => '18.00'],
            ]]],
            'amounts_snapshot' => ['total' => '118.00', 'operation_type' => '0101', 'payment_terms' => ['type' => 'cash', 'amount' => '118.00', 'source' => 'order_payment_status'], 'missing_fields' => [], 'tax_snapshot' => [
                'status' => 'complete', 'missing_fields' => [], 'totals' => [
                    'taxable_base_minor' => '100.00', 'exempt_base_minor' => '0.00', 'unaffected_base_minor' => '0.00', 'tax_minor' => '18.00',
                ],
            ]],
            'currency' => 'PEN', 'tax_calculation_status' => 'complete', 'version' => 1,
            'prepared_by' => $actor->id, 'prepared_at' => now(),
        ]);

        $settingsExisted = ElectronicInvoiceSetting::query()->where('singleton_key', 'primary')->exists();
        $settings = ElectronicInvoiceSetting::query()->firstOrCreate(['singleton_key' => 'primary'], ['environment' => 'testing', 'connection_type' => 'direct_sunat']);
        $priorSettings = $settings->getAttributes();
        $certificatePath = 'electronic-invoices/testing/mysql-confirm-'.bin2hex(random_bytes(8)).'.p12';
        Storage::disk('local')->put($certificatePath, 'synthetic-test-only');
        $settings->forceFill(['environment' => 'testing', 'connection_type' => 'direct_sunat', 'certificate_path' => $certificatePath, 'certificate_expires_at' => now()->addDay()])->save();

        $initial = storage_path('framework/testing/sunat-confirm-'.bin2hex(random_bytes(8)));
        $this->assertTrue(mkdir($initial, 0700, true));
        $configPath = $initial.DIRECTORY_SEPARATOR.'connection.json';
        $readyPath = $initial.DIRECTORY_SEPARATOR.'worker.ready';
        $resultPath = $initial.DIRECTORY_SEPARATOR.'worker.result';
        $this->assertSame($database, config('database.connections.mysql.database') ?? null);
        $this->assertNotFalse(file_put_contents($configPath, json_encode(config('database.connections.mysql'), JSON_THROW_ON_ERROR)));
        @chmod($configPath, 0600);
        $priorJobIds = $connection->table('jobs')->pluck('id')->all();

        $connection->beginTransaction();
        $worker = null;
        try {
            $connection->table('orders')->where('id', $order->id)->lockForUpdate()->first();
            $worker = new Process($this->workerCommand(
                base_path('tests/Support/electronic-document-confirm-worker.php'),
                $configPath, $database, (string) $document->id, (string) $actor->id, $resultPath, $readyPath
            ), base_path(), array_merge($_ENV, $_SERVER, [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database,
            ]));
            $worker->setTimeout(40);
            $worker->start();
            $deadline = microtime(true) + 20;
            while (! is_file($readyPath) && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(20000);
            }
            $this->assertFileExists($readyPath, 'The independent confirmation worker must connect before the lock race. '.$worker->getErrorOutput());
            $parentConnectionId = (int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
            $childConnectionId = (int) file_get_contents($readyPath);
            $this->assertNotSame($parentConnectionId, $childConnectionId);

            $first = app(ElectronicDocumentEmissionService::class)->confirm($document, $actor, 1);
            usleep(350000);
            $this->assertTrue($worker->isRunning(), 'The second connection must wait on the order row while the first confirmation is uncommitted.');
            $connection->commit();
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            $second = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame((int) $document->id, $second['id']);
            $this->assertSame('queued', $second['status']);
            $this->assertSame($first->series, $second['series']);
            $this->assertSame((int) $first->document_number, (int) $second['document_number']);
            $this->assertSame((int) $actor->id, (int) $second['confirmed_by']);
            $this->assertSame($first->confirmed_at->toISOString(), $second['confirmed_at']);
            $this->assertSame(1, $connection->table('electronic_document_attempts')->where('electronic_document_id', $document->id)->count());
            $this->assertSame(1, $connection->table('electronic_document_events')->where('electronic_document_id', $document->id)->where('event_type', 'confirmed')->count());
            $this->assertSame(1, $connection->table('electronic_document_outbox')->where('electronic_document_attempt_id', $connection->table('electronic_document_attempts')->where('electronic_document_id', $document->id)->value('id'))->whereNotNull('published_at')->count());
            $this->assertSame(1, $connection->table('jobs')->where('queue', 'electronic-invoices')->whereNotIn('id', $priorJobIds)->count());
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if ($worker instanceof Process && $worker->isRunning()) {
                $worker->stop(1);
            }
            $jobs = $connection->table('jobs')->where('queue', 'electronic-invoices');
            if ($priorJobIds !== []) {
                $jobs->whereNotIn('id', $priorJobIds);
            }
            $jobs->delete();
            $connection->table('electronic_document_outbox')->whereIn('electronic_document_attempt_id', function ($query) use ($document): void {
                $query->select('id')->from('electronic_document_attempts')->where('electronic_document_id', $document->id);
            })->delete();
            $connection->table('electronic_document_attempts')->where('electronic_document_id', $document->id)->delete();
            $connection->table('electronic_document_events')->where('electronic_document_id', $document->id)->delete();
            $connection->table('electronic_documents')->where('id', $document->id)->delete();
            $connection->table('electronic_document_series')->where('issuer_tax_id', $issuer)->delete();
            $connection->table('orders')->where('id', $order->id)->delete();
            if ($settingsExisted) {
                $connection->table('electronic_invoice_settings')->where('id', $settings->id)->update(array_diff_key($priorSettings, array_flip(['id', 'created_at', 'updated_at'])));
            } else {
                $connection->table('electronic_invoice_settings')->where('id', $settings->id)->delete();
            }
            $connection->table('users')->whereIn('id', [$customer->id, $actor->id])->delete();
            Storage::disk('local')->delete($certificatePath);
            foreach ([$configPath, $readyPath, $resultPath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($initial)) {
                rmdir($initial);
            }
        }
    }

    public function test_two_different_drafts_for_one_order_cannot_both_be_confirmed_concurrently(): void
    {
        $database = $this->isolatedDatabaseName();
        $connection = DB::connection('mysql');
        $server = $connection->selectOne('SELECT DATABASE() AS database_name, @@default_storage_engine AS engine_name');
        $this->assertSame($database, $server->database_name);
        $this->assertSame('INNODB', strtoupper((string) $server->engine_name));
        config([
            'database.default' => 'mysql',
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'mysql',
            'electronic_invoicing.enabled' => true,
            'electronic_invoicing.sunat.xsd_directory' => base_path('tests/Fixtures/OasisUBL21'),
            'electronic_invoicing.sunat.invoice_xsd' => base_path('tests/Fixtures/OasisUBL21/xsd/maindoc/UBL-Invoice-2.1.xsd'),
        ]);

        $issuer = sprintf('20%09d', random_int(0, 999999999));
        $customer = User::factory()->create(['role' => 'customer']);
        $actor = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        $order = Order::query()->create([
            'user_id' => $customer->id, 'status' => 'paid', 'total' => '118.00', 'subtotal' => '118.00',
            'discount_total' => '0.00', 'shipping_amount' => '0.00', 'shipping_info' => [],
            'payment_method' => 'transferencia', 'payment_status' => 'approved', 'delivery_type' => 'pickup',
            'tracking_status' => 'pending', 'requested_receipt_type' => 'factura',
            'requested_recipient_name' => 'Cliente MySQL QA', 'requested_identity_type' => 'RUC',
            'requested_identity_number' => '20987654321', 'requested_receipt_email' => 'qa@example.test',
            'tax_calculation_status' => 'complete', 'tax_calculation_version' => 'peruvian-inclusive-tax-v1',
            'tax_snapshot' => ['status' => 'complete', 'missing_fields' => []],
        ]);
        $firstDraft = $this->makeConfirmableDraft($order, $actor, $issuer);
        $secondDraft = $this->makeConfirmableDraft($order, $actor, $issuer);

        $settingsExisted = ElectronicInvoiceSetting::query()->where('singleton_key', 'primary')->exists();
        $settings = ElectronicInvoiceSetting::query()->firstOrCreate(['singleton_key' => 'primary'], ['environment' => 'testing', 'connection_type' => 'direct_sunat']);
        $priorSettings = $settings->getAttributes();
        $certificatePath = 'electronic-invoices/testing/mysql-confirm-'.bin2hex(random_bytes(8)).'.p12';
        Storage::disk('local')->put($certificatePath, 'synthetic-test-only');
        $settings->forceFill(['environment' => 'testing', 'connection_type' => 'direct_sunat', 'certificate_path' => $certificatePath, 'certificate_expires_at' => now()->addDay()])->save();

        $initial = storage_path('framework/testing/sunat-same-order-'.bin2hex(random_bytes(8)));
        $this->assertTrue(mkdir($initial, 0700, true));
        $configPath = $initial.DIRECTORY_SEPARATOR.'connection.json';
        $readyPath = $initial.DIRECTORY_SEPARATOR.'worker.ready';
        $resultPath = $initial.DIRECTORY_SEPARATOR.'worker.result';
        $this->assertSame($database, config('database.connections.mysql.database') ?? null);
        $this->assertNotFalse(file_put_contents($configPath, json_encode(config('database.connections.mysql'), JSON_THROW_ON_ERROR)));
        @chmod($configPath, 0600);
        $priorJobIds = $connection->table('jobs')->pluck('id')->all();
        $worker = null;

        $connection->beginTransaction();
        try {
            $connection->table('orders')->where('id', $order->id)->lockForUpdate()->first();
            $worker = new Process($this->workerCommand(
                base_path('tests/Support/electronic-document-confirm-worker.php'),
                $configPath, $database, (string) $secondDraft->id, (string) $actor->id, $resultPath, $readyPath
            ), base_path(), array_merge($_ENV, $_SERVER, [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database,
            ]));
            $worker->setTimeout(40);
            $worker->start();
            $deadline = microtime(true) + 20;
            while (! is_file($readyPath) && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(20000);
            }
            $this->assertFileExists($readyPath, 'The second connection must be ready before competing on the order lock. '.$worker->getErrorOutput());
            $parentConnectionId = (int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
            $childConnectionId = (int) file_get_contents($readyPath);
            $this->assertNotSame($parentConnectionId, $childConnectionId);

            $first = app(ElectronicDocumentEmissionService::class)->confirm($firstDraft, $actor, 1);
            usleep(350000);
            $this->assertTrue($worker->isRunning(), 'The second draft confirmation must wait on the same order row.');
            $connection->commit();
            $worker->wait();

            $this->assertSame(1, $worker->getExitCode(), $worker->getErrorOutput());
            $second = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(HttpException::class, $second['error']);
            $this->assertSame('queued', $connection->table('electronic_documents')->where('id', $firstDraft->id)->value('status'));
            $this->assertSame('draft', $connection->table('electronic_documents')->where('id', $secondDraft->id)->value('status'));
            $this->assertSame(1, $connection->table('electronic_document_attempts')->where('electronic_document_id', $firstDraft->id)->count());
            $this->assertSame(0, $connection->table('electronic_document_attempts')->where('electronic_document_id', $secondDraft->id)->count());
            $this->assertSame(1, $connection->table('jobs')->where('queue', 'electronic-invoices')->whereNotIn('id', $priorJobIds)->count());
            $this->assertNull($connection->table('electronic_documents')->where('id', $secondDraft->id)->value('document_number'));
            $this->assertSame(2, $connection->table('electronic_document_series')->where('issuer_tax_id', $issuer)->value('next_number'));
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if ($worker instanceof Process && $worker->isRunning()) {
                $worker->stop(1);
            }
            $jobs = $connection->table('jobs')->where('queue', 'electronic-invoices');
            if ($priorJobIds !== []) {
                $jobs->whereNotIn('id', $priorJobIds);
            }
            $jobs->delete();
            $documentIds = [$firstDraft->id, $secondDraft->id];
            $attemptIds = $connection->table('electronic_document_attempts')->whereIn('electronic_document_id', $documentIds)->pluck('id');
            $connection->table('electronic_document_outbox')->whereIn('electronic_document_attempt_id', $attemptIds)->delete();
            $connection->table('electronic_document_attempts')->whereIn('electronic_document_id', $documentIds)->delete();
            $connection->table('electronic_document_events')->whereIn('electronic_document_id', $documentIds)->delete();
            $connection->table('electronic_documents')->whereIn('id', $documentIds)->delete();
            $connection->table('electronic_document_series')->where('issuer_tax_id', $issuer)->delete();
            $connection->table('orders')->where('id', $order->id)->delete();
            if ($settingsExisted) {
                $connection->table('electronic_invoice_settings')->where('id', $settings->id)->update(array_diff_key($priorSettings, array_flip(['id', 'created_at', 'updated_at'])));
            } else {
                $connection->table('electronic_invoice_settings')->where('id', $settings->id)->delete();
            }
            $connection->table('users')->whereIn('id', [$customer->id, $actor->id])->delete();
            Storage::disk('local')->delete($certificatePath);
            foreach ([$configPath, $readyPath, $resultPath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($initial)) {
                rmdir($initial);
            }
        }
    }

    private function makeConfirmableDraft(Order $order, User $actor, string $issuer): ElectronicDocument
    {
        return ElectronicDocument::query()->create([
            'order_id' => $order->id, 'draft_reference' => (string) Str::uuid(),
            'preparation_key' => (string) Str::uuid(), 'document_type' => 'factura',
            'status' => 'draft', 'environment' => 'testing', 'connection_type' => 'direct_sunat',
            'seller_snapshot' => ['commercial_name' => 'LubriStore QA', 'tax_id_number' => $issuer, 'legal_name' => 'Emisor QA SAC', 'country_code' => 'PE', 'fiscal_address' => 'Dirección QA'],
            'recipient_snapshot' => ['document_type' => 'RUC', 'document_number' => '20987654321', 'name' => 'Cliente QA'],
            'lines_snapshot' => ['items' => [[
                'name' => 'Producto QA', 'quantity' => 1, 'unit_price' => '118.00', 'subtotal' => '118.00',
                'tax' => ['calculation_status' => 'complete', 'tax_affectation' => ['code' => '10'], 'base' => '100.00', 'tax' => '18.00'],
            ]]],
            'amounts_snapshot' => ['total' => '118.00', 'operation_type' => '0101', 'payment_terms' => ['type' => 'cash', 'amount' => '118.00', 'source' => 'order_payment_status'], 'missing_fields' => [], 'tax_snapshot' => [
                'status' => 'complete', 'missing_fields' => [], 'totals' => ['taxable_base_minor' => '100.00', 'exempt_base_minor' => '0.00', 'unaffected_base_minor' => '0.00', 'tax_minor' => '18.00'],
            ]],
            'currency' => 'PEN', 'tax_calculation_status' => 'complete', 'version' => 1,
            'prepared_by' => $actor->id, 'prepared_at' => now(),
        ]);
    }
}
