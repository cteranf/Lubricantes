<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentResolutionCase;
use App\Models\PaymentResolutionHistory;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\TreasuryPaymentResolutionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MySqlTreasuryPaymentReassignmentConcurrencyTest extends TestCase
{
    private const DATABASE = 'lubristore_2b4_resolution_certification_test';

    public function test_reassignment_barrier_paths_are_consistent_without_a_database(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lubristore-reassignment-race-'.uniqid('', true);
        $this->assertTrue(mkdir($directory) || is_dir($directory));
        $firstReady = $directory.DIRECTORY_SEPARATOR.'ready-first';
        $secondReady = $directory.DIRECTORY_SEPARATOR.'ready-second';
        $release = $directory.DIRECTORY_SEPARATOR.'release';
        $probe = base_path('tests/Support/mysql_treasury_reassignment_barrier_probe.php');
        $workers = [];

        try {
            $this->assertSame('mysql_treasury_reassignment_barrier_probe.php', basename($probe));
            $this->assertNotSame('mysql_treasury_reassignment_worker.php', basename($probe));
            $this->assertTrue(is_dir($directory));
            $this->assertTrue(is_writable($directory));
            foreach ([$firstReady, $secondReady] as $ready) {
                $environment = array_merge(getenv() ?: [], ['APP_ENV' => 'testing']);
                $process = proc_open([PHP_BINARY, $probe, '1', '1', $ready, $release, 'barrier_probe'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path(), $environment);
                $this->assertIsResource($process);
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $workers[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
            }

            $readyDeadline = microtime(true) + 5;
            while (microtime(true) < $readyDeadline) {
                clearstatcache(true, $firstReady);
                clearstatcache(true, $secondReady);
                if (is_file($firstReady) && is_file($secondReady)) {
                    break;
                }
                usleep(25_000);
            }
            clearstatcache(true, $firstReady);
            clearstatcache(true, $secondReady);
            $this->assertTrue(is_file($firstReady));
            $this->assertTrue(is_file($secondReady));
            $this->assertSame(7, file_put_contents($release, 'release', LOCK_EX));
            clearstatcache(true, $release);
            $this->assertTrue(is_file($release));
            $this->assertSame('release', file_get_contents($release));

            foreach ($workers as $worker) {
                $deadline = microtime(true) + 5;
                $workerExitCode = null;
                while (microtime(true) < $deadline) {
                    $status = proc_get_status($worker['process']);
                    if (! $status['running']) {
                        $workerExitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : null;
                        break;
                    }
                    usleep(25_000);
                }
                $stdout = stream_get_contents($worker['stdout']) ?: '';
                $stderr = stream_get_contents($worker['stderr']) ?: '';
                fclose($worker['stdout']);
                fclose($worker['stderr']);
                $exitCode = $workerExitCode ?? proc_close($worker['process']);
                if ($workerExitCode !== null) {
                    proc_close($worker['process']);
                }
                $this->assertSame(0, $exitCode, $stderr);
                $this->assertSame(['result' => 'success', 'ok' => true, 'release_observed' => true], json_decode($stdout, true));
            }
            $workers = [];
        } finally {
            foreach ($workers as $worker) {
                @proc_terminate($worker['process']);
                @fclose($worker['stdout']);
                @fclose($worker['stderr']);
                @proc_close($worker['process']);
            }
            @unlink($firstReady);
            @unlink($secondReady);
            @unlink($release);
            @rmdir($directory);
        }

        $this->assertDirectoryDoesNotExist($directory);
    }

    public function test_reassignment_barrier_after_bootstrap_uses_financial_worker_without_models(): void
    {
        $this->requireIsolatedMysql();
        $worker = base_path('tests/Support/mysql_treasury_reassignment_worker.php');
        $this->assertSame('mysql_treasury_reassignment_worker.php', basename($worker));
        $startedAt = microtime(true);
        $results = $this->runAdvisoryLockPair([
            'first' => [1, 1, 'barrier_probe_after_bootstrap'],
            'second' => [1, 1, 'barrier_probe_after_bootstrap'],
        ], $worker);
        $summary = $this->safeRaceSummary($results);

        foreach ($results as $result) {
            $this->assertSame(0, $result['exit_code'], $summary);
            $this->assertSame('success', $result['result'], $summary);
            $this->assertTrue($result['ready'], $summary);
            $this->assertTrue($result['release_signal_sent'], $summary);
            $this->assertTrue($result['json']['release_observed'] ?? false, $summary);
            $this->assertTrue($result['release_lock_hash_matches'], $summary);
            $this->assertTrue($result['lock_is_free'], $summary);
            $this->assertTrue($result['parent_connection_stable'], $summary);
            $this->assertTrue($result['parent_connection_ids_equal'], $summary);
            $this->assertSame($result['parent_connection_id_acquired'], $result['parent_connection_id_released'], $summary);
            $this->assertTrue($result['ready_file_seen'], $summary);
            $this->assertGreaterThan(0, $result['ready_file_bytes'], $summary);
            $this->assertTrue($result['ready_hash_valid'], $summary);
            $this->assertLessThan(10_000, $result['ready_detection_elapsed_ms'], $summary);
            $this->assertLessThan(20_000, $result['release_completed_ms'], $summary);
            $this->assertGreaterThanOrEqual($result['release_started_ms'], $result['release_completed_ms'], $summary);
        }
        $this->assertLessThan(20, microtime(true) - $startedAt, $summary);
    }

    public function test_consecutive_fixtures_use_distinct_explicit_unique_identifiers(): void
    {
        $this->requireIsolatedMysql();
        $first = $this->fixture('unique-identifiers-first');
        $second = $this->fixture('unique-identifiers-second');

        $this->assertNotSame($first['treasury']->email, $second['treasury']->email);
        $this->assertNotSame($first['customer']->email, $second['customer']->email);
        $this->assertStringStartsWith('treasury-', $first['treasury']->email);
        $this->assertStringEndsWith('@example.test', $first['treasury']->email);
        $this->assertStringStartsWith('customer-', $first['customer']->email);
        $this->assertStringEndsWith('@example.test', $first['customer']->email);

        foreach ([
            'submission idempotency key' => [$first['submission']->idempotency_key, $second['submission']->idempotency_key],
            'submission fingerprint' => [$first['submission']->duplicate_fingerprint, $second['submission']->duplicate_fingerprint],
            'normalized operation' => [$first['submission']->normalized_operation_number, $second['submission']->normalized_operation_number],
            'original reservation idempotency key' => [$first['original']->idempotency_key, $second['original']->idempotency_key],
            'transaction idempotency key' => [$first['transaction']->idempotency_key, $second['transaction']->idempotency_key],
            'resolution case idempotency key' => [$first['case']->idempotency_key, $second['case']->idempotency_key],
            'product SKU' => [$first['product']->sku, $second['product']->sku],
            'product slug' => [$first['product']->slug, $second['product']->slug],
            'warehouse code' => [$first['inventory']->warehouse->code, $second['inventory']->warehouse->code],
        ] as $label => [$firstValue, $secondValue]) {
            $this->assertTrue(is_string($firstValue) && is_string($secondValue), "Fixture identifier type is invalid: {$label}");
            $this->assertFalse(hash_equals($firstValue, $secondValue), "Duplicate fixture identifier: {$label}");
        }
    }

    public function test_concurrent_reassignment_is_idempotent(): void
    {
        $this->requireIsolatedMysql();
        $completed = 0;

        for ($iteration = 1; $iteration <= 10; $iteration++) {
            $fixture = $this->fixture("same-submission-{$iteration}");
            $results = $this->race([
                'first' => [$fixture['submission']->id, $fixture['treasury']->id, 'reassign'],
                'second' => [$fixture['submission']->id, $fixture['treasury']->id, 'reassign'],
            ]);
            $summary = "scenario=reassignment_vs_reassignment iteration={$iteration} ".$this->safeRaceSummary($results);

            foreach ($results as $result) {
                $this->assertTrue($result['ready'], $summary);
                $this->assertTrue($result['release_signal_sent'], $summary);
                $this->assertTrue($result['json']['release_observed'] ?? false, $summary);
                $this->assertSame(0, $result['exit_code'], $summary);
                $this->assertSame('success', $result['result'], $summary);
            }
            $this->assertSame($results['first']['json']['case_id'], $results['second']['json']['case_id'], $summary);
            $this->assertCompleteWinner($fixture);
            $this->assertSame(1, PaymentTransaction::where('payment_submission_id', $fixture['submission']->id)->count(), $summary);
            $this->assertGreaterThanOrEqual(0, $fixture['inventory']->fresh()->quantity, $summary);
            $this->assertGreaterThanOrEqual(0, $fixture['inventory']->fresh()->reserved_quantity, $summary);
            $completed++;
        }

        fwrite(STDOUT, PHP_EOL.json_encode([
            'scenario' => 'reassignment_vs_reassignment',
            'iterations' => $completed,
            'complete_idempotent' => $completed,
        ], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    public function test_two_orders_competing_for_last_stock_have_exactly_one_complete_winner(): void
    {
        $this->requireIsolatedMysql();
        $branches = ['first_won' => 0, 'second_won' => 0];

        for ($iteration = 1; $iteration <= 10; $iteration++) {
            $shared = $this->sharedStock("last-stock-{$iteration}", 1);
            $first = $this->fixture("last-stock-{$iteration}-first", null, $shared);
            $second = $this->fixture("last-stock-{$iteration}-second", null, $shared);
            $results = $this->race([
                'first' => [$first['submission']->id, $first['treasury']->id, 'reassign'],
                'second' => [$second['submission']->id, $second['treasury']->id, 'reassign'],
            ]);
            $summary = "scenario=last_stock iteration={$iteration} ".$this->safeRaceSummary($results);

            foreach ($results as $result) {
                $this->assertSame(0, $result['exit_code'], $summary);
                $this->assertContains($result['result'], ['success', 'domain_conflict'], $summary);
            }
            $fixtures = collect(['first' => $first, 'second' => $second]);
            $winnerKeys = $fixtures->filter(fn ($fixture) => $fixture['submission']->fresh()->status === PaymentSubmission::APPROVED)->keys();
            $losers = $fixtures->reject(fn ($fixture) => $fixture['submission']->fresh()->status === PaymentSubmission::APPROVED)->values();
            $this->assertCount(1, $winnerKeys, $summary);
            $this->assertCount(1, $losers, $summary);
            $winnerKey = $winnerKeys->first();
            $this->assertCompleteWinner($fixtures->get($winnerKey));
            $this->assertCompleteLoser($losers->first());
            $this->assertSame(1, collect($results)->where('result', 'success')->count(), $summary);
            $conflict = collect($results)->firstWhere('result', 'domain_conflict');
            $this->assertSame('insufficient_stock', $conflict['json']['code'] ?? null, $summary);
            $this->assertSame(0, $shared['inventory']->fresh()->quantity, $summary);
            $this->assertSame(0, $shared['inventory']->fresh()->reserved_quantity, $summary);
            $this->assertSame(0, $shared['product']->fresh()->stock, $summary);
            $this->assertSame(1, InventoryMovement::whereIn('reference_id', [(string) $first['order']->id, (string) $second['order']->id])->where('type', InventoryMovement::SALE)->count(), $summary);
            $branches[$winnerKey.'_won']++;
        }

        fwrite(STDOUT, PHP_EOL.json_encode([
            'scenario' => 'last_stock',
            'iterations' => array_sum($branches),
            'branches' => $branches,
            'controlled_insufficient_stock' => array_sum($branches),
        ], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    public function test_reassignment_racing_manual_cancellation_has_no_partial_financial_or_inventory_state(): void
    {
        $this->requireIsolatedMysql();
        $branches = ['reassignment_first' => 0, 'cancellation_first' => 0];

        for ($iteration = 1; $iteration <= 10; $iteration++) {
            $fixture = $this->fixture("manual-cancellation-{$iteration}");
            $results = $this->race([
                'first' => [$fixture['submission']->id, $fixture['treasury']->id, 'reassign'],
                'second' => [$fixture['submission']->id, $fixture['treasury']->id, 'cancel_order'],
            ]);
            $summary = "scenario=reassignment_vs_cancellation iteration={$iteration} ".$this->safeRaceSummary($results);

            foreach ($results as $result) {
                $this->assertSame(0, $result['exit_code'], $summary);
                $this->assertContains($result['result'], ['success', 'domain_conflict'], $summary);
            }
            $this->assertSame('canceled', $fixture['order']->fresh()->status, $summary);
            $this->assertGreaterThanOrEqual(0, $fixture['inventory']->fresh()->quantity, $summary);
            $this->assertGreaterThanOrEqual(0, $fixture['inventory']->fresh()->reserved_quantity, $summary);

            if ($fixture['submission']->fresh()->status === PaymentSubmission::APPROVED) {
                $this->assertCompleteWinner($fixture, true);
                $this->assertSame(1, InventoryMovement::where('reference_id', (string) $fixture['order']->id)->where('type', InventoryMovement::CANCELLATION_RETURN)->count(), $summary);
                $this->assertSame($fixture['initial']['quantity'], $fixture['inventory']->fresh()->quantity, $summary);
                $branches['reassignment_first']++;
            } else {
                $this->assertCompleteLoser($fixture);
                $this->assertSame(InventoryReservation::EXPIRED, $fixture['original']->fresh()->status, $summary);
                $this->assertSame(0, InventoryMovement::where('reference_id', (string) $fixture['order']->id)->count(), $summary);
                $this->assertSame('domain_conflict', $results['first']['result'], $summary);
                $branches['cancellation_first']++;
            }
        }

        fwrite(STDOUT, PHP_EOL.json_encode([
            'scenario' => 'reassignment_vs_cancellation',
            'iterations' => array_sum($branches),
            'branches' => $branches,
        ], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    public function test_multwarehouse_reassignment_racing_expiration_uses_numeric_balance_order(): void
    {
        $this->requireIsolatedMysql();
        $stocks = $this->adversarialStocks('reassign-expire');
        $reassignment = $this->multiWarehouseReassignmentFixture('reassign-expire-owner', $stocks);
        $expiration = $this->multiWarehouseReservationFixture('reassign-expire-target', $stocks, true);
        $results = $this->race([
            'first' => [$reassignment['submission']->id, $reassignment['treasury']->id, 'reassign'],
            'second' => [$expiration['submission']->id, $expiration['treasury']->id, 'expire_reservation'],
        ]);
        $summary = 'scenario=multwarehouse_reassignment_vs_expiration '.$this->safeRaceSummary($results);

        $this->assertSuccessfulRace($results, $summary);
        $this->assertCompleteMultiWarehouseWinner($reassignment, $summary);
        $this->assertSame('canceled', $expiration['order']->fresh()->status, $summary);
        $this->assertSame(2, InventoryReservation::where('order_id', $expiration['order']->id)->where('status', InventoryReservation::EXPIRED)->count(), $summary);
        $this->assertSharedStocks($stocks, 9, 0, $summary);
    }

    public function test_multwarehouse_reassignment_racing_cancellation_uses_numeric_balance_order(): void
    {
        $this->requireIsolatedMysql();
        $stocks = $this->adversarialStocks('reassign-cancel');
        $reassignment = $this->multiWarehouseReassignmentFixture('reassign-cancel-owner', $stocks);
        $cancellation = $this->multiWarehouseReservationFixture('reassign-cancel-target', $stocks, false);
        $results = $this->race([
            'first' => [$reassignment['submission']->id, $reassignment['treasury']->id, 'reassign'],
            'second' => [$cancellation['submission']->id, $cancellation['treasury']->id, 'cancel_order'],
        ]);
        $summary = 'scenario=multwarehouse_reassignment_vs_cancellation '.$this->safeRaceSummary($results);

        $this->assertSuccessfulRace($results, $summary);
        $this->assertCompleteMultiWarehouseWinner($reassignment, $summary);
        $this->assertSame('canceled', $cancellation['order']->fresh()->status, $summary);
        $this->assertSame(2, InventoryReservation::where('order_id', $cancellation['order']->id)->where('status', InventoryReservation::RELEASED)->count(), $summary);
        $this->assertSharedStocks($stocks, 9, 0, $summary);
    }

    public function test_multwarehouse_expiration_racing_cancellation_uses_numeric_balance_order(): void
    {
        $this->requireIsolatedMysql();
        $stocks = $this->adversarialStocks('expire-cancel');
        $expiration = $this->multiWarehouseReservationFixture('expire-cancel-expiration', $stocks, true);
        $cancellation = $this->multiWarehouseReservationFixture('expire-cancel-cancellation', $stocks, true);
        $results = $this->race([
            'first' => [$expiration['submission']->id, $expiration['treasury']->id, 'expire_reservation'],
            'second' => [$cancellation['submission']->id, $cancellation['treasury']->id, 'cancel_order'],
        ]);
        $summary = 'scenario=multwarehouse_expiration_vs_cancellation '.$this->safeRaceSummary($results);

        $this->assertSuccessfulRace($results, $summary);
        $this->assertSame('canceled', $expiration['order']->fresh()->status, $summary);
        $this->assertSame('canceled', $cancellation['order']->fresh()->status, $summary);
        $this->assertSame(2, InventoryReservation::where('order_id', $expiration['order']->id)->where('status', InventoryReservation::EXPIRED)->count(), $summary);
        $this->assertSame(2, InventoryReservation::where('order_id', $cancellation['order']->id)->where('status', InventoryReservation::RELEASED)->count(), $summary);
        $this->assertSharedStocks($stocks, 10, 0, $summary);
    }

    public function test_transfer_racing_reassignment_uses_complete_canonical_balances(): void
    {
        $this->requireIsolatedMysql();
        $warehouses = $this->adversarialTransferWarehouses('transfer-reassign');

        for ($iteration = 1; $iteration <= 10; $iteration++) {
            $stock = $this->transferStockOnWarehouses("transfer-reassign-{$iteration}", $warehouses, 10, 5);
            $shared = [
                'branch' => $stock['branch'],
                'warehouse' => $stock['lowWarehouse'],
                'product' => $stock['product'],
                'inventory' => $stock['lowInventory'],
            ];
            $fixture = $this->fixture("transfer-reassign-{$iteration}", null, $shared);
            $results = $this->race([
                'first' => [$stock['product']->id, $fixture['treasury']->id, 'transfer_inventory', $this->transferPayload($stock, false, 2)],
                'second' => [$fixture['submission']->id, $fixture['treasury']->id, 'reassign'],
            ]);
            $summary = "scenario=transfer_vs_reassignment iteration={$iteration} ".$this->safeRaceSummary($results);

            $this->assertSuccessfulRace($results, $summary);
            $this->assertSame(PaymentSubmission::APPROVED, $fixture['submission']->fresh()->status, $summary);
            $this->assertSame(PaymentTransaction::APPROVED, $fixture['transaction']->fresh()->status, $summary);
            $this->assertSame(PaymentResolutionCase::REASSIGNED, $fixture['case']->fresh()->status, $summary);
            $this->assertSame(7, (int) $stock['lowInventory']->fresh()->quantity, $summary);
            $this->assertSame(7, (int) $stock['highInventory']->fresh()->quantity, $summary);
            $this->assertSame(14, (int) $stock['product']->fresh()->stock, $summary);
            $this->assertSame(1, InventoryMovement::where('reference_type', 'order')->where('reference_id', (string) $fixture['order']->id)->where('type', InventoryMovement::SALE)->count(), $summary);
            $this->assertSame(2, InventoryMovement::where('reference_type', 'inventory_transfer')->where('product_id', $stock['product']->id)->count(), $summary);
        }
    }

    public function test_transfer_racing_expiration_preserves_complete_balances(): void
    {
        $this->requireIsolatedMysql();
        $warehouses = $this->adversarialTransferWarehouses('transfer-expire');

        for ($iteration = 1; $iteration <= 10; $iteration++) {
            $stock = $this->transferStockOnWarehouses("transfer-expire-{$iteration}", $warehouses, 10, 5);
            $expiration = $this->multiWarehouseReservationFixture("transfer-expire-{$iteration}", [
                [
                    'branch' => $stock['branch'],
                    'warehouse' => $stock['lowWarehouse'],
                    'product' => $stock['product'],
                    'inventory' => $stock['lowInventory'],
                ],
                [
                    'branch' => $stock['branch'],
                    'warehouse' => $stock['highWarehouse'],
                    'product' => $stock['product'],
                    'inventory' => $stock['highInventory'],
                ],
            ], true);
            $results = $this->race([
                'first' => [$stock['product']->id, $expiration['treasury']->id, 'transfer_inventory', $this->transferPayload($stock, false, 2)],
                'second' => [$expiration['submission']->id, $expiration['treasury']->id, 'expire_reservation'],
            ]);
            $summary = "scenario=transfer_vs_expiration iteration={$iteration} ".$this->safeRaceSummary($results);

            $this->assertSuccessfulRace($results, $summary);
            $this->assertSame('canceled', $expiration['order']->fresh()->status, $summary);
            $this->assertExpiredFixtureReservations($expiration, $summary);
            $this->assertSame(8, (int) $stock['lowInventory']->fresh()->quantity, $summary);
            $this->assertSame(7, (int) $stock['highInventory']->fresh()->quantity, $summary);
            $this->assertSame(0, (int) $stock['lowInventory']->fresh()->reserved_quantity, $summary);
            $this->assertSame(0, (int) $stock['highInventory']->fresh()->reserved_quantity, $summary);
            $this->assertSame(15, (int) $stock['product']->fresh()->stock, $summary);
            $this->assertSame(15, (int) WarehouseInventory::where('product_id', $stock['product']->id)->sum('quantity'), $summary);
            $this->assertTransferMovementPairForRace($stock, $expiration['treasury'], 2, 10, 8, 5, 7, $summary);
            $this->assertSame(0, InventoryMovement::where('reference_type', 'order')->where('reference_id', (string) $expiration['order']->id)->count(), $summary);
        }
    }

    public function test_opposite_transfers_use_the_same_canonical_balance_order(): void
    {
        $this->requireIsolatedMysql();
        $warehouses = $this->adversarialTransferWarehouses('opposite-transfer');

        for ($iteration = 1; $iteration <= 10; $iteration++) {
            $stock = $this->transferStockOnWarehouses("opposite-transfer-{$iteration}", $warehouses, 10, 10);
            $actor = User::factory()->create([
                'email' => 'inventory-'.$this->uniqueFixtureSuffix("opposite-transfer-{$iteration}").'@example.test',
                'role' => 'treasury',
                'is_active' => true,
            ]);
            $results = $this->race([
                'first' => [$stock['product']->id, $actor->id, 'transfer_inventory', $this->transferPayload($stock, false, 2)],
                'second' => [$stock['product']->id, $actor->id, 'transfer_inventory', $this->transferPayload($stock, true, 3)],
            ]);
            $summary = "scenario=opposite_transfers iteration={$iteration} ".$this->safeRaceSummary($results);

            $this->assertSuccessfulRace($results, $summary);
            $this->assertSame(11, (int) $stock['lowInventory']->fresh()->quantity, $summary);
            $this->assertSame(9, (int) $stock['highInventory']->fresh()->quantity, $summary);
            $this->assertSame(20, (int) $stock['product']->fresh()->stock, $summary);
            $this->assertSame(4, InventoryMovement::where('reference_type', 'inventory_transfer')->where('product_id', $stock['product']->id)->count(), $summary);
        }
    }

    private function requireIsolatedMysql(): void
    {
        $database = DB::selectOne('SELECT DATABASE() AS name')->name ?? null;
        $version = DB::selectOne('SELECT VERSION() AS version')->version ?? '';
        $this->assertSame('testing', app()->environment());
        $this->assertFalse((bool) config('app.debug'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(self::DATABASE, $database);
        $this->assertTrue(str_ends_with((string) $database, '_test'));
        $this->assertNotSame('lubricantes', $database);
        $this->assertTrue(str_starts_with((string) $version, '8.'));
        foreach (['070570', '070580', '070590', '070600', '070610', '070620'] as $suffix) {
            $this->assertSame(1, DB::table('migrations')->where('migration', 'like', "%{$suffix}%")->count(), "Migration {$suffix} must be applied.");
        }
    }

    private function sharedStock(string $suffix, int $quantity): array
    {
        $suffix = $this->uniqueFixtureSuffix($suffix);
        $branch = Branch::create(['code' => 'BR-'.$suffix, 'name' => 'Reassignment branch', 'address' => 'Test', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-'.$suffix, 'name' => 'Reassignment warehouse', 'is_active' => true]);
        $product = Product::create(['name' => 'Reassignment '.$suffix, 'slug' => 'reassignment-'.$suffix, 'sku' => 'R-'.Str::upper($suffix), 'price' => '20.00', 'sale_price' => '20.00', 'is_active' => true]);
        $product->forceFill(['stock' => $quantity])->save();
        $inventory = WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => $quantity, 'reserved_quantity' => 0]);

        return compact('branch', 'warehouse', 'product', 'inventory');
    }

    /** @return array<int, array{branch: Branch, warehouse: Warehouse, product: Product, inventory: WarehouseInventory}> */
    private function adversarialStocks(string $label): array
    {
        $suffix = $this->uniqueFixtureSuffix($label);
        $branch = Branch::create(['code' => 'ADV-'.$suffix, 'name' => 'Adversarial lock branch', 'address' => 'Test', 'is_active' => true]);
        $currentMaximum = (int) (Warehouse::max('id') ?? 0);
        $lowId = $currentMaximum + 1;
        $highId = 10 ** strlen((string) $lowId);
        if ($highId <= $lowId) {
            $highId *= 10;
        }
        while (strcmp((string) $lowId, (string) $highId) <= 0) {
            $lowId++;
        }
        $lowWarehouse = Warehouse::forceCreate(['id' => $lowId, 'branch_id' => $branch->id, 'code' => 'ADV-L-'.$suffix, 'name' => 'Numeric low', 'is_active' => true]);
        $highWarehouse = Warehouse::forceCreate(['id' => $highId, 'branch_id' => $branch->id, 'code' => 'ADV-H-'.$suffix, 'name' => 'Numeric high', 'is_active' => true]);
        $this->assertLessThan($highWarehouse->id, $lowWarehouse->id);
        $this->assertGreaterThan(0, strcmp((string) $lowWarehouse->id, (string) $highWarehouse->id));

        return collect([$highWarehouse, $lowWarehouse])->map(function (Warehouse $warehouse, int $index) use ($branch, $suffix): array {
            $product = Product::create([
                'name' => 'Adversarial '.$index.' '.$suffix,
                'slug' => 'adversarial-'.$index.'-'.$suffix,
                'sku' => 'ADV-'.$index.'-'.Str::upper($suffix),
                'price' => '20.00',
                'sale_price' => '20.00',
                'is_active' => true,
            ]);
            $product->forceFill(['stock' => 10])->save();
            $inventory = WarehouseInventory::create([
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => 10,
                'reserved_quantity' => 0,
            ]);

            return compact('branch', 'warehouse', 'product', 'inventory');
        })->all();
    }

    /** @return array{branch: Branch, lowWarehouse: Warehouse, highWarehouse: Warehouse} */
    private function adversarialTransferWarehouses(string $label): array
    {
        $suffix = $this->uniqueFixtureSuffix($label);
        $branch = Branch::create(['code' => 'TR-'.$suffix, 'name' => 'Transfer lock branch', 'address' => 'Test', 'is_active' => true]);
        $currentMaximum = (int) (Warehouse::max('id') ?? 0);
        $lowId = $currentMaximum + 1;
        $highId = 10 ** strlen((string) $lowId);
        if ($highId <= $lowId) {
            $highId *= 10;
        }
        while (strcmp((string) $lowId, (string) $highId) <= 0) {
            $lowId++;
        }
        $lowWarehouse = Warehouse::forceCreate(['id' => $lowId, 'branch_id' => $branch->id, 'code' => 'TR-L-'.$suffix, 'name' => 'Transfer numeric low', 'is_active' => true]);
        $highWarehouse = Warehouse::forceCreate(['id' => $highId, 'branch_id' => $branch->id, 'code' => 'TR-H-'.$suffix, 'name' => 'Transfer numeric high', 'is_active' => true]);
        $this->assertLessThan($highWarehouse->id, $lowWarehouse->id);

        return compact('branch', 'lowWarehouse', 'highWarehouse');
    }

    /** @return array{branch: Branch, lowWarehouse: Warehouse, highWarehouse: Warehouse, product: Product, lowInventory: WarehouseInventory, highInventory: WarehouseInventory} */
    private function transferStockOnWarehouses(string $label, array $warehouses, int $lowQuantity, int $highQuantity): array
    {
        $suffix = $this->uniqueFixtureSuffix($label);
        ['branch' => $branch, 'lowWarehouse' => $lowWarehouse, 'highWarehouse' => $highWarehouse] = $warehouses;
        $product = Product::create([
            'name' => 'Transfer '.$suffix,
            'slug' => 'transfer-'.$suffix,
            'sku' => 'TR-'.Str::upper($suffix),
            'price' => '20.00',
            'sale_price' => '20.00',
            'is_active' => true,
        ]);
        $product->forceFill(['stock' => $lowQuantity + $highQuantity])->save();
        // Insert high first so physical row ids disagree with canonical warehouse order.
        $highInventory = WarehouseInventory::create(['warehouse_id' => $highWarehouse->id, 'product_id' => $product->id, 'quantity' => $highQuantity, 'reserved_quantity' => 0]);
        $lowInventory = WarehouseInventory::create(['warehouse_id' => $lowWarehouse->id, 'product_id' => $product->id, 'quantity' => $lowQuantity, 'reserved_quantity' => 0]);
        $this->assertGreaterThan($highInventory->id, $lowInventory->id);

        return compact('branch', 'lowWarehouse', 'highWarehouse', 'product', 'lowInventory', 'highInventory');
    }

    /** @return array{product_id: int, source_warehouse_id: int, destination_warehouse_id: int, quantity: int} */
    private function transferPayload(array $stock, bool $reverse, int $quantity): array
    {
        return [
            'product_id' => (int) $stock['product']->id,
            'source_warehouse_id' => (int) ($reverse ? $stock['highWarehouse']->id : $stock['lowWarehouse']->id),
            'destination_warehouse_id' => (int) ($reverse ? $stock['lowWarehouse']->id : $stock['highWarehouse']->id),
            'quantity' => $quantity,
        ];
    }

    /**
     * @param  array{product: Product, lowWarehouse: Warehouse, highWarehouse: Warehouse}  $stock
     */
    private function assertTransferMovementPairForRace(array $stock, User $actor, int $quantity, int $sourceBefore, int $sourceAfter, int $destinationBefore, int $destinationAfter, string $summary): void
    {
        $movements = InventoryMovement::query()
            ->where('product_id', $stock['product']->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $movements, $summary);

        $outgoing = $movements->where('type', InventoryMovement::TRANSFER_OUT)->values();
        $incoming = $movements->where('type', InventoryMovement::TRANSFER_IN)->values();
        $this->assertCount(1, $outgoing, $summary);
        $this->assertCount(1, $incoming, $summary);
        $out = $outgoing->first();
        $in = $incoming->first();
        $this->assertInstanceOf(InventoryMovement::class, $out, $summary);
        $this->assertInstanceOf(InventoryMovement::class, $in, $summary);

        $this->assertSame($quantity, (int) $out->quantity, $summary);
        $this->assertSame($quantity, (int) $in->quantity, $summary);
        $this->assertSame((int) $stock['product']->id, (int) $out->product_id, $summary);
        $this->assertSame((int) $stock['product']->id, (int) $in->product_id, $summary);
        $this->assertSame((int) $stock['lowWarehouse']->id, (int) $out->warehouse_id, $summary);
        $this->assertSame((int) $stock['highWarehouse']->id, (int) $in->warehouse_id, $summary);
        $this->assertSame((int) $actor->id, (int) $out->user_id, $summary);
        $this->assertSame((int) $actor->id, (int) $in->user_id, $summary);
        $this->assertSame($sourceBefore, (int) $out->quantity_before, $summary);
        $this->assertSame($sourceAfter, (int) $out->quantity_after, $summary);
        $this->assertSame($destinationBefore, (int) $in->quantity_before, $summary);
        $this->assertSame($destinationAfter, (int) $in->quantity_after, $summary);
        $this->assertSame('inventory_transfer', $out->reference_type, $summary);
        $this->assertSame('inventory_transfer', $in->reference_type, $summary);
        $this->assertTrue(is_string($out->reference_id) && $out->reference_id !== '', 'Transfer-out reference is missing. '.$summary);
        $this->assertTrue(hash_equals((string) $out->reference_id, (string) $in->reference_id), 'Transfer movement correlation differs. '.$summary);
        $this->assertTrue(is_string($out->idempotency_key) && hash_equals('transfer-'.$out->reference_id.'-out', $out->idempotency_key), 'Transfer-out idempotency contract differs. '.$summary);
        $this->assertTrue(is_string($in->idempotency_key) && hash_equals('transfer-'.$in->reference_id.'-in', $in->idempotency_key), 'Transfer-in idempotency contract differs. '.$summary);
        $this->assertTransferMetadataAllowlist($out, $stock['lowWarehouse'], $stock['highWarehouse'], 'out', $summary);
        $this->assertTransferMetadataAllowlist($in, $stock['lowWarehouse'], $stock['highWarehouse'], 'in', $summary);
        $this->assertNotNull($out->created_at, $summary);
        $this->assertNotNull($in->created_at, $summary);
        $this->assertTrue($out->created_at->lessThanOrEqualTo($in->created_at), 'Transfer movement timestamps are incoherent. '.$summary);
    }

    private function assertTransferMetadataAllowlist(InventoryMovement $movement, Warehouse $source, Warehouse $destination, string $direction, string $summary): void
    {
        $metadata = $movement->metadata;
        $this->assertTrue(is_array($metadata), "Transfer-{$direction} metadata must be an associative array. ".$summary);
        $keys = array_keys($metadata);
        sort($keys, SORT_STRING);
        $this->assertTrue($keys === ['destination_warehouse_id', 'source_warehouse_id'], "Transfer-{$direction} metadata keys differ from the allowlist. ".$summary);
        $this->assertTrue(is_int($metadata['source_warehouse_id'] ?? null), "Transfer-{$direction} source metadata type is invalid. ".$summary);
        $this->assertTrue(is_int($metadata['destination_warehouse_id'] ?? null), "Transfer-{$direction} destination metadata type is invalid. ".$summary);
        $this->assertTrue(($metadata['source_warehouse_id'] ?? null) === (int) $source->id, "Transfer-{$direction} source metadata is incoherent. ".$summary);
        $this->assertTrue(($metadata['destination_warehouse_id'] ?? null) === (int) $destination->id, "Transfer-{$direction} destination metadata is incoherent. ".$summary);
        $this->assertTrue(
            ($direction === 'out' && (int) $movement->warehouse_id === (int) $source->id)
            || ($direction === 'in' && (int) $movement->warehouse_id === (int) $destination->id),
            "Transfer-{$direction} metadata direction is incoherent. ".$summary
        );
    }

    /** @param array{order: Order, reservations: array<int, InventoryReservation>} $fixture */
    private function assertExpiredFixtureReservations(array $fixture, string $summary): void
    {
        $reservations = InventoryReservation::query()
            ->where('order_id', $fixture['order']->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $reservations, $summary);

        $expected = collect($fixture['reservations'])->keyBy(fn (InventoryReservation $reservation) => (int) $reservation->id);
        $this->assertSame(2, $expected->count(), $summary);
        $this->assertTrue(
            $reservations->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all()
                === $expected->keys()->map(fn ($id) => (int) $id)->sort()->values()->all(),
            'Expiration reservations differ from the fixture. '.$summary
        );
        $this->assertTrue($reservations->every(fn (InventoryReservation $reservation) => $reservation->status === InventoryReservation::EXPIRED), 'Every fixture reservation must finish expired. '.$summary);

        foreach ($reservations as $reservation) {
            $original = $expected->get((int) $reservation->id);
            $this->assertInstanceOf(InventoryReservation::class, $original, $summary);
            $this->assertTrue((int) $reservation->order_id === (int) $fixture['order']->id, 'Reservation order differs from the fixture. '.$summary);
            $this->assertTrue((int) $reservation->order_item_id === (int) $original->order_item_id, 'Reservation item differs from the fixture. '.$summary);
            $this->assertTrue((int) $reservation->warehouse_id === (int) $original->warehouse_id, 'Reservation warehouse differs from the fixture. '.$summary);
            $this->assertTrue((int) $reservation->product_id === (int) $original->product_id, 'Reservation product differs from the fixture. '.$summary);
            $this->assertTrue((int) $reservation->quantity === (int) $original->quantity && (int) $reservation->quantity === 1, 'Reservation quantity differs from the fixture. '.$summary);
        }
    }

    private function multiWarehouseReassignmentFixture(string $label, array $stocks): array
    {
        $suffix = $this->uniqueFixtureSuffix($label);
        $treasury = User::factory()->create(['name' => 'Treasury '.$suffix, 'email' => "treasury-{$suffix}@example.test", 'role' => 'treasury', 'is_active' => true]);
        $customer = User::factory()->create(['name' => 'Customer '.$suffix, 'email' => "customer-{$suffix}@example.test", 'role' => 'customer', 'is_active' => true]);
        $order = $this->multiWarehouseOrder($customer, $suffix, $stocks);
        $items = [];
        $originals = [];
        foreach ($stocks as $stock) {
            $item = $this->multiWarehouseItem($order, $stock);
            $items[] = $item;
            $originals[] = InventoryReservation::forceCreate([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'reservation_sequence' => 1,
                'product_id' => $item->product_id,
                'warehouse_id' => $item->warehouse_id,
                'quantity' => 1,
                'status' => InventoryReservation::EXPIRED,
                'expires_at' => now()->subHour(),
                'released_at' => now()->subHour(),
                'idempotency_key' => 'adversarial-original-'.$suffix.'-'.$item->id,
                'metadata' => ['release_reason' => 'reservation_expired'],
            ]);
        }
        $submission = $this->locatorSubmission($order, $customer, $suffix);
        $case = app(TreasuryPaymentResolutionService::class)->open($submission->id, $treasury->id);
        $transaction = PaymentTransaction::where('payment_submission_id', $submission->id)->firstOrFail();

        return compact('treasury', 'customer', 'order', 'items', 'originals', 'submission', 'case', 'transaction', 'stocks');
    }

    private function multiWarehouseReservationFixture(string $label, array $stocks, bool $due): array
    {
        $suffix = $this->uniqueFixtureSuffix($label);
        $treasury = User::factory()->create(['name' => 'Treasury '.$suffix, 'email' => "treasury-{$suffix}@example.test", 'role' => 'treasury', 'is_active' => true]);
        $customer = User::factory()->create(['name' => 'Customer '.$suffix, 'email' => "customer-{$suffix}@example.test", 'role' => 'customer', 'is_active' => true]);
        $order = $this->multiWarehouseOrder($customer, $suffix, $stocks, $due);
        $reservations = [];
        foreach ($stocks as $stock) {
            $item = $this->multiWarehouseItem($order, $stock);
            $reservations[] = InventoryReservation::forceCreate([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'reservation_sequence' => 1,
                'product_id' => $item->product_id,
                'warehouse_id' => $item->warehouse_id,
                'quantity' => 1,
                'status' => InventoryReservation::ACTIVE,
                'expires_at' => $due ? now()->subMinute() : now()->addHour(),
                'idempotency_key' => 'adversarial-active-'.$suffix.'-'.$item->id,
                'metadata' => [],
            ]);
            $stock['inventory']->increment('reserved_quantity');
        }
        $submission = $this->locatorSubmission($order, $customer, $suffix);

        return compact('treasury', 'customer', 'order', 'reservations', 'submission');
    }

    private function multiWarehouseOrder(User $customer, string $suffix, array $stocks, bool $due = true): Order
    {
        return Order::create([
            'user_id' => $customer->id,
            'status' => 'pending',
            'fulfillment_status' => Order::FULFILLMENT_RESERVED,
            'total' => '40.00',
            'shipping_info' => ['address' => 'Adversarial locks'],
            'payment_method' => 'transferencia',
            'payment_status' => 'pending',
            'reserved_until' => $due ? now()->subMinute() : now()->addHour(),
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
        ]);
    }

    private function multiWarehouseItem(Order $order, array $stock): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $stock['product']->id,
            'warehouse_id' => $stock['warehouse']->id,
            'quantity' => 1,
            'price' => '20.00',
            'subtotal' => '20.00',
        ]);
    }

    private function locatorSubmission(Order $order, User $customer, string $suffix): PaymentSubmission
    {
        return PaymentSubmission::create([
            'order_id' => $order->id,
            'channel' => 'yape',
            'status' => PaymentSubmission::PENDING_REVIEW,
            'expected_amount' => '40.00',
            'currency' => 'PEN',
            'operation_number' => 'AD'.strtoupper(substr(hash('sha256', 'operation-'.$suffix), 0, 8)),
            'normalized_operation_number' => 'AD'.strtoupper(substr(hash('sha256', 'normalized-'.$suffix), 0, 10)),
            'duplicate_fingerprint' => hash('sha256', 'fingerprint-'.$suffix),
            'declared_paid_at' => now(),
            'receiving_account_snapshot' => [],
            'submitted_by' => $customer->id,
            'submitted_at' => now(),
            'idempotency_key' => 'adversarial-submission-'.$suffix,
        ]);
    }

    private function assertSuccessfulRace(array $results, string $summary): void
    {
        foreach ($results as $result) {
            $this->assertSame(0, $result['exit_code'], $summary);
            $this->assertSame('success', $result['result'], $summary);
            $this->assertTrue($result['json']['release_observed'] ?? false, $summary);
        }
    }

    private function assertCompleteMultiWarehouseWinner(array $fixture, string $summary): void
    {
        $this->assertSame(PaymentSubmission::APPROVED, $fixture['submission']->fresh()->status, $summary);
        $this->assertSame(PaymentTransaction::APPROVED, $fixture['transaction']->fresh()->status, $summary);
        $this->assertSame(PaymentResolutionCase::REASSIGNED, $fixture['case']->fresh()->status, $summary);
        $this->assertSame(2, InventoryReservation::where('resolution_case_id', $fixture['case']->id)->where('status', InventoryReservation::CONSUMED)->count(), $summary);
        $this->assertSame(2, InventoryMovement::where('reference_type', 'order')->where('reference_id', (string) $fixture['order']->id)->where('type', InventoryMovement::SALE)->count(), $summary);
    }

    private function assertSharedStocks(array $stocks, int $quantity, int $reserved, string $summary): void
    {
        foreach ($stocks as $stock) {
            $inventory = $stock['inventory']->fresh();
            $this->assertSame($quantity, (int) $inventory->quantity, $summary);
            $this->assertSame($reserved, (int) $inventory->reserved_quantity, $summary);
            $this->assertSame($quantity, (int) $stock['product']->fresh()->stock, $summary);
            $this->assertGreaterThanOrEqual(0, (int) $inventory->quantity, $summary);
            $this->assertGreaterThanOrEqual(0, (int) $inventory->reserved_quantity, $summary);
        }
    }

    private function fixture(string $suffix, ?User $treasury = null, ?array $shared = null): array
    {
        $suffix = $this->uniqueFixtureSuffix($suffix);
        $treasury ??= User::factory()->create([
            'name' => 'Treasury '.$suffix,
            'email' => "treasury-{$suffix}@example.test",
            'role' => 'treasury',
            'is_active' => true,
        ]);
        $customer = User::factory()->create([
            'name' => 'Customer '.$suffix,
            'email' => "customer-{$suffix}@example.test",
            'role' => 'customer',
            'is_active' => true,
        ]);
        $shared ??= $this->sharedStock('stock-'.$suffix, 10);
        $order = Order::create([
            'user_id' => $customer->id,
            'status' => 'pending',
            'fulfillment_status' => Order::FULFILLMENT_RESERVED,
            'total' => '20.00',
            'shipping_info' => ['address' => 'Concurrency'],
            'payment_method' => 'transferencia',
            'payment_status' => 'pending',
            'reserved_until' => now()->subMinute(),
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $shared['product']->id,
            'warehouse_id' => $shared['warehouse']->id,
            'quantity' => 1,
            'price' => '20.00',
            'subtotal' => '20.00',
        ]);
        $original = InventoryReservation::forceCreate([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'reservation_sequence' => 1,
            'product_id' => $shared['product']->id,
            'warehouse_id' => $shared['warehouse']->id,
            'quantity' => 1,
            'status' => InventoryReservation::EXPIRED,
            'expires_at' => now()->subHour(),
            'released_at' => now()->subHour(),
            'idempotency_key' => 'reassignment-original-'.$suffix,
            'metadata' => ['release_reason' => 'reservation_expired'],
        ]);
        $submission = PaymentSubmission::create([
            'order_id' => $order->id,
            'channel' => 'yape',
            'status' => PaymentSubmission::PENDING_REVIEW,
            'expected_amount' => '20.00',
            'currency' => 'PEN',
            'operation_number' => 'RR'.strtoupper(substr(hash('sha256', 'operation-'.$suffix), 0, 8)),
            'normalized_operation_number' => 'RR'.strtoupper(substr(hash('sha256', 'normalized-'.$suffix), 0, 10)),
            'duplicate_fingerprint' => hash('sha256', $suffix),
            'declared_paid_at' => now(),
            'receiving_account_snapshot' => [],
            'submitted_by' => $customer->id,
            'submitted_at' => now(),
            'idempotency_key' => 'reassignment-submission-'.$suffix,
        ]);
        $case = app(TreasuryPaymentResolutionService::class)->open($submission->id, $treasury->id);
        $transaction = PaymentTransaction::where('payment_submission_id', $submission->id)->firstOrFail();

        return [
            'treasury' => $treasury,
            'customer' => $customer,
            'order' => $order,
            'item' => $item,
            'original' => $original,
            'submission' => $submission,
            'case' => $case,
            'transaction' => $transaction,
            'inventory' => $shared['inventory'],
            'product' => $shared['product'],
            'initial' => [
                'quantity' => (int) $shared['inventory']->quantity,
                'reserved_quantity' => (int) $shared['inventory']->reserved_quantity,
                'product_stock' => (int) $shared['product']->stock,
            ],
        ];
    }

    private function uniqueFixtureSuffix(string $label): string
    {
        $label = Str::lower(Str::slug(Str::limit($label, 24, '')));

        return $label.'-'.bin2hex(random_bytes(8));
    }

    private function assertCompleteWinner(array $fixture, bool $canceledAfterward = false): void
    {
        $submission = $fixture['submission']->fresh();
        $transaction = $fixture['transaction']->fresh();
        $case = $fixture['case']->fresh();
        $order = $fixture['order']->fresh();
        $reservation = InventoryReservation::where('resolution_case_id', $case->id)->firstOrFail();
        $this->assertSame(PaymentSubmission::APPROVED, $submission->status);
        $this->assertSame(PaymentTransaction::APPROVED, $transaction->status);
        $this->assertSame(PaymentTransaction::approvedScopeKeyForOrder($order->id), $transaction->approved_scope_key);
        $this->assertSame(PaymentResolutionCase::REASSIGNED, $case->status);
        $this->assertSame(InventoryReservation::CONSUMED, $reservation->status);
        $this->assertNotNull($reservation->consumed_at);
        $this->assertSame('approved', $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame($canceledAfterward ? 'canceled' : 'pending', $order->status);
        $this->assertSame(1, PaymentSubmissionHistory::where('payment_submission_id', $submission->id)->where('event', PaymentSubmissionHistory::REASSIGNMENT_APPROVED)->count());
        $this->assertSame(1, PaymentResolutionHistory::where('payment_resolution_case_id', $case->id)->where('event', 'reassigned')->count());
        $this->assertSame(1, InventoryMovement::where('idempotency_key', 'sale-order-item-'.$fixture['item']->id)->count());
        $this->assertSame($fixture['inventory']->fresh()->quantity, $fixture['product']->fresh()->stock);
    }

    private function assertCompleteLoser(array $fixture): void
    {
        $this->assertSame(PaymentSubmission::MANUAL_RESOLUTION_REQUIRED, $fixture['submission']->fresh()->status);
        $this->assertSame(PaymentTransaction::RECEIVED, $fixture['transaction']->fresh()->status);
        $this->assertSame(PaymentResolutionCase::OPEN, $fixture['case']->fresh()->status);
        $this->assertSame(0, InventoryReservation::where('resolution_case_id', $fixture['case']->id)->count());
        $this->assertSame(0, InventoryMovement::where('reference_type', 'order')->where('reference_id', (string) $fixture['order']->id)->count());
        $this->assertSame(0, PaymentSubmissionHistory::where('payment_submission_id', $fixture['submission']->id)->where('event', PaymentSubmissionHistory::REASSIGNMENT_APPROVED)->count());
        $this->assertSame(0, PaymentResolutionHistory::where('payment_resolution_case_id', $fixture['case']->id)->where('event', 'reassigned')->count());
    }

    /** @param array<string,array{0:int,1:int,2:string,3?:array<string,int>}> $specifications */
    private function race(array $specifications): array
    {
        $this->requireIsolatedMysql();

        return $this->runAdvisoryLockPair(
            $specifications,
            base_path('tests/Support/mysql_treasury_reassignment_worker.php')
        );
    }

    /** @param array<string,array{0:int,1:int,2:string,3?:array<string,int>}> $specifications */
    private function runAdvisoryLockPair(array $specifications, string $workerPath): array
    {
        $raceStartedAt = microtime(true);
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lubristore-reassignment-race-'.Str::lower(Str::random(16));
        $this->assertTrue(@mkdir($directory) || is_dir($directory));
        $readyPaths = [
            'first' => $directory.DIRECTORY_SEPARATOR.'ready-first',
            'second' => $directory.DIRECTORY_SEPARATOR.'ready-second',
        ];
        $token = Str::lower(Str::random(20));
        $lockNames = [
            'first' => "lubri-reassign-{$token}-first",
            'second' => "lubri-reassign-{$token}-second",
        ];
        foreach ($lockNames as $lockName) {
            $this->assertMatchesRegularExpression('/\\A[a-z0-9-]+\\z/', $lockName);
            $this->assertLessThanOrEqual(64, strlen($lockName));
        }
        $lockHashes = array_map(fn (string $lockName): string => hash('sha256', $lockName), $lockNames);
        $locksHeld = ['first' => false, 'second' => false];
        $barrierPdo = $this->dedicatedBarrierPdo();
        $ownerConnectionId = $this->connectionId($barrierPdo);
        $currentConnectionId = null;
        $parentConnectionIdsEqual = null;
        $parentConnectionStable = false;
        $releaseSignalSent = false;
        $workers = [];
        $environment = array_merge(getenv() ?: [], [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => self::DATABASE,
        ]);

        try {
            foreach (['first', 'second'] as $name) {
                $this->assertTrue($this->acquireLock($barrierPdo, $lockNames[$name]), "Parent failed to acquire the {$name} advisory lock.");
                $locksHeld[$name] = true;
            }

            foreach ($specifications as $name => $specification) {
                [$submissionId, $actorId, $action] = $specification;
                $readyPath = $readyPaths[$name];
                $command = [PHP_BINARY, $workerPath, (string) $submissionId, (string) $actorId, $readyPath, $lockNames[$name], $action];
                if (isset($specification[3])) {
                    $command[] = json_encode($specification[3], JSON_THROW_ON_ERROR);
                }
                $process = proc_open(
                    $command,
                    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                    $pipes,
                    base_path(),
                    $environment
                );
                if (! is_resource($process)) {
                    $this->fail('Unable to start a reassignment worker.');
                }
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $workers[$name] = [
                    'process' => $process,
                    'stdout_pipe' => $pipes[1],
                    'stderr_pipe' => $pipes[2],
                    'stdout' => '',
                    'stderr' => '',
                    'ready' => false,
                    'release_signal_sent' => false,
                    'release_lock_hash' => null,
                    'release_lock_hash_matches' => false,
                    'lock_is_free' => false,
                    'parent_connection_stable' => false,
                    'parent_connection_id_acquired' => $ownerConnectionId,
                    'parent_connection_id_released' => null,
                    'parent_connection_ids_equal' => null,
                    'parent_connection_diagnostic' => null,
                    'ready_file_seen' => false,
                    'ready_file_bytes' => 0,
                    'ready_hash_valid' => false,
                    'process_running_when_ready_seen' => null,
                    'ready_detection_elapsed_ms' => null,
                    'polling_started_elapsed_ms' => null,
                    'first_ready_detected_ms' => null,
                    'second_ready_detected_ms' => null,
                    'release_started_ms' => null,
                    'release_completed_ms' => null,
                    'protocol_error' => null,
                    'exit_code' => null,
                    'running' => true,
                    'closed' => false,
                ];
            }

            $readyDetectionStartedAt = microtime(true);
            $pollingStartedElapsedMs = (int) round(($readyDetectionStartedAt - $raceStartedAt) * 1000);
            foreach ($workers as &$worker) {
                $worker['polling_started_elapsed_ms'] = $pollingStartedElapsedMs;
            }
            unset($worker);
            $readyDeadline = $readyDetectionStartedAt + 10;
            $firstReadyPath = $readyPaths['first'];
            $secondReadyPath = $readyPaths['second'];
            $firstReady = false;
            $secondReady = false;
            $firstReadyDetectedMs = null;
            $secondReadyDetectedMs = null;
            while (microtime(true) < $readyDeadline) {
                if (! $firstReady) {
                    clearstatcache(true, $firstReadyPath);
                    $firstReadyContents = @file_get_contents($firstReadyPath);
                    $workers['first']['ready_file_seen'] = $firstReadyContents !== false;
                    $workers['first']['ready_file_bytes'] = is_string($firstReadyContents) ? strlen($firstReadyContents) : 0;
                    $workers['first']['ready_hash_valid'] = $this->readyHashMatches($firstReadyContents, $lockHashes['first']);
                }

                if (! $secondReady) {
                    clearstatcache(true, $secondReadyPath);
                    $secondReadyContents = @file_get_contents($secondReadyPath);
                    $workers['second']['ready_file_seen'] = $secondReadyContents !== false;
                    $workers['second']['ready_file_bytes'] = is_string($secondReadyContents) ? strlen($secondReadyContents) : 0;
                    $workers['second']['ready_hash_valid'] = $this->readyHashMatches($secondReadyContents, $lockHashes['second']);
                }

                $this->refreshWorkerStatuses($workers);

                if (! $firstReady && $workers['first']['ready_hash_valid']) {
                    $firstReady = true;
                    $detectedMs = (int) round((microtime(true) - $readyDetectionStartedAt) * 1000);
                    $firstReadyDetectedMs = $detectedMs;
                    $workers['first']['ready'] = true;
                    $workers['first']['release_lock_hash'] = $lockHashes['first'];
                    $workers['first']['release_lock_hash_matches'] = true;
                    $workers['first']['process_running_when_ready_seen'] = $workers['first']['running'];
                    $workers['first']['ready_detection_elapsed_ms'] = $detectedMs;
                    $workers['first']['first_ready_detected_ms'] = $detectedMs;
                }

                if (! $secondReady && $workers['second']['ready_hash_valid']) {
                    $secondReady = true;
                    $detectedMs = (int) round((microtime(true) - $readyDetectionStartedAt) * 1000);
                    $secondReadyDetectedMs = $detectedMs;
                    $workers['second']['ready'] = true;
                    $workers['second']['release_lock_hash'] = $lockHashes['second'];
                    $workers['second']['release_lock_hash_matches'] = true;
                    $workers['second']['process_running_when_ready_seen'] = $workers['second']['running'];
                    $workers['second']['ready_detection_elapsed_ms'] = $detectedMs;
                    $workers['second']['second_ready_detected_ms'] = $detectedMs;
                }

                if ($firstReady && $secondReady && ! $releaseSignalSent) {
                    foreach ($workers as &$worker) {
                        $worker['first_ready_detected_ms'] = $firstReadyDetectedMs;
                        $worker['second_ready_detected_ms'] = $secondReadyDetectedMs;
                    }
                    unset($worker);

                    try {
                        $currentConnectionId = $this->connectionId($barrierPdo);
                        $parentConnectionIdsEqual = $currentConnectionId === $ownerConnectionId;
                        $parentConnectionStable = $parentConnectionIdsEqual;
                    } catch (\Throwable $exception) {
                        $diagnostic = $this->safeParentConnectionDiagnostic($exception);
                        foreach ($workers as &$worker) {
                            $worker['protocol_error'] = 'parent_connection_check_failed';
                            $worker['parent_connection_diagnostic'] = $diagnostic;
                            $worker['parent_connection_ids_equal'] = null;
                            $worker['parent_connection_stable'] = false;
                        }
                        unset($worker);
                        break;
                    }
                    if (! $parentConnectionStable) {
                        foreach ($workers as &$worker) {
                            $worker['protocol_error'] = 'parent_lock_connection_changed';
                            $worker['parent_connection_ids_equal'] = false;
                        }
                        unset($worker);
                        break;
                    }

                    $releaseStartedMs = (int) round((microtime(true) - $readyDetectionStartedAt) * 1000);
                    foreach ($workers as &$worker) {
                        $worker['release_started_ms'] = $releaseStartedMs;
                    }
                    unset($worker);
                    $releaseResults = [];
                    foreach (['first', 'second'] as $name) {
                        $released = $this->releaseLock($barrierPdo, $lockNames[$name]);
                        $releaseResults[$name] = $released;
                        if ((int) $released === 1) {
                            $locksHeld[$name] = false;
                        }
                    }
                    if ((int) $releaseResults['first'] !== 1 || (int) $releaseResults['second'] !== 1) {
                        foreach ($workers as &$worker) {
                            $worker['protocol_error'] = 'parent_release_failed';
                        }
                        unset($worker);
                        break;
                    }

                    $releaseSignalSent = true;
                    $releaseCompletedMs = (int) round((microtime(true) - $readyDetectionStartedAt) * 1000);
                    foreach ($workers as &$worker) {
                        $worker['release_signal_sent'] = true;
                        $worker['parent_connection_stable'] = true;
                        $worker['parent_connection_ids_equal'] = true;
                        $worker['parent_connection_id_released'] = $currentConnectionId;
                        $worker['release_completed_ms'] = $releaseCompletedMs;
                    }
                    unset($worker);
                    break;
                }
                if (collect($workers)->contains(fn ($worker) => ! $worker['running'] && ! $worker['ready'])) {
                    foreach ($workers as &$worker) {
                        if (! $worker['running'] && ! $worker['ready']) {
                            $worker['protocol_error'] ??= 'worker_exited_before_ready';
                        }
                    }
                    unset($worker);
                    break;
                }
                usleep(20_000);
            }
            if (! collect($workers)->every(fn ($worker) => $worker['ready'])) {
                foreach ($workers as &$worker) {
                    $worker['protocol_error'] ??= $worker['running'] ? 'ready_timeout' : 'worker_exited_before_ready';
                }
                unset($worker);
                $this->terminateWorkers($workers);

                return $this->finalizeWorkers($workers);
            }

            if (collect($workers)->contains(fn ($worker) => ! $worker['release_lock_hash_matches'])) {
                $this->terminateWorkers($workers);

                return $this->finalizeWorkers($workers);
            }

            if (! $releaseSignalSent) {
                $this->terminateWorkers($workers);

                return $this->finalizeWorkers($workers);
            }

            $deadline = microtime(true) + 30;
            while (microtime(true) < $deadline && $this->anyRunning($workers)) {
                $this->drainWorkers($workers);
                usleep(25_000);
            }
            if ($this->anyRunning($workers)) {
                foreach ($workers as &$worker) {
                    if ($this->isRunning($worker)) {
                        $worker['protocol_error'] ??= 'execution_timeout';
                    }
                }
                unset($worker);
                $this->terminateWorkers($workers);
            }

            $results = $this->finalizeWorkers($workers);
            foreach (['first', 'second'] as $name) {
                $results[$name]['lock_is_free'] = $this->isFreeLock($barrierPdo, $lockNames[$name]);
                $this->assertTrue($results[$name]['lock_is_free'], "The {$name} advisory lock remains held.");
            }

            return $results;
        } finally {
            foreach (['first', 'second'] as $name) {
                if ($locksHeld[$name]) {
                    $this->releaseLock($barrierPdo, $lockNames[$name]);
                    $locksHeld[$name] = false;
                }
                $this->assertTrue($this->isFreeLock($barrierPdo, $lockNames[$name]), "The {$name} advisory lock was not freed during cleanup.");
            }
            $this->cleanupWorkers($workers, $directory, $readyPaths);
            $barrierPdo = null;
        }
    }

    private function drainWorkers(array &$workers): void
    {
        foreach ($workers as &$worker) {
            if (! $worker['closed']) {
                $status = proc_get_status($worker['process']);
                if (! $status['running'] && $status['exitcode'] >= 0) {
                    $worker['exit_code'] = $status['exitcode'];
                }
                $worker['running'] = $status['running'];
                $worker['stdout'] .= stream_get_contents($worker['stdout_pipe']) ?: '';
                $worker['stderr'] .= stream_get_contents($worker['stderr_pipe']) ?: '';
            }
        }
        unset($worker);
    }

    private function refreshWorkerStatuses(array &$workers): void
    {
        foreach ($workers as &$worker) {
            if ($worker['closed']) {
                continue;
            }

            $status = proc_get_status($worker['process']);
            if (! $status['running'] && $status['exitcode'] >= 0) {
                $worker['exit_code'] = $status['exitcode'];
            }
            $worker['running'] = $status['running'];
        }
        unset($worker);
    }

    private function anyRunning(array $workers): bool
    {
        return collect($workers)->contains(fn ($worker) => $this->isRunning($worker));
    }

    private function isRunning(array $worker): bool
    {
        return ! $worker['closed'] && $worker['running'];
    }

    private function terminateWorkers(array &$workers): void
    {
        foreach ($workers as &$worker) {
            if ($this->isRunning($worker)) {
                @proc_terminate($worker['process']);
            }
        }
        unset($worker);
        $deadline = microtime(true) + 1;
        while (microtime(true) < $deadline && $this->anyRunning($workers)) {
            $this->drainWorkers($workers);
            usleep(20_000);
        }
    }

    private function finalizeWorkers(array &$workers): array
    {
        $results = [];
        foreach ($workers as $name => &$worker) {
            $this->drainWorkers($workers);
            $worker['stdout'] .= stream_get_contents($worker['stdout_pipe']) ?: '';
            $worker['stderr'] .= stream_get_contents($worker['stderr_pipe']) ?: '';
            fclose($worker['stdout_pipe']);
            fclose($worker['stderr_pipe']);
            $closeCode = proc_close($worker['process']);
            $worker['closed'] = true;
            $decoded = json_decode(trim($worker['stdout']), true);
            $result = $worker['protocol_error']
                ?? (is_array($decoded)
                    ? ($decoded['result'] ?? 'worker_error')
                    : ($worker['ready'] ? 'worker_exited_after_ready' : 'invalid_json'));
            $results[$name] = [
                'exit_code' => $worker['exit_code'] ?? $closeCode,
                'result' => $result,
                'json' => is_array($decoded) ? $decoded : null,
                'ready' => $worker['ready'],
                'release_signal_sent' => $worker['release_signal_sent'],
                'release_lock_hash' => $worker['release_lock_hash'],
                'release_lock_hash_matches' => $worker['release_lock_hash_matches'],
                'lock_is_free' => $worker['lock_is_free'],
                'parent_connection_stable' => $worker['parent_connection_stable'],
                'parent_connection_id_acquired' => $worker['parent_connection_id_acquired'],
                'parent_connection_id_released' => $worker['parent_connection_id_released'],
                'parent_connection_ids_equal' => $worker['parent_connection_ids_equal'],
                'parent_connection_diagnostic' => $worker['parent_connection_diagnostic'],
                'ready_file_seen' => $worker['ready_file_seen'],
                'ready_file_bytes' => $worker['ready_file_bytes'],
                'ready_hash_valid' => $worker['ready_hash_valid'],
                'process_running_when_ready_seen' => $worker['process_running_when_ready_seen'],
                'ready_detection_elapsed_ms' => $worker['ready_detection_elapsed_ms'],
                'polling_started_elapsed_ms' => $worker['polling_started_elapsed_ms'],
                'first_ready_detected_ms' => $worker['first_ready_detected_ms'],
                'second_ready_detected_ms' => $worker['second_ready_detected_ms'],
                'release_started_ms' => $worker['release_started_ms'],
                'release_completed_ms' => $worker['release_completed_ms'],
                'running' => false,
                'stderr_present' => trim($worker['stderr']) !== '',
            ];
        }
        unset($worker);

        return $results;
    }

    private function cleanupWorkers(array &$workers, string $directory, array $readyPaths): void
    {
        foreach ($workers as &$worker) {
            if (! $worker['closed']) {
                if ($this->isRunning($worker)) {
                    @proc_terminate($worker['process']);
                }
                @fclose($worker['stdout_pipe']);
                @fclose($worker['stderr_pipe']);
                @proc_close($worker['process']);
                $worker['closed'] = true;
            }
        }
        unset($worker);
        foreach ($readyPaths as $readyPath) {
            @unlink($readyPath);
        }
        @rmdir($directory);
        $this->assertDirectoryDoesNotExist($directory);
    }

    private function dedicatedBarrierPdo(): \PDO
    {
        $configuration = config('database.connections.mysql');
        $host = (string) ($configuration['host'] ?? '127.0.0.1');
        $port = (int) ($configuration['port'] ?? 3306);
        $database = (string) ($configuration['database'] ?? '');
        $username = (string) ($configuration['username'] ?? '');
        $password = (string) ($configuration['password'] ?? '');
        $charset = (string) ($configuration['charset'] ?? 'utf8mb4');
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
        $pdo = new \PDO($dsn, $username, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $databaseStatement = $pdo->query('SELECT DATABASE()');
        $versionStatement = $pdo->query('SELECT VERSION()');
        $effectiveDatabase = $databaseStatement === false ? null : $databaseStatement->fetchColumn();
        $version = $versionStatement === false ? null : $versionStatement->fetchColumn();
        $this->assertSame(self::DATABASE, $effectiveDatabase);
        $this->assertTrue(str_ends_with((string) $effectiveDatabase, '_test'));
        $this->assertNotSame('lubricantes', $effectiveDatabase);
        $this->assertTrue(str_starts_with((string) $version, '8.'));
        $this->assertGreaterThan(0, $this->connectionId($pdo));

        return $pdo;
    }

    private function readyHashMatches(string|false $contents, string $expectedHash): bool
    {
        if (! is_string($contents) || $contents === '') {
            return false;
        }

        $message = json_decode($contents, true);
        $actualHash = is_array($message) ? ($message['release_lock_hash'] ?? null) : null;

        return is_string($actualHash) && hash_equals($expectedHash, $actualHash);
    }

    private function acquireLock(\PDO $pdo, string $name): bool
    {
        $statement = $pdo->prepare('SELECT GET_LOCK(?, ?) AS acquired');
        $statement->execute([$name, 0]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function releaseLock(\PDO $pdo, string $name): ?int
    {
        $statement = $pdo->prepare('SELECT RELEASE_LOCK(?) AS released');
        $statement->execute([$name]);
        $released = $statement->fetchColumn();

        return $released === false || $released === null ? null : (int) $released;
    }

    private function isFreeLock(\PDO $pdo, string $name): bool
    {
        $statement = $pdo->prepare('SELECT IS_FREE_LOCK(?) AS is_free');
        $statement->execute([$name]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function connectionId(\PDO $pdo): int
    {
        $statement = $pdo->query('SELECT CONNECTION_ID()');
        $connectionId = $statement === false ? false : $statement->fetchColumn();

        return $connectionId === false ? 0 : (int) $connectionId;
    }

    private function safeParentConnectionDiagnostic(\Throwable $exception): array
    {
        $errorInfo = $exception instanceof \PDOException && is_array($exception->errorInfo)
            ? $exception->errorInfo
            : [];

        return [
            'stage' => 'parent_connection_check',
            'exception_class' => $exception::class,
            'sqlstate' => is_string($errorInfo[0] ?? null) ? $errorInfo[0] : null,
            'driver_code' => is_numeric($errorInfo[1] ?? null) ? (int) $errorInfo[1] : null,
        ];
    }

    private function safeRaceSummary(array $results): string
    {
        $safe = [];
        foreach ($results as $name => $result) {
            $safe[$name] = [
                'exit_code' => $result['exit_code'] ?? null,
                'result' => $result['result'] ?? null,
                'ready' => $result['ready'] ?? null,
                'release_signal_sent' => $result['release_signal_sent'] ?? null,
                'release_observed' => $result['json']['release_observed'] ?? null,
                'release_lock_hash' => $result['release_lock_hash'] ?? null,
                'release_lock_hash_matches' => $result['release_lock_hash_matches'] ?? null,
                'lock_is_free' => $result['lock_is_free'] ?? null,
                'parent_connection_stable' => $result['parent_connection_stable'] ?? null,
                'parent_connection_ids_equal' => $result['parent_connection_ids_equal'] ?? null,
                'parent_connection_diagnostic' => $result['parent_connection_diagnostic'] ?? null,
                'ready_file_seen' => $result['ready_file_seen'] ?? null,
                'ready_file_bytes' => $result['ready_file_bytes'] ?? null,
                'ready_hash_valid' => $result['ready_hash_valid'] ?? null,
                'process_running_when_ready_seen' => $result['process_running_when_ready_seen'] ?? null,
                'ready_detection_elapsed_ms' => $result['ready_detection_elapsed_ms'] ?? null,
                'polling_started_elapsed_ms' => $result['polling_started_elapsed_ms'] ?? null,
                'first_ready_detected_ms' => $result['first_ready_detected_ms'] ?? null,
                'second_ready_detected_ms' => $result['second_ready_detected_ms'] ?? null,
                'release_started_ms' => $result['release_started_ms'] ?? null,
                'release_completed_ms' => $result['release_completed_ms'] ?? null,
                'running' => $result['running'] ?? null,
                'ok' => $result['json']['ok'] ?? null,
                'status' => $result['json']['status'] ?? null,
                'code' => $result['json']['code'] ?? null,
                'message' => isset($result['json']['message']) ? substr(strip_tags((string) $result['json']['message']), 0, 200) : null,
                'exception_class' => $result['json']['exception_class'] ?? null,
                'stage' => $result['json']['stage'] ?? null,
                'sqlstate' => $result['json']['sqlstate'] ?? null,
                'driver_code' => $result['json']['driver_code'] ?? null,
                'previous_exception_class' => $result['json']['previous_exception_class'] ?? null,
            ];
        }

        return json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'race summary unavailable';
    }
}
