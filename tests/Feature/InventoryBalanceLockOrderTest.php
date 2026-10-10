<?php

namespace Tests\Feature;

use App\Exceptions\InventoryException;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\InventoryBalanceLockService;
use App\Services\InventoryReservationExpirationService;
use App\Services\InventoryService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PDOException;
use Tests\TestCase;

class InventoryBalanceLockOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_pairs_use_numeric_order_for_warehouses_products_and_duplicates(): void
    {
        $pairs = app(InventoryBalanceLockService::class)->canonicalPairs([
            ['warehouse_id' => '10', 'product_id' => '2'],
            ['warehouse_id' => '2', 'product_id' => '10'],
            ['warehouse_id' => '2', 'product_id' => '1'],
            ['warehouse_id' => 10, 'product_id' => 1],
            ['warehouse_id' => 2, 'product_id' => 1],
        ]);

        $this->assertSame([
            ['warehouse_id' => 2, 'product_id' => 1],
            ['warehouse_id' => 2, 'product_id' => 10],
            ['warehouse_id' => 10, 'product_id' => 1],
            ['warehouse_id' => 10, 'product_id' => 2],
        ], $pairs);
    }

    public function test_balance_lock_requires_an_outer_transaction_even_for_an_empty_set(): void
    {
        $originalDefault = config('database.default');
        $temporary = 'inventory_lock_guard';
        config([
            "database.connections.{$temporary}" => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => $temporary,
        ]);
        DB::purge($temporary);

        try {
            $this->expectException(LogicException::class);
            app(InventoryBalanceLockService::class)->lock([]);
        } finally {
            DB::purge($temporary);
            config(['database.default' => $originalDefault]);
            config()->offsetUnset("database.connections.{$temporary}");
        }
    }

    public function test_transfer_synchronizes_product_from_the_complete_locked_balance_set(): void
    {
        $suffix = Str::lower(Str::random(10));
        $branch = Branch::create(['code' => 'TR-'.$suffix, 'name' => 'Transfer locks', 'address' => 'Test', 'is_active' => true]);
        $source = Warehouse::create(['branch_id' => $branch->id, 'code' => 'TR-S-'.$suffix, 'name' => 'Source', 'is_active' => true]);
        $destination = Warehouse::create(['branch_id' => $branch->id, 'code' => 'TR-D-'.$suffix, 'name' => 'Destination', 'is_active' => true]);
        $third = Warehouse::create(['branch_id' => $branch->id, 'code' => 'TR-T-'.$suffix, 'name' => 'Third', 'is_active' => true]);
        $product = $this->product('Transfer '.$suffix, 'TR-'.$suffix);
        $sourceBalance = WarehouseInventory::create(['warehouse_id' => $source->id, 'product_id' => $product->id, 'quantity' => 10, 'reserved_quantity' => 0]);
        $destinationBalance = WarehouseInventory::create(['warehouse_id' => $destination->id, 'product_id' => $product->id, 'quantity' => 5, 'reserved_quantity' => 0]);
        WarehouseInventory::create(['warehouse_id' => $third->id, 'product_id' => $product->id, 'quantity' => 7, 'reserved_quantity' => 0]);
        $product->forceFill(['stock' => 22])->save();

        app(InventoryService::class)->transfer($product, $source, $destination, 3, 'Canonical transfer');

        $this->assertSame(7, (int) $sourceBalance->fresh()->quantity);
        $this->assertSame(8, (int) $destinationBalance->fresh()->quantity);
        $this->assertSame(22, (int) $product->fresh()->stock);
        $this->assertSame(22, (int) WarehouseInventory::where('product_id', $product->id)->sum('quantity'));
    }

    public function test_transfer_creates_a_missing_default_destination_at_zero_without_increasing_physical_stock(): void
    {
        $suffix = Str::lower(Str::random(10));
        $branch = Branch::create(['code' => 'TZ-'.$suffix, 'name' => 'Zero destination', 'address' => 'Test', 'is_active' => true]);
        $source = Warehouse::create(['branch_id' => $branch->id, 'code' => 'TZ-S-'.$suffix, 'name' => 'Source', 'is_default' => false, 'is_active' => true]);
        $destination = Warehouse::where('is_default', true)->firstOrFail();
        $third = Warehouse::create(['branch_id' => $branch->id, 'code' => 'TZ-T-'.$suffix, 'name' => 'Third', 'is_default' => false, 'is_active' => true]);
        $product = $this->product('Zero destination '.$suffix, 'TZ-'.$suffix);
        WarehouseInventory::create(['warehouse_id' => $source->id, 'product_id' => $product->id, 'quantity' => 10, 'reserved_quantity' => 0]);
        WarehouseInventory::create(['warehouse_id' => $third->id, 'product_id' => $product->id, 'quantity' => 7, 'reserved_quantity' => 0]);
        $product->forceFill(['stock' => 17])->save();

        app(InventoryService::class)->transfer($product, $source, $destination, 3, 'Zero destination transfer');

        $this->assertSame(7, (int) WarehouseInventory::where(['warehouse_id' => $source->id, 'product_id' => $product->id])->value('quantity'));
        $this->assertSame(3, (int) WarehouseInventory::where(['warehouse_id' => $destination->id, 'product_id' => $product->id])->value('quantity'));
        $this->assertSame(17, (int) WarehouseInventory::where('product_id', $product->id)->sum('quantity'));
        $this->assertSame(17, (int) $product->fresh()->stock);
    }

    public function test_transfer_rejects_a_missing_source_without_creating_or_repairing_it(): void
    {
        $suffix = Str::lower(Str::random(10));
        $branch = Branch::create(['code' => 'MS-'.$suffix, 'name' => 'Missing source', 'address' => 'Test', 'is_active' => true]);
        $source = Warehouse::create(['branch_id' => $branch->id, 'code' => 'MS-S-'.$suffix, 'name' => 'Source', 'is_active' => true]);
        $destination = Warehouse::create(['branch_id' => $branch->id, 'code' => 'MS-D-'.$suffix, 'name' => 'Destination', 'is_active' => true]);
        $product = $this->product('Missing source '.$suffix, 'MS-'.$suffix);
        WarehouseInventory::create(['warehouse_id' => $destination->id, 'product_id' => $product->id, 'quantity' => 5, 'reserved_quantity' => 0]);
        $product->forceFill(['stock' => 5])->save();
        $beforeMovements = DB::table('inventory_movements')->count();

        try {
            app(InventoryService::class)->transfer($product, $source, $destination, 1, 'Missing source transfer');
            $this->fail('A missing source balance must be rejected.');
        } catch (\App\Exceptions\InventoryException) {
        }

        $this->assertFalse(WarehouseInventory::where(['warehouse_id' => $source->id, 'product_id' => $product->id])->exists());
        $this->assertSame(5, (int) WarehouseInventory::where(['warehouse_id' => $destination->id, 'product_id' => $product->id])->value('quantity'));
        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertSame($beforeMovements, DB::table('inventory_movements')->count());
    }

    public function test_transfer_rejects_negative_aggregate_stock_without_repairing_it(): void
    {
        $suffix = Str::lower(Str::random(10));
        $branch = Branch::create(['code' => 'NS-'.$suffix, 'name' => 'Negative stock', 'address' => 'Test', 'is_active' => true]);
        $source = Warehouse::create(['branch_id' => $branch->id, 'code' => 'NS-S-'.$suffix, 'name' => 'Source', 'is_active' => true]);
        $destination = Warehouse::create(['branch_id' => $branch->id, 'code' => 'NS-D-'.$suffix, 'name' => 'Destination', 'is_active' => true]);
        $product = $this->product('Negative stock '.$suffix, 'NS-'.$suffix);
        WarehouseInventory::create(['warehouse_id' => $source->id, 'product_id' => $product->id, 'quantity' => 5, 'reserved_quantity' => 0]);
        WarehouseInventory::create(['warehouse_id' => $destination->id, 'product_id' => $product->id, 'quantity' => 0, 'reserved_quantity' => 0]);
        $product->forceFill(['stock' => -1])->save();

        try {
            app(InventoryService::class)->transfer($product, $source, $destination, 1, 'Negative aggregate transfer');
            $this->fail('A negative aggregate stock must be rejected.');
        } catch (\App\Exceptions\InventoryException) {
        }

        $this->assertSame(-1, (int) $product->fresh()->stock);
        $this->assertSame(5, (int) WarehouseInventory::where(['warehouse_id' => $source->id, 'product_id' => $product->id])->value('quantity'));
        $this->assertSame(0, (int) WarehouseInventory::where(['warehouse_id' => $destination->id, 'product_id' => $product->id])->value('quantity'));
    }

    public function test_failed_transfer_rolls_back_a_new_zero_destination_balance(): void
    {
        $suffix = Str::lower(Str::random(10));
        $branch = Branch::create(['code' => 'RB-'.$suffix, 'name' => 'Rollback destination', 'address' => 'Test', 'is_active' => true]);
        $source = Warehouse::create(['branch_id' => $branch->id, 'code' => 'RB-S-'.$suffix, 'name' => 'Source', 'is_active' => true]);
        $destination = Warehouse::where('is_default', true)->firstOrFail();
        $product = $this->product('Rollback destination '.$suffix, 'RB-'.$suffix);
        WarehouseInventory::create(['warehouse_id' => $source->id, 'product_id' => $product->id, 'quantity' => 2, 'reserved_quantity' => 0]);
        $product->forceFill(['stock' => 2])->save();

        try {
            app(InventoryService::class)->transfer($product, $source, $destination, 3, 'Rollback destination transfer');
            $this->fail('An excessive transfer must fail.');
        } catch (\App\Exceptions\InventoryException) {
        }

        $this->assertFalse(WarehouseInventory::where(['warehouse_id' => $destination->id, 'product_id' => $product->id])->exists());
        $this->assertSame(2, (int) WarehouseInventory::where(['warehouse_id' => $source->id, 'product_id' => $product->id])->value('quantity'));
        $this->assertSame(2, (int) $product->fresh()->stock);
    }

    public function test_transfer_retries_the_complete_transaction_with_fresh_models_and_rolls_back_the_first_attempt(): void
    {
        $fixture = $this->transferRetryFixture('real-retry');
        $destinationQuantitiesAtCheckpoint = [];
        $service = $this->instrumentedTransferService(function (int $attempt, Product $product, Warehouse $source, Warehouse $destination, ?User $actor, Collection $inventories) use (&$destinationQuantitiesAtCheckpoint): void {
            $balance = $inventories->first(fn (WarehouseInventory $inventory) => (int) $inventory->warehouse_id === (int) $destination->id);
            $this->assertInstanceOf(WarehouseInventory::class, $balance);
            $destinationQuantitiesAtCheckpoint[] = (int) $balance->quantity;

            if ($attempt === 1) {
                $balance->update(['quantity' => 99]);
                throw $this->recognizedConcurrencyException();
            }
        });

        [$out, $in] = $service->transfer($fixture['product'], $fixture['source'], $fixture['destination'], 1, 'Retried transfer', $fixture['actor']);

        $this->assertSame(2, $service->attempts);
        $this->assertSame([0, 0], $destinationQuantitiesAtCheckpoint, 'The first attempt mutation must be rolled back before the retry.');
        foreach (['product', 'source', 'destination', 'actor'] as $model) {
            $this->assertNotSame($service->hydrations[0][$model], $service->hydrations[1][$model], "{$model} must be hydrated again for the retry.");
            $this->assertSame($service->hydrations[0][$model]->getKey(), $service->hydrations[1][$model]->getKey());
        }
        $this->assertSame(1, WarehouseInventory::where(['warehouse_id' => $fixture['destination']->id, 'product_id' => $fixture['product']->id])->count());
        $this->assertSame(3, (int) $fixture['sourceBalance']->fresh()->quantity);
        $this->assertSame(1, (int) WarehouseInventory::where(['warehouse_id' => $fixture['destination']->id, 'product_id' => $fixture['product']->id])->value('quantity'));
        $this->assertSame(4, (int) $fixture['product']->fresh()->stock);
        $this->assertTransferMovementPair($out, $in, $fixture['product'], $fixture['source'], $fixture['destination'], $fixture['actor'], 1, 4, 3, 0, 1);
        $this->assertSame(2, InventoryMovement::where('reference_type', 'inventory_transfer')->where('product_id', $fixture['product']->id)->count());
    }

    public function test_transfer_does_not_retry_domain_exceptions(): void
    {
        $fixture = $this->transferRetryFixture('domain-no-retry');
        $service = $this->instrumentedTransferService(function (): void {
            throw new InventoryException('Synthetic domain conflict.');
        });

        try {
            $service->transfer($fixture['product'], $fixture['source'], $fixture['destination'], 1, 'Domain failure', $fixture['actor']);
            $this->fail('A domain exception must propagate.');
        } catch (InventoryException $exception) {
            $this->assertSame('Synthetic domain conflict.', $exception->getMessage());
        }

        $this->assertSame(1, $service->attempts);
        $this->assertFailedRetryFixtureWasRolledBack($fixture);
    }

    public function test_transfer_does_not_retry_non_concurrent_query_exceptions(): void
    {
        $fixture = $this->transferRetryFixture('query-no-retry');
        $expected = $this->nonConcurrentQueryException();
        $service = $this->instrumentedTransferService(function () use ($expected): void {
            throw $expected;
        });

        try {
            $service->transfer($fixture['product'], $fixture['source'], $fixture['destination'], 1, 'Query failure', $fixture['actor']);
            $this->fail('A non-concurrent query exception must propagate.');
        } catch (QueryException $exception) {
            $this->assertSame($expected, $exception);
        }

        $this->assertSame(1, $service->attempts);
        $this->assertFailedRetryFixtureWasRolledBack($fixture);
    }

    public function test_transfer_propagates_a_concurrency_error_after_exactly_three_attempts(): void
    {
        $fixture = $this->transferRetryFixture('deadlock-exhausted');
        $service = $this->instrumentedTransferService(function (): void {
            throw $this->recognizedConcurrencyException();
        });

        try {
            $service->transfer($fixture['product'], $fixture['source'], $fixture['destination'], 1, 'Deadlock exhaustion', $fixture['actor']);
            $this->fail('An exhausted concurrency error must propagate.');
        } catch (QueryException $exception) {
            $this->assertSame(40001, $exception->getCode());
        }

        $this->assertSame(3, $service->attempts);
        $this->assertFailedRetryFixtureWasRolledBack($fixture);
    }

    public function test_multireservation_expiration_releases_all_balances_once(): void
    {
        $fixture = $this->fixture(true);

        $first = app(InventoryReservationExpirationService::class)->expireOrder($fixture['order']->id);
        $second = app(InventoryReservationExpirationService::class)->expireOrder($fixture['order']->id);

        $this->assertTrue($first['processed']);
        $this->assertFalse($second['processed']);
        $this->assertSame(0, $fixture['lowInventory']->fresh()->reserved_quantity);
        $this->assertSame(0, $fixture['highInventory']->fresh()->reserved_quantity);
        $this->assertSame(10, $fixture['lowInventory']->fresh()->quantity);
        $this->assertSame(10, $fixture['highInventory']->fresh()->quantity);
        $this->assertSame(2, InventoryReservation::where('order_id', $fixture['order']->id)->where('status', InventoryReservation::EXPIRED)->count());
    }

    public function test_multireservation_cancellation_locks_all_balances_before_releasing_in_reservation_order(): void
    {
        $fixture = $this->fixture(false);
        $reservations = InventoryReservation::where('order_id', $fixture['order']->id)->orderBy('id')->get();
        $this->assertSame($fixture['highInventory']->warehouse_id, $reservations->first()->warehouse_id);
        $this->assertLessThan($fixture['highInventory']->warehouse_id, $fixture['lowInventory']->warehouse_id);

        $first = app(InventoryService::class)->releaseOrderReservation($fixture['order']);
        $second = app(InventoryService::class)->releaseOrderReservation($fixture['order']);

        $this->assertSame(['processed' => 2, 'skipped' => 0], $first);
        $this->assertSame(['processed' => 0, 'skipped' => 2], $second);
        $this->assertSame(0, $fixture['lowInventory']->fresh()->reserved_quantity);
        $this->assertSame(0, $fixture['highInventory']->fresh()->reserved_quantity);
        $this->assertSame(2, InventoryReservation::where('order_id', $fixture['order']->id)->where('status', InventoryReservation::RELEASED)->count());
    }

    public function test_multireservation_consumption_locks_balances_and_products_in_canonical_order_once(): void
    {
        $fixture = $this->fixture(false);

        $first = app(InventoryService::class)->consumeOrderReservation($fixture['order']);
        $second = app(InventoryService::class)->consumeOrderReservation($fixture['order']);

        $this->assertSame(['processed' => 2, 'skipped' => 0], $first);
        $this->assertSame(['processed' => 0, 'skipped' => 2], $second);
        $this->assertSame(8, $fixture['lowInventory']->fresh()->quantity);
        $this->assertSame(8, $fixture['highInventory']->fresh()->quantity);
        $this->assertSame(0, $fixture['lowInventory']->fresh()->reserved_quantity);
        $this->assertSame(0, $fixture['highInventory']->fresh()->reserved_quantity);
        $this->assertSame(8, $fixture['lowProduct']->fresh()->stock);
        $this->assertSame(8, $fixture['highProduct']->fresh()->stock);
        $this->assertSame(2, InventoryReservation::where('order_id', $fixture['order']->id)->where('status', InventoryReservation::CONSUMED)->count());
    }

    public function test_failure_on_second_canonical_balance_rolls_back_first_release_and_reservations(): void
    {
        $fixture = $this->fixture(false);
        $before = $this->snapshot($fixture);
        $trigger = 'inventory_second_balance_failure';
        DB::unprepared("CREATE TRIGGER {$trigger} BEFORE UPDATE ON warehouse_inventories WHEN OLD.id = {$fixture['highInventory']->id} BEGIN SELECT RAISE(ABORT, 'second balance failure'); END");

        try {
            app(InventoryService::class)->releaseOrderReservation($fixture['order']);
            $this->fail('The second balance failure did not abort the release.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('second balance failure', $exception->getMessage());
        } finally {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }

        $this->assertSame($before, $this->snapshot($fixture));
    }

    /** @return array{product: Product, source: Warehouse, destination: Warehouse, actor: User, sourceBalance: WarehouseInventory} */
    private function transferRetryFixture(string $label): array
    {
        $suffix = Str::lower(Str::random(10));
        $branch = Branch::create(['code' => 'RT-'.$suffix, 'name' => 'Retry '.$label, 'address' => 'Test', 'is_active' => true]);
        $source = Warehouse::create(['branch_id' => $branch->id, 'code' => 'RT-S-'.$suffix, 'name' => 'Source', 'is_active' => true]);
        $destination = Warehouse::create(['branch_id' => $branch->id, 'code' => 'RT-D-'.$suffix, 'name' => 'Destination', 'is_active' => true]);
        $product = $this->product('Retry '.$label.' '.$suffix, 'RT-'.$suffix);
        $actor = User::factory()->create(['email' => 'retry-'.$suffix.'@example.test', 'is_active' => true]);
        $sourceBalance = WarehouseInventory::create(['warehouse_id' => $source->id, 'product_id' => $product->id, 'quantity' => 4, 'reserved_quantity' => 0]);
        $product->forceFill(['stock' => 4])->save();

        return compact('product', 'source', 'destination', 'actor', 'sourceBalance');
    }

    private function instrumentedTransferService(Closure $checkpoint): InventoryService
    {
        return new class(app(InventoryReservationExpirationService::class), app(InventoryBalanceLockService::class), $checkpoint) extends InventoryService
        {
            public int $attempts = 0;

            /** @var array<int, array{product: Product, source: Warehouse, destination: Warehouse, actor: User|null}> */
            public array $hydrations = [];

            public function __construct(InventoryReservationExpirationService $expiration, InventoryBalanceLockService $locks, private Closure $checkpoint)
            {
                parent::__construct($expiration, $locks);
            }

            protected function afterTransferAttemptLocked(Product $product, Warehouse $source, Warehouse $destination, ?User $actor, Collection $inventories): void
            {
                $this->attempts++;
                $this->hydrations[] = compact('product', 'source', 'destination', 'actor');
                ($this->checkpoint)($this->attempts, $product, $source, $destination, $actor, $inventories);
            }
        };
    }

    private function recognizedConcurrencyException(): QueryException
    {
        return new QueryException('retry probe', [], new PDOException('database is locked', 40001));
    }

    private function nonConcurrentQueryException(): QueryException
    {
        return new QueryException('retry probe', [], new PDOException('synthetic non-concurrent database failure', 9999));
    }

    /** @param array{product: Product, source: Warehouse, destination: Warehouse, actor: User, sourceBalance: WarehouseInventory} $fixture */
    private function assertFailedRetryFixtureWasRolledBack(array $fixture): void
    {
        $this->assertSame(4, (int) $fixture['sourceBalance']->fresh()->quantity);
        $this->assertFalse(WarehouseInventory::where(['warehouse_id' => $fixture['destination']->id, 'product_id' => $fixture['product']->id])->exists());
        $this->assertSame(4, (int) $fixture['product']->fresh()->stock);
        $this->assertSame(0, InventoryMovement::where('product_id', $fixture['product']->id)->count());
    }

    private function assertTransferMovementPair(InventoryMovement $out, InventoryMovement $in, Product $product, Warehouse $source, Warehouse $destination, User $actor, int $quantity, int $sourceBefore, int $sourceAfter, int $destinationBefore, int $destinationAfter): void
    {
        $this->assertSame(InventoryMovement::TRANSFER_OUT, $out->type);
        $this->assertSame(InventoryMovement::TRANSFER_IN, $in->type);
        $this->assertSame($quantity, (int) $out->quantity);
        $this->assertSame($quantity, (int) $in->quantity);
        $this->assertSame((int) $product->id, (int) $out->product_id);
        $this->assertSame((int) $product->id, (int) $in->product_id);
        $this->assertSame((int) $source->id, (int) $out->warehouse_id);
        $this->assertSame((int) $destination->id, (int) $in->warehouse_id);
        $this->assertSame((int) $actor->id, (int) $out->user_id);
        $this->assertSame((int) $actor->id, (int) $in->user_id);
        $this->assertSame($sourceBefore, (int) $out->quantity_before);
        $this->assertSame($sourceAfter, (int) $out->quantity_after);
        $this->assertSame($destinationBefore, (int) $in->quantity_before);
        $this->assertSame($destinationAfter, (int) $in->quantity_after);
        $this->assertSame('inventory_transfer', $out->reference_type);
        $this->assertSame('inventory_transfer', $in->reference_type);
        $this->assertNotNull($out->reference_id);
        $this->assertSame($out->reference_id, $in->reference_id);
        $this->assertTrue(is_string($out->idempotency_key) && $out->idempotency_key !== '', 'Transfer-out idempotency key is missing.');
        $this->assertTrue(is_string($in->idempotency_key) && $in->idempotency_key !== '', 'Transfer-in idempotency key is missing.');
        $this->assertFalse(hash_equals($out->idempotency_key, $in->idempotency_key), 'Transfer movement idempotency keys must differ.');
        $this->assertTrue(str_ends_with($out->idempotency_key, '-out'), 'Transfer-out idempotency key has an invalid contract.');
        $this->assertTrue(str_ends_with($in->idempotency_key, '-in'), 'Transfer-in idempotency key has an invalid contract.');
    }

    /** @return array<string, mixed> */
    private function fixture(bool $expired): array
    {
        $suffix = Str::lower(Str::random(10));
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $branch = Branch::create(['code' => 'BL-'.$suffix, 'name' => 'Balance locks', 'address' => 'Test', 'is_active' => true]);
        $lowWarehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'LOW-'.$suffix, 'name' => 'Low', 'is_active' => true]);
        for ($index = 0; $index < 8; $index++) {
            Warehouse::create(['branch_id' => $branch->id, 'code' => "MID-{$index}-{$suffix}", 'name' => "Middle {$index}", 'is_active' => true]);
        }
        $highWarehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'HIGH-'.$suffix, 'name' => 'High', 'is_active' => true]);
        $lowProduct = $this->product('Low '.$suffix, 'LOW-'.$suffix);
        $highProduct = $this->product('High '.$suffix, 'HIGH-'.$suffix);
        $lowInventory = WarehouseInventory::create(['warehouse_id' => $lowWarehouse->id, 'product_id' => $lowProduct->id, 'quantity' => 10, 'reserved_quantity' => 2]);
        $highInventory = WarehouseInventory::create(['warehouse_id' => $highWarehouse->id, 'product_id' => $highProduct->id, 'quantity' => 10, 'reserved_quantity' => 2]);
        $lowProduct->forceFill(['stock' => 10])->save();
        $highProduct->forceFill(['stock' => 10])->save();
        $order = Order::create([
            'user_id' => $customer->id,
            'status' => 'pending',
            'fulfillment_status' => Order::FULFILLMENT_RESERVED,
            'total' => '40.00',
            'shipping_info' => ['address' => 'Test'],
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'reserved_until' => $expired ? now()->subMinute() : now()->addHour(),
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
        ]);

        // The higher warehouse reservation is deliberately inserted first.
        foreach ([[$highWarehouse, $highProduct], [$lowWarehouse, $lowProduct]] as [$warehouse, $product]) {
            $item = OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 2,
                'price' => '10.00',
                'subtotal' => '20.00',
            ]);
            InventoryReservation::forceCreate([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'reservation_sequence' => 1,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 2,
                'status' => InventoryReservation::ACTIVE,
                'expires_at' => $expired ? now()->subMinute() : now()->addHour(),
                'idempotency_key' => 'balance-lock-'.$suffix.'-'.$item->id,
                'metadata' => [],
            ]);
        }

        return compact('order', 'lowInventory', 'highInventory', 'lowProduct', 'highProduct');
    }

    private function product(string $name, string $sku): Product
    {
        return Product::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(5),
            'sku' => $sku,
            'price' => '10.00',
            'sale_price' => '10.00',
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function snapshot(array $fixture): array
    {
        return [
            'order' => $fixture['order']->fresh()->getAttributes(),
            'reservations' => InventoryReservation::where('order_id', $fixture['order']->id)->orderBy('id')->get()->map->getAttributes()->all(),
            'inventories' => WarehouseInventory::whereIn('id', [$fixture['lowInventory']->id, $fixture['highInventory']->id])->orderBy('id')->get()->map->getAttributes()->all(),
        ];
    }
}
