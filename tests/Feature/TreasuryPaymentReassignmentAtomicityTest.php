<?php

namespace Tests\Feature;

use App\Exceptions\PaymentReassignmentConflictException;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentResolutionCase;
use App\Models\PaymentSubmission;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\InventoryReservationExpirationService;
use App\Services\InventoryService;
use App\Services\TreasuryPaymentReassignmentService;
use App\Services\TreasuryPaymentResolutionService;
use App\Services\TreasuryPaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class TreasuryPaymentReassignmentAtomicityTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider physicalWriteFailures */
    public function test_each_physical_write_failure_rolls_back_the_complete_two_item_reassignment(string $point): void
    {
        $fixture = $this->fixture();
        $before = $this->snapshot($fixture);
        $trigger = 'reassign_atomic_'.str_replace('-', '_', $point);
        DB::unprepared($this->triggerSql($fixture, $point, $trigger));

        try {
            app(TreasuryPaymentReassignmentService::class)
                ->reassignAndApprove($fixture['submission'], $fixture['treasury']);
            $this->fail("The injected {$point} failure did not abort the reassignment.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString('atomicity failure', $exception->getMessage());
        } finally {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }

        $this->assertCompleteRollback($fixture, $before);
    }

    public static function physicalWriteFailures(): array
    {
        return [
            'first reservation insert' => ['reserve-insert-first'],
            'second reservation insert' => ['reserve-insert-second'],
            'first reserved balance update' => ['reserve-balance-first'],
            'second reserved balance update' => ['reserve-balance-second'],
            'first inventory consumption' => ['consume-inventory-first'],
            'second inventory consumption' => ['consume-inventory-second'],
            'first reservation consumption' => ['consume-reservation-first'],
            'second reservation consumption' => ['consume-reservation-second'],
            'first sale movement' => ['movement-first'],
            'second sale movement' => ['movement-second'],
            'first product synchronization' => ['product-first'],
            'second product synchronization' => ['product-second'],
            'payment transaction promotion' => ['transaction'],
            'payment submission approval' => ['submission'],
            'resolution case closure' => ['case'],
            'order financial update' => ['order'],
            'submission reassignment history' => ['submission-history'],
            'resolution reassignment history' => ['resolution-history'],
            'after final history before outer commit' => ['after-resolution-history'],
        ];
    }

    public function test_failure_after_reservation_savepoint_completes_rolls_back_every_prior_write(): void
    {
        $fixture = $this->fixture();
        $before = $this->snapshot($fixture);
        $real = app(InventoryService::class);
        $inventory = Mockery::mock($real)->makePartial();
        $inventory->shouldReceive('reserveVersionedForResolutionCaseWithLockedEvidence')->once()
            ->andReturnUsing(function (...$arguments) use ($real): never {
                $real->reserveVersionedForResolutionCaseWithLockedEvidence(...$arguments);
                throw new RuntimeException('failure after completed reservation savepoint');
            });

        try {
            (new TreasuryPaymentReassignmentService($inventory))
                ->reassignAndApprove($fixture['submission'], $fixture['treasury']);
            $this->fail('The outer transaction accepted a failure after reservation completed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('failure after completed reservation savepoint', $exception->getMessage());
        }

        $this->assertCompleteRollback($fixture, $before);
    }

    public function test_failure_after_consumption_savepoint_completes_rolls_back_every_inventory_and_financial_write(): void
    {
        $fixture = $this->fixture();
        $before = $this->snapshot($fixture);
        $real = app(InventoryService::class);
        $inventory = Mockery::mock($real)->makePartial();
        $inventory->shouldReceive('consumeVersionedReservationsForResolutionCase')->once()
            ->andReturnUsing(function (...$arguments) use ($real): never {
                $real->consumeVersionedReservationsForResolutionCase(...$arguments);
                throw new RuntimeException('failure after completed consumption savepoint');
            });

        try {
            (new TreasuryPaymentReassignmentService($inventory))
                ->reassignAndApprove($fixture['submission'], $fixture['treasury']);
            $this->fail('The outer transaction accepted a failure after consumption completed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('failure after completed consumption savepoint', $exception->getMessage());
        }

        $this->assertCompleteRollback($fixture, $before);
    }

    /** @dataProvider canceledOrderWriteFailures */
    public function test_canceled_expired_order_is_not_partially_reopened_when_a_late_write_fails(string $point): void
    {
        $fixture = $this->fixture(true);
        $this->assertSame('canceled', $fixture['order']->fresh()->status);
        $before = $this->snapshot($fixture);
        $trigger = 'reassign_reopen_'.str_replace('-', '_', $point);
        DB::unprepared($this->triggerSql($fixture, $point, $trigger));

        try {
            app(TreasuryPaymentReassignmentService::class)
                ->reassignAndApprove($fixture['submission'], $fixture['treasury']);
            $this->fail("The injected {$point} failure did not abort reopening.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString('atomicity failure', $exception->getMessage());
        } finally {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }

        $this->assertCompleteRollback($fixture, $before);
        $this->assertSame('canceled', $fixture['order']->fresh()->status);
        $this->assertSame(InventoryReservation::EXPIRED, $fixture['originals'][0]->fresh()->status);
    }

    public static function canceledOrderWriteFailures(): array
    {
        return [
            'before order reopening' => ['transaction'],
            'combined financial and reopening order update' => ['order'],
            'reopening history insert' => ['reopen-history'],
            'after reopening history before outer commit' => ['after-reopen-history'],
        ];
    }

    public function test_real_unique_collision_during_reservation_creation_rolls_back_without_false_recovery(): void
    {
        $fixture = $this->fixture();
        $otherOrder = Order::create([
            'user_id' => $fixture['customer']->id,
            'status' => 'pending',
            'fulfillment_status' => Order::FULFILLMENT_RESERVED,
            'total' => '1.00',
            'shipping_info' => ['address' => 'Isolation'],
            'payment_method' => 'transferencia',
            'payment_status' => 'pending',
            'reserved_until' => now()->addHour(),
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
        ]);
        $otherItem = OrderItem::create([
            'order_id' => $otherOrder->id,
            'product_id' => $fixture['products'][0]->id,
            'warehouse_id' => $fixture['inventories'][0]->warehouse_id,
            'quantity' => 1,
            'price' => '1.00',
            'subtotal' => '1.00',
        ]);
        InventoryReservation::forceCreate([
            'order_id' => $otherOrder->id,
            'order_item_id' => $otherItem->id,
            'reservation_sequence' => 1,
            'product_id' => $otherItem->product_id,
            'warehouse_id' => $otherItem->warehouse_id,
            'quantity' => 1,
            'status' => InventoryReservation::ACTIVE,
            'expires_at' => now()->addHour(),
            'idempotency_key' => 'treasury-resolution-reassign-case-'.$fixture['case']->id.'-item-'.$fixture['items'][1]->id.'-seq-2',
            'metadata' => [],
        ]);
        $before = $this->snapshot($fixture);

        try {
            app(TreasuryPaymentReassignmentService::class)
                ->reassignAndApprove($fixture['submission'], $fixture['treasury']);
            $this->fail('A real UNIQUE collision was accepted.');
        } catch (PaymentReassignmentConflictException) {
            $this->assertCompleteRollback($fixture, $before);
        }
    }

    public function test_central_classifier_does_not_treat_non_unique_sqlite_failures_as_unique(): void
    {
        $classifier = app(TreasuryPaymentService::class);

        $this->assertTrue($classifier->isUniqueViolation($this->queryException('23000', 19, 'UNIQUE constraint failed: table.value')));
        $this->assertFalse($classifier->isUniqueViolation($this->queryException('23000', 19, 'FOREIGN KEY constraint failed')));
        $this->assertFalse($classifier->isUniqueViolation($this->queryException('23000', 19, 'NOT NULL constraint failed: table.value')));
    }

    private function fixture(bool $expireAutomatically = false): array
    {
        $treasury = User::factory()->create(['role' => 'treasury', 'is_active' => true]);
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $branch = Branch::create(['code' => 'BR-AT-'.Str::random(8), 'name' => 'Atomicidad', 'address' => 'Dirección', 'is_active' => true]);
        $order = Order::create([
            'user_id' => $customer->id,
            'status' => 'pending',
            'fulfillment_status' => Order::FULFILLMENT_RESERVED,
            'total' => '50.00',
            'shipping_info' => ['address' => 'Prueba'],
            'payment_method' => 'transferencia',
            'payment_status' => 'pending',
            'reserved_until' => now()->subMinute(),
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
        ]);
        $items = $inventories = $products = $originals = [];
        for ($index = 0; $index < 2; $index++) {
            $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-AT-'.Str::random(8), 'name' => 'Almacén '.$index, 'is_active' => true]);
            $product = Product::create(['name' => 'Producto '.Str::random(8), 'slug' => 'atomicidad-'.Str::uuid(), 'sku' => 'AT-'.Str::upper(Str::random(8)), 'price' => '12.50', 'is_active' => true]);
            $product->forceFill(['stock' => 10])->save();
            $inventory = WarehouseInventory::create([
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => 10,
                'reserved_quantity' => $expireAutomatically ? 2 : 0,
            ]);
            $item = OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 2,
                'price' => '12.50',
                'subtotal' => '25.00',
            ]);
            $reservation = InventoryReservation::forceCreate([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'reservation_sequence' => 1,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 2,
                'status' => $expireAutomatically ? InventoryReservation::ACTIVE : InventoryReservation::EXPIRED,
                'expires_at' => now()->subHour(),
                'released_at' => $expireAutomatically ? null : now()->subHour(),
                'idempotency_key' => 'atomicity-original-'.$item->id.'-'.Str::uuid(),
                'metadata' => $expireAutomatically ? [] : ['release_reason' => 'reservation_expired'],
            ]);
            $items[] = $item;
            $inventories[] = $inventory;
            $products[] = $product;
            $originals[] = $reservation;
        }
        $submission = PaymentSubmission::create([
            'order_id' => $order->id,
            'channel' => 'yape',
            'status' => PaymentSubmission::PENDING_REVIEW,
            'expected_amount' => $order->total,
            'currency' => 'PEN',
            'operation_number' => 'AT'.Str::upper(Str::random(8)),
            'normalized_operation_number' => Str::upper(Str::random(12)),
            'duplicate_fingerprint' => hash('sha256', Str::uuid()->toString()),
            'declared_paid_at' => now(),
            'receiving_account_snapshot' => [],
            'submitted_by' => $customer->id,
            'submitted_at' => now(),
            'idempotency_key' => 'atomicity-submission-'.Str::uuid(),
        ]);

        if ($expireAutomatically) {
            app(InventoryReservationExpirationService::class)->expireOrder($order->id);
        }
        $case = app(TreasuryPaymentResolutionService::class)->open($submission->id, $treasury->id);
        $transaction = PaymentTransaction::where('payment_submission_id', $submission->id)->firstOrFail();

        return compact('treasury', 'customer', 'order', 'submission', 'case', 'transaction', 'items', 'inventories', 'products', 'originals');
    }

    private function triggerSql(array $fixture, string $point, string $trigger): string
    {
        $firstItem = $fixture['items'][0]->id;
        $secondItem = $fixture['items'][1]->id;
        $firstInventory = $fixture['inventories'][0]->id;
        $secondInventory = $fixture['inventories'][1]->id;
        $firstProduct = $fixture['products'][0]->id;
        $secondProduct = $fixture['products'][1]->id;
        $case = $fixture['case']->id;
        $submission = $fixture['submission']->id;
        $transaction = $fixture['transaction']->id;
        $order = $fixture['order']->id;

        [$timing, $operation, $table, $condition] = match ($point) {
            'reserve-insert-first' => ['BEFORE', 'INSERT', 'inventory_reservations', "NEW.resolution_case_id = {$case} AND NEW.order_item_id = {$firstItem}"],
            'reserve-insert-second' => ['BEFORE', 'INSERT', 'inventory_reservations', "NEW.resolution_case_id = {$case} AND NEW.order_item_id = {$secondItem}"],
            'reserve-balance-first' => ['BEFORE', 'UPDATE', 'warehouse_inventories', "NEW.id = {$firstInventory} AND NEW.reserved_quantity > OLD.reserved_quantity"],
            'reserve-balance-second' => ['BEFORE', 'UPDATE', 'warehouse_inventories', "NEW.id = {$secondInventory} AND NEW.reserved_quantity > OLD.reserved_quantity"],
            'consume-inventory-first' => ['BEFORE', 'UPDATE', 'warehouse_inventories', "NEW.id = {$firstInventory} AND NEW.quantity < OLD.quantity"],
            'consume-inventory-second' => ['BEFORE', 'UPDATE', 'warehouse_inventories', "NEW.id = {$secondInventory} AND NEW.quantity < OLD.quantity"],
            'consume-reservation-first' => ['BEFORE', 'UPDATE', 'inventory_reservations', "NEW.resolution_case_id = {$case} AND NEW.order_item_id = {$firstItem} AND NEW.status = 'consumed'"],
            'consume-reservation-second' => ['BEFORE', 'UPDATE', 'inventory_reservations', "NEW.resolution_case_id = {$case} AND NEW.order_item_id = {$secondItem} AND NEW.status = 'consumed'"],
            'movement-first' => ['BEFORE', 'INSERT', 'inventory_movements', "NEW.idempotency_key = 'sale-order-item-{$firstItem}'"],
            'movement-second' => ['BEFORE', 'INSERT', 'inventory_movements', "NEW.idempotency_key = 'sale-order-item-{$secondItem}'"],
            'product-first' => ['BEFORE', 'UPDATE', 'products', "NEW.id = {$firstProduct}"],
            'product-second' => ['BEFORE', 'UPDATE', 'products', "NEW.id = {$secondProduct}"],
            'transaction' => ['BEFORE', 'UPDATE', 'payment_transactions', "NEW.id = {$transaction} AND NEW.status = 'approved'"],
            'submission' => ['BEFORE', 'UPDATE', 'payment_submissions', "NEW.id = {$submission} AND NEW.status = 'approved'"],
            'case' => ['BEFORE', 'UPDATE', 'payment_resolution_cases', "NEW.id = {$case} AND NEW.status = 'reassigned'"],
            'order' => ['BEFORE', 'UPDATE', 'orders', "NEW.id = {$order} AND NEW.payment_status = 'approved'"],
            'submission-history' => ['BEFORE', 'INSERT', 'payment_submission_histories', "NEW.payment_submission_id = {$submission} AND NEW.event = 'reassignment_approved'"],
            'resolution-history' => ['BEFORE', 'INSERT', 'payment_resolution_histories', "NEW.payment_resolution_case_id = {$case} AND NEW.event = 'reassigned'"],
            'after-resolution-history' => ['AFTER', 'INSERT', 'payment_resolution_histories', "NEW.payment_resolution_case_id = {$case} AND NEW.event = 'reassigned'"],
            'reopen-history' => ['BEFORE', 'INSERT', 'order_fulfillment_histories', "NEW.order_id = {$order} AND NEW.from_status = 'canceled' AND NEW.to_status = 'reserved'"],
            'after-reopen-history' => ['AFTER', 'INSERT', 'order_fulfillment_histories', "NEW.order_id = {$order} AND NEW.from_status = 'canceled' AND NEW.to_status = 'reserved'"],
            default => throw new RuntimeException("Unknown trigger point {$point}"),
        };

        return "CREATE TRIGGER {$trigger} {$timing} {$operation} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'atomicity failure {$point}'); END";
    }

    private function assertCompleteRollback(array $fixture, array $before): void
    {
        $this->assertSame($before, $this->snapshot($fixture));
        $this->assertSame(0, InventoryReservation::where('resolution_case_id', $fixture['case']->id)->count());
        $this->assertSame(0, InventoryMovement::where('reference_type', 'order')->where('reference_id', (string) $fixture['order']->id)->count());
        $this->assertSame(PaymentSubmission::MANUAL_RESOLUTION_REQUIRED, $fixture['submission']->fresh()->status);
        $this->assertSame(PaymentTransaction::RECEIVED, $fixture['transaction']->fresh()->status);
        $this->assertSame(PaymentResolutionCase::OPEN, $fixture['case']->fresh()->status);
        $this->assertSame('pending', $fixture['order']->fresh()->payment_status);
        $this->assertNull($fixture['order']->fresh()->paid_at);
    }

    private function snapshot(array $fixture): array
    {
        $orderId = $fixture['order']->id;
        $submissionId = $fixture['submission']->id;
        $caseId = $fixture['case']->id;
        $itemIds = collect($fixture['items'])->pluck('id')->all();
        $inventoryIds = collect($fixture['inventories'])->pluck('id')->all();
        $productIds = collect($fixture['products'])->pluck('id')->all();

        return [
            'submission' => $this->rows('payment_submissions', fn ($query) => $query->where('id', $submissionId)),
            'submission_histories' => $this->rows('payment_submission_histories', fn ($query) => $query->where('payment_submission_id', $submissionId)),
            'transactions' => $this->rows('payment_transactions', fn ($query) => $query->where('order_id', $orderId)->orWhere('payment_submission_id', $submissionId)),
            'case' => $this->rows('payment_resolution_cases', fn ($query) => $query->where('id', $caseId)),
            'resolution_histories' => $this->rows('payment_resolution_histories', fn ($query) => $query->where('payment_resolution_case_id', $caseId)),
            'refunds' => $this->rows('payment_refunds', fn ($query) => $query->where('payment_resolution_case_id', $caseId)),
            'order' => $this->rows('orders', fn ($query) => $query->where('id', $orderId)),
            'items' => $this->rows('order_items', fn ($query) => $query->where('order_id', $orderId)),
            'handling_processes' => $this->rows('order_handling_processes', fn ($query) => $query->where('order_id', $orderId)),
            'handling_items' => $this->rows('order_handling_items', fn ($query) => $query->whereIn('order_item_id', $itemIds)),
            'handling_incidents' => $this->rows('order_handling_incidents', fn ($query) => $query->where('order_id', $orderId)),
            'handling_histories' => $this->rows('order_handling_histories', fn ($query) => $query->where('order_id', $orderId)),
            'reservations' => $this->rows('inventory_reservations', fn ($query) => $query->where('order_id', $orderId)),
            'inventories' => $this->rows('warehouse_inventories', fn ($query) => $query->whereIn('id', $inventoryIds)),
            'products' => $this->rows('products', fn ($query) => $query->whereIn('id', $productIds)),
            'movements' => $this->rows('inventory_movements', fn ($query) => $query->where(fn ($nested) => $nested->where('reference_type', 'order')->where('reference_id', (string) $orderId))->orWhereIn('product_id', $productIds)),
            'fulfillment_histories' => $this->rows('order_fulfillment_histories', fn ($query) => $query->where('order_id', $orderId)),
        ];
    }

    private function rows(string $table, callable $scope): array
    {
        $query = DB::table($table)->orderBy('id');
        $scope($query);

        return $query->get()->map(fn ($row) => collect((array) $row)->map(function ($value, string $key) {
            if ($value !== null && ($key === 'reserved_until' || str_ends_with($key, '_at'))) {
                return now()->parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
            }

            return $value;
        })->all())->all();
    }

    private function queryException(string $sqlState, int $driverCode, string $message): QueryException
    {
        $previous = new PDOException($message, $driverCode);
        $previous->errorInfo = [$sqlState, $driverCode, $message];

        return new QueryException('statement', [], $previous);
    }
}
