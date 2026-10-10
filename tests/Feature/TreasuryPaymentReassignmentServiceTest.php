<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientVersionedInventoryStockException;
use App\Exceptions\InventoryReservationConflictException;
use App\Exceptions\PaymentReassignmentConflictException;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderFulfillmentHistory;
use App\Models\OrderHandlingProcess;
use App\Models\OrderItem;
use App\Models\PaymentRefund;
use App\Models\PaymentResolutionCase;
use App\Models\PaymentResolutionHistory;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\InventoryReservationExpirationService;
use App\Services\InventoryService;
use App\Services\TreasuryPaymentReassignmentService;
use App\Services\TreasuryPaymentResolutionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PDO;
use PDOException;
use Tests\TestCase;

class TreasuryPaymentReassignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_safe_metadata_normalization_ignores_object_key_order_but_preserves_types_and_list_order(): void
    {
        $first = [
            'nested' => ['second' => 2, 'first' => 1],
            'list' => [['beta' => true, 'alpha' => false], 'tail'],
        ];
        $sameObjectContent = [
            'list' => [['alpha' => false, 'beta' => true], 'tail'],
            'nested' => ['first' => 1, 'second' => 2],
        ];

        $this->assertSame(
            $this->normalizeJsonValue($first),
            $this->normalizeJsonValue($sameObjectContent)
        );
        $this->assertNotSame(
            $this->normalizeJsonValue($first),
            $this->normalizeJsonValue(array_replace_recursive($sameObjectContent, ['nested' => ['second' => '2']]))
        );
        $this->assertNotSame(
            $this->normalizeJsonValue(['list' => [1, 2]]),
            $this->normalizeJsonValue(['list' => [2, 1]])
        );
    }

    public function test_reassigns_and_approves_one_item_atomically_without_changing_financial_identity(): void
    {
        $fixture = $this->fixture();
        $transactionBefore = $fixture['transaction']->getAttributes();
        $original = $fixture['originals'][0]->fresh()->getAttributes();

        $case = app(TreasuryPaymentReassignmentService::class)->reassignAndApprove($fixture['submission'], $fixture['treasury']);

        $transaction = $fixture['transaction']->fresh();
        $submission = $fixture['submission']->fresh();
        $order = $fixture['order']->fresh();
        $caseReservation = InventoryReservation::where('resolution_case_id', $case->id)->firstOrFail();
        $this->assertSame(PaymentTransaction::APPROVED, $transaction->status);
        $this->assertSame(PaymentTransaction::approvedScopeKeyForOrder($order->id), $transaction->approved_scope_key);
        foreach (['id', 'payment_submission_id', 'order_id', 'amount', 'currency', 'payment_method', 'transaction_type', 'collection_method', 'confirmed_by', 'confirmed_at', 'idempotency_key'] as $attribute) {
            $this->assertSame((string) $transactionBefore[$attribute], (string) $transaction->getRawOriginal($attribute));
        }
        $this->assertSame(1, PaymentTransaction::where('payment_submission_id', $submission->id)->count());
        $this->assertSame(PaymentSubmission::APPROVED, $submission->status);
        $this->assertSame(PaymentResolutionCase::REASSIGNED, $case->status);
        $this->assertSame(PaymentResolutionCase::STOCK_REASSIGNMENT, $case->type);
        $this->assertSame('approved', $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame('pending', $order->status);
        $this->assertSame($original, $fixture['originals'][0]->fresh()->getAttributes());
        $this->assertSame(InventoryReservation::CONSUMED, $caseReservation->status);
        $this->assertSame(1, InventoryMovement::where('idempotency_key', 'sale-order-item-'.$fixture['items'][0]->id)->count());
        $this->assertSafeUniqueHistories($fixture, $case, false, 1);
    }

    public function test_reassigns_two_items_in_their_original_warehouses_and_creates_one_sale_each(): void
    {
        $fixture = $this->fixture(2);

        $case = app(TreasuryPaymentReassignmentService::class)->reassignAndApprove($fixture['submission'], $fixture['treasury']);

        $reservations = InventoryReservation::where('resolution_case_id', $case->id)->orderBy('order_item_id')->get();
        $this->assertCount(2, $reservations);
        foreach ($fixture['items'] as $index => $item) {
            $reservation = $reservations->firstWhere('order_item_id', $item->id);
            $this->assertSame($item->warehouse_id, $reservation->warehouse_id);
            $this->assertSame(InventoryReservation::CONSUMED, $reservation->status);
            $this->assertSame(8, $fixture['inventories'][$index]->fresh()->quantity);
            $this->assertSame(0, $fixture['inventories'][$index]->fresh()->reserved_quantity);
            $this->assertSame(8, $fixture['products'][$index]->fresh()->stock);
            $this->assertSame(1, InventoryMovement::where('idempotency_key', 'sale-order-item-'.$item->id)->count());
        }
        $this->assertSame(1, PaymentTransaction::where('payment_submission_id', $fixture['submission']->id)->count());
        $this->assertSafeUniqueHistories($fixture, $case, false, 2);
    }

    public function test_automatically_expired_canceled_order_is_reopened_only_after_success(): void
    {
        $fixture = $this->fixture(1, InventoryReservation::ACTIVE, true);
        $this->assertSame('canceled', $fixture['order']->fresh()->status);

        $case = app(TreasuryPaymentReassignmentService::class)->reassignAndApprove($fixture['submission'], $fixture['treasury']);

        $order = $fixture['order']->fresh();
        $this->assertSame('pending', $order->status);
        $this->assertSame('pending', $order->tracking_status);
        $this->assertSame(Order::FULFILLMENT_RESERVED, $order->fulfillment_status);
        $this->assertSame('approved', $order->payment_status);
        $this->assertSame(1, OrderFulfillmentHistory::where('idempotency_key', 'resolution-reopened-order-'.$order->id.'-case-'.$case->id)->count());
        $this->assertSame(1, OrderFulfillmentHistory::where('idempotency_key', 'reservation-expired-order-'.$order->id)->count());
        $this->assertSafeUniqueHistories($fixture, $case, true, 1);
    }

    public function test_manual_cancellation_is_rejected_without_mutations(): void
    {
        $fixture = $this->fixture(1, InventoryReservation::RELEASED);
        $fixture['order']->update([
            'status' => 'canceled',
            'tracking_status' => 'canceled',
            'fulfillment_status' => Order::FULFILLMENT_CANCELED,
        ]);
        $before = $this->snapshot($fixture);

        try {
            app(TreasuryPaymentReassignmentService::class)->reassignAndApprove($fixture['submission'], $fixture['treasury']);
            $this->fail('La reasignación aceptó una cancelación manual.');
        } catch (PaymentReassignmentConflictException) {
            $this->assertSame($before, $this->snapshot($fixture));
        }
    }

    public function test_insufficient_stock_rolls_back_every_layer_and_is_distinguishable(): void
    {
        $fixture = $this->fixture();
        $fixture['inventories'][0]->update(['quantity' => 1, 'reserved_quantity' => 0]);
        $fixture['products'][0]->forceFill(['stock' => 1])->save();
        $before = $this->snapshot($fixture);

        try {
            app(TreasuryPaymentReassignmentService::class)->reassignAndApprove($fixture['submission'], $fixture['treasury']);
            $this->fail('La reasignación aceptó stock insuficiente.');
        } catch (InsufficientVersionedInventoryStockException) {
            $this->assertSame($before, $this->snapshot($fixture));
        }
    }

    public function test_committed_winner_leaves_the_sequential_last_stock_loser_as_insufficient_stock_without_writes(): void
    {
        $treasury = User::factory()->create(['role' => 'treasury', 'is_active' => true]);
        $branch = Branch::create([
            'code' => 'BR-LAST-'.Str::random(8),
            'name' => 'Sede último stock',
            'address' => 'Dirección',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'code' => 'WH-LAST-'.Str::random(8),
            'name' => 'Almacén último stock',
            'is_active' => true,
        ]);
        $product = Product::create([
            'name' => 'Producto último stock '.Str::random(8),
            'slug' => 'producto-ultimo-stock-'.Str::uuid(),
            'sku' => 'LAST-'.Str::upper(Str::random(8)),
            'price' => '20.00',
            'is_active' => true,
        ]);
        $product->forceFill(['stock' => 1])->save();
        $inventory = WarehouseInventory::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'reserved_quantity' => 0,
        ]);
        $shared = compact('warehouse', 'product', 'inventory');
        $winner = $this->sharedLastStockFixture($shared, $treasury, 'winner');
        $loser = $this->sharedLastStockFixture($shared, $treasury, 'loser');

        app(TreasuryPaymentReassignmentService::class)
            ->reassignAndApprove($winner['submission'], $treasury);
        $beforeLoser = $this->snapshot($loser);

        try {
            app(TreasuryPaymentReassignmentService::class)
                ->reassignAndApprove($loser['submission'], $treasury);
            $this->fail('El segundo pedido aceptó reasignar stock ya consumido por el ganador.');
        } catch (InsufficientVersionedInventoryStockException $exception) {
            $this->assertSame(InventoryReservationConflictException::class, get_parent_class($exception));
            $this->assertSame($beforeLoser, $this->snapshot($loser));
        }
    }

    public function test_domain_failure_is_not_retried_or_reclassified_by_the_outer_transaction(): void
    {
        $fixture = $this->fixture();
        $before = $this->snapshot($fixture);
        $inventory = Mockery::mock(app(InventoryService::class))->makePartial();
        $inventory->shouldReceive('reserveVersionedForResolutionCaseWithLockedEvidence')
            ->once()
            ->andThrow(new InsufficientVersionedInventoryStockException('expected domain failure'));

        try {
            (new TreasuryPaymentReassignmentService($inventory))
                ->reassignAndApprove($fixture['submission'], $fixture['treasury']);
            $this->fail('The domain failure was not propagated.');
        } catch (InsufficientVersionedInventoryStockException $exception) {
            $this->assertSame('expected domain failure', $exception->getMessage());
        }

        $this->assertSame($before, $this->snapshot($fixture));
    }

    public function test_structural_inventory_conflict_is_translated_with_its_domain_cause_and_without_writes(): void
    {
        $fixture = $this->fixture();
        $before = $this->snapshot($fixture);
        $structural = new InventoryReservationConflictException('structural inventory inconsistency');
        $inventory = Mockery::mock(app(InventoryService::class))->makePartial();
        $inventory->shouldReceive('reserveVersionedForResolutionCaseWithLockedEvidence')
            ->once()
            ->andThrow($structural);

        try {
            (new TreasuryPaymentReassignmentService($inventory))
                ->reassignAndApprove($fixture['submission'], $fixture['treasury']);
            $this->fail('The structural inventory conflict was not translated.');
        } catch (PaymentReassignmentConflictException $exception) {
            $this->assertSame($structural, $exception->getPrevious());
        }

        $this->assertSame($before, $this->snapshot($fixture));
    }

    public function test_unrelated_query_exception_is_not_retried_or_reclassified_by_the_outer_transaction(): void
    {
        $fixture = $this->fixture();
        $before = $this->snapshot($fixture);
        $previous = new PDOException('unrelated database failure', 9999);
        $previous->errorInfo = ['HY000', 9999, 'unrelated database failure'];
        $failure = new QueryException('test statement', [], $previous);
        $inventory = Mockery::mock(app(InventoryService::class))->makePartial();
        $inventory->shouldReceive('reserveVersionedForResolutionCaseWithLockedEvidence')
            ->once()
            ->andThrow($failure);

        try {
            (new TreasuryPaymentReassignmentService($inventory))
                ->reassignAndApprove($fixture['submission'], $fixture['treasury']);
            $this->fail('The unrelated QueryException was not propagated.');
        } catch (QueryException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame($before, $this->snapshot($fixture));
    }

    public function test_coherent_retry_returns_the_same_case_without_any_write(): void
    {
        $fixture = $this->fixture();
        $first = app(TreasuryPaymentReassignmentService::class)->reassignAndApprove($fixture['submission'], $fixture['treasury']);
        $before = $this->snapshot($fixture);

        $second = app(TreasuryPaymentReassignmentService::class)->reassignAndApprove($fixture['submission']->fresh(), $fixture['treasury']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($before, $this->snapshot($fixture));
    }

    public function test_partial_final_state_is_a_conflict_without_repair(): void
    {
        $fixture = $this->fixture();
        $fixture['submission']->update(['status' => PaymentSubmission::APPROVED]);
        $before = $this->snapshot($fixture);

        try {
            app(TreasuryPaymentReassignmentService::class)->reassignAndApprove($fixture['submission'], $fixture['treasury']);
            $this->fail('La reasignación reparó un estado final parcial.');
        } catch (PaymentReassignmentConflictException) {
            $this->assertSame($before, $this->snapshot($fixture));
        }
    }

    public function test_failure_after_inventory_consumption_rolls_back_inventory_and_financial_transitions(): void
    {
        $trigger = 'reassign_tx_fail_'.bin2hex(random_bytes(4));
        $driver = DB::connection()->getDriverName();
        $ddlPdo = $driver === 'mysql' ? $this->mysqlTriggerPdo() : null;
        $triggerCreated = false;
        $mysqlTransactionLevel = null;

        try {
            if ($driver === 'mysql') {
                // Create DDL before this test touches payment_transactions. The
                // dedicated connection must never commit the PHPUnit transaction.
                $this->createTransactionApprovalFailureTrigger($trigger, null, $ddlPdo);
                $triggerCreated = true;
            }

            $fixture = $this->fixture(2);
            $before = $this->snapshot($fixture);
            if ($driver === 'sqlite') {
                $this->createTransactionApprovalFailureTrigger($trigger, (int) $fixture['transaction']->id, null);
                $triggerCreated = true;
            }

            try {
                app(TreasuryPaymentReassignmentService::class)->reassignAndApprove($fixture['submission'], $fixture['treasury']);
                $this->fail('El fallo posterior al consumo no interrumpió la transacción exterior.');
            } catch (QueryException) {
                $this->assertSame($before, $this->snapshot($fixture));
            }
        } finally {
            if ($triggerCreated && $driver === 'mysql') {
                // MySQL retains the table metadata lock until the surrounding
                // PHPUnit transaction ends. Roll back only this test fixture,
                // drop through the dedicated PDO, then restore the transaction
                // expected by RefreshDatabase's teardown callback.
                $mysqlTransactionLevel = DB::transactionLevel();
                if ($mysqlTransactionLevel > 0) {
                    DB::rollBack();
                }
                try {
                    $this->dropFailureTrigger($trigger, $ddlPdo);
                } finally {
                    if ($mysqlTransactionLevel > 0) {
                        DB::beginTransaction();
                    }
                }
            } elseif ($triggerCreated) {
                $this->dropFailureTrigger($trigger, null);
            }
        }

        if ($driver === 'mysql') {
            $this->assertSame(1, $mysqlTransactionLevel);
        }
        $this->assertFalse($this->failureTriggerExists($trigger, $ddlPdo));
        $ddlPdo = null;
    }

    /** @dataProvider initialSubmissionAnomalies */
    public function test_each_initial_submission_anomaly_is_a_conflict_without_repair(string $field, mixed $value): void
    {
        $fixture = $this->fixture();
        DB::table('payment_submissions')->where('id', $fixture['submission']->id)->update([$field => $this->resolveFixtureValue($fixture, $value)]);

        $this->assertReassignmentConflictWithoutMutation($fixture);
    }

    public static function initialSubmissionAnomalies(): array
    {
        return [
            'pending review' => ['status', PaymentSubmission::PENDING_REVIEW],
            'observed' => ['status', PaymentSubmission::OBSERVED],
            'rejected' => ['status', PaymentSubmission::REJECTED],
            'approved without final state' => ['status', PaymentSubmission::APPROVED],
            'expired' => ['status', PaymentSubmission::EXPIRED],
            'canceled' => ['status', PaymentSubmission::CANCELED],
            'different order' => ['order_id', '__second_order__'],
            'unsupported channel' => ['channel', 'cash'],
            'different expected amount' => ['expected_amount', '999.00'],
            'different currency' => ['currency', 'USD'],
            'missing reviewed timestamp' => ['reviewed_at', null],
            'incompatible reviewer' => ['reviewed_by', '__other_treasury__'],
            'incompatible B1 reason' => ['decision_reason', 'incompatible_reason'],
        ];
    }

    /** @dataProvider initialTransactionAnomalies */
    public function test_each_initial_transaction_anomaly_is_a_conflict_without_repair(string $field, mixed $value): void
    {
        $fixture = $this->fixture();
        DB::table('payment_transactions')->where('id', $fixture['transaction']->id)->update([$field => $this->resolveFixtureValue($fixture, $value)]);

        $this->assertReassignmentConflictWithoutMutation($fixture);
    }

    public static function initialTransactionAnomalies(): array
    {
        return [
            'different order' => ['order_id', '__second_order__'],
            'different submission' => ['payment_submission_id', '__second_submission__'],
            'different amount' => ['amount', '999.00'],
            'different currency' => ['currency', 'USD'],
            'different payment method' => ['payment_method', 'contraentrega'],
            'different transaction type' => ['transaction_type', PaymentTransaction::REFUND],
            'pending status' => ['status', PaymentTransaction::PENDING],
            'approved incomplete' => ['status', PaymentTransaction::APPROVED],
            'failed status' => ['status', PaymentTransaction::FAILED],
            'canceled status' => ['status', PaymentTransaction::CANCELED],
            'different idempotency key' => ['idempotency_key', '__unique_key__'],
            'unexpected approved scope' => ['approved_scope_key', '__unique_scope__'],
            'missing confirmation timestamp' => ['confirmed_at', null],
            'incompatible confirmer' => ['confirmed_by', '__other_treasury__'],
        ];
    }

    /** @dataProvider initialCaseAnomalies */
    public function test_each_initial_resolution_case_anomaly_is_a_conflict_without_repair(string $field, mixed $value): void
    {
        $fixture = $this->fixture();
        DB::table('payment_resolution_cases')->where('id', $fixture['case']->id)->update([$field => $this->resolveFixtureValue($fixture, $value)]);

        $this->assertReassignmentConflictWithoutMutation($fixture);
    }

    public static function initialCaseAnomalies(): array
    {
        return [
            'different submission' => ['payment_submission_id', '__second_submission__'],
            'missing received transaction' => ['received_payment_transaction_id', null],
            'different received transaction' => ['received_payment_transaction_id', '__second_transaction__'],
            'already reassigned' => ['status', PaymentResolutionCase::REASSIGNED],
            'refund pending' => ['status', PaymentResolutionCase::REFUND_PENDING],
            'refunded' => ['status', PaymentResolutionCase::REFUNDED],
            'refund failed' => ['status', PaymentResolutionCase::REFUND_FAILED],
            'stock type already selected' => ['type', PaymentResolutionCase::STOCK_REASSIGNMENT],
            'refund type already selected' => ['type', PaymentResolutionCase::REFUND],
            'different amount' => ['amount', '999.00'],
            'different currency' => ['currency', 'USD'],
            'different idempotency key' => ['idempotency_key', '__unique_key__'],
            'missing funds timestamp' => ['funds_received_at', null],
        ];
    }

    /** @dataProvider initialOrderAnomalies */
    public function test_each_initial_order_or_operation_anomaly_is_a_conflict_without_repair(string $kind): void
    {
        $fixture = $this->fixture($kind === 'mixed_cancellation' ? 2 : 1);
        $this->applyOrderAnomaly($fixture, $kind);

        $this->assertReassignmentConflictWithoutMutation($fixture);
    }

    public static function initialOrderAnomalies(): array
    {
        return [
            'different payment method' => ['payment_method'],
            'approved payment' => ['payment_approved'],
            'rejected payment' => ['payment_rejected'],
            'refund pending payment' => ['payment_refund_pending'],
            'refunded payment' => ['payment_refunded'],
            'premature paid timestamp' => ['paid_at'],
            'different total' => ['total'],
            'rejected order' => ['status_rejected'],
            'delivered order' => ['status_delivered'],
            'picked up order' => ['status_picked_up'],
            'manual cancellation' => ['manual_cancellation'],
            'cancellation without expiration history' => ['missing_expiration_history'],
            'cancellation with mixed reservation provenance' => ['mixed_cancellation'],
            'preparation started' => ['preparing'],
            'delivery started' => ['delivery'],
            'picking started' => ['picking'],
            'packing started' => ['packing'],
            'handling canceled' => ['handling_canceled'],
            'order item removed' => ['missing_item'],
            'order item altered' => ['altered_item'],
        ];
    }

    /** @dataProvider openingEvidenceAnomalies */
    public function test_each_b1_opening_evidence_anomaly_is_a_conflict_without_repair(string $kind): void
    {
        $fixture = $this->fixture();
        $this->applyOpeningEvidenceAnomaly($fixture, $kind);

        $this->assertReassignmentConflictWithoutMutation($fixture);
    }

    public static function openingEvidenceAnomalies(): array
    {
        return [
            'missing submission history' => ['submission_missing'],
            'duplicate submission history' => ['submission_duplicate'],
            'submission transition' => ['submission_transition'],
            'submission actor' => ['submission_actor'],
            'submission timestamp' => ['submission_timestamp'],
            'submission reason' => ['submission_reason'],
            'submission metadata' => ['submission_metadata'],
            'missing resolution history' => ['resolution_missing'],
            'duplicate resolution history' => ['resolution_duplicate'],
            'resolution transition' => ['resolution_transition'],
            'resolution actor' => ['resolution_actor'],
            'resolution timestamp' => ['resolution_timestamp'],
            'resolution reason' => ['resolution_reason'],
            'resolution metadata' => ['resolution_metadata'],
            'transaction source metadata' => ['transaction_metadata'],
            'case reason' => ['case_reason'],
        ];
    }

    public function test_missing_transaction_and_case_are_independent_conflicts_without_repair(): void
    {
        foreach (['transaction', 'case'] as $missing) {
            $fixture = $this->fixture();
            if ($missing === 'transaction') {
                DB::table('payment_resolution_cases')->where('id', $fixture['case']->id)->update(['received_payment_transaction_id' => null]);
                DB::table('payment_transactions')->where('id', $fixture['transaction']->id)->delete();
            } else {
                DB::table('payment_resolution_histories')->where('payment_resolution_case_id', $fixture['case']->id)->delete();
                DB::table('payment_resolution_cases')->where('id', $fixture['case']->id)->delete();
            }
            $this->assertReassignmentConflictWithoutMutation($fixture);
        }
    }

    public function test_scope_occupied_approved_transaction_and_refund_are_conflicts_without_repair(): void
    {
        foreach (['scope', 'approved', 'refund'] as $kind) {
            $fixture = $this->fixture();
            if ($kind === 'refund') {
                PaymentRefund::forceCreate([
                    'payment_resolution_case_id' => $fixture['case']->id,
                    'refund_payment_transaction_id' => null,
                    'status' => 'pending',
                    'amount' => $fixture['order']->total,
                    'currency' => 'PEN',
                    'reason' => 'fixture',
                    'requested_at' => now(),
                    'idempotency_key' => 'reassignment-refund-'.Str::uuid(),
                ]);
            } else {
                PaymentTransaction::create([
                    'order_id' => $fixture['order']->id,
                    'payment_method' => 'tarjeta',
                    'transaction_type' => PaymentTransaction::PAYMENT,
                    'status' => $kind === 'approved' ? PaymentTransaction::APPROVED : PaymentTransaction::PENDING,
                    'amount' => $fixture['order']->total,
                    'currency' => 'PEN',
                    'idempotency_key' => 'reassignment-collision-'.Str::uuid(),
                    'approved_scope_key' => $kind === 'scope' ? PaymentTransaction::approvedScopeKeyForOrder($fixture['order']->id) : 'historic-approved-'.Str::uuid(),
                    'confirmed_at' => now(),
                ]);
            }
            $this->assertReassignmentConflictWithoutMutation($fixture);
        }
    }

    public function test_case_requested_at_and_duplicate_case_are_enforced_by_the_database(): void
    {
        $fixture = $this->fixture();
        foreach (['requested_at', 'duplicate_case'] as $kind) {
            $before = $this->snapshot($fixture);
            try {
                if ($kind === 'requested_at') {
                    DB::table('payment_resolution_cases')->where('id', $fixture['case']->id)->update(['requested_at' => null]);
                } else {
                    DB::table('payment_resolution_cases')->insert([
                        'payment_submission_id' => $fixture['submission']->id,
                        'received_payment_transaction_id' => null,
                        'status' => PaymentResolutionCase::OPEN,
                        'amount' => $fixture['order']->total,
                        'currency' => 'PEN',
                        'requested_at' => now(),
                        'idempotency_key' => 'duplicate-case-'.Str::uuid(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $this->fail('La base aceptó una restricción estructural inválida.');
            } catch (QueryException $exception) {
                if ($kind === 'requested_at') {
                    $this->assertNotNullConstraintViolation($exception, 'requested_at');
                } else {
                    $this->assertUniqueConstraintViolation(
                        $exception,
                        'payment_resolution_cases.payment_submission_id',
                        'prc_submission_unique'
                    );
                }
                $this->assertSame($before, $this->snapshot($fixture));
            }
        }
    }

    public function test_case_reservation_linked_to_another_order_is_a_conflict_without_repair(): void
    {
        $fixture = $this->fixture();
        InventoryReservation::forceCreate([
            'order_id' => $this->secondaryOrder($fixture)->id,
            'order_item_id' => $fixture['items'][0]->id,
            'reservation_sequence' => 2,
            'resolution_case_id' => $fixture['case']->id,
            'product_id' => $fixture['products'][0]->id,
            'warehouse_id' => $fixture['inventories'][0]->warehouse_id,
            'quantity' => $fixture['items'][0]->quantity,
            'status' => InventoryReservation::ACTIVE,
            'expires_at' => now()->addHour(),
            'idempotency_key' => 'foreign-case-reservation-'.Str::uuid(),
            'metadata' => [],
        ]);

        $this->assertReassignmentConflictWithoutMutation($fixture);
    }

    /** @dataProvider finalStateAnomalies */
    public function test_each_partial_or_corrupt_final_state_is_a_conflict_without_repair(string $kind): void
    {
        $fixture = $this->fixture();
        app(TreasuryPaymentReassignmentService::class)->reassignAndApprove($fixture['submission'], $fixture['treasury']);

        if ($kind === 'negative_stock' && DB::connection()->getDriverName() === 'mysql') {
            $before = $this->snapshot($fixture);
            try {
                $this->applyFinalStateAnomaly($fixture, $kind);
            } catch (QueryException $exception) {
                $this->assertNegativeValueRejected($exception, 'quantity');
                $this->assertSame($before, $this->snapshot($fixture));
                $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS connection_usable')->connection_usable);

                return;
            }
        } else {
            $this->applyFinalStateAnomaly($fixture, $kind);
        }

        $this->assertReassignmentConflictWithoutMutation($fixture);
    }

    public static function finalStateAnomalies(): array
    {
        return [
            'transaction status' => ['transaction_status'],
            'transaction approved scope' => ['transaction_scope'],
            'transaction amount' => ['transaction_amount'],
            'transaction currency' => ['transaction_currency'],
            'transaction order' => ['transaction_order'],
            'transaction confirmed timestamp' => ['transaction_confirmed_at'],
            'submission status' => ['submission_status'],
            'submission reviewed timestamp' => ['submission_reviewed_at'],
            'submission amount' => ['submission_amount'],
            'submission currency' => ['submission_currency'],
            'case status' => ['case_status'],
            'case type' => ['case_type'],
            'case resolved timestamp' => ['case_resolved_at'],
            'case transaction link' => ['case_transaction'],
            'order payment status' => ['order_payment_status'],
            'order paid timestamp' => ['order_paid_at'],
            'order status' => ['order_status'],
            'active case reservation' => ['reservation_active'],
            'released case reservation' => ['reservation_released'],
            'expired case reservation' => ['reservation_expired'],
            'missing consumed timestamp' => ['reservation_consumed_at'],
            'missing sale movement' => ['movement_missing'],
            'extra sale movement' => ['movement_duplicate'],
            'incompatible sale movement' => ['movement_incompatible'],
            'desynchronized product stock' => ['product_stock'],
            'negative warehouse stock' => ['negative_stock'],
            'missing submission final history' => ['submission_history_missing'],
            'duplicate submission final history' => ['submission_history_duplicate'],
            'submission history transition' => ['submission_history_transition'],
            'submission history actor' => ['submission_history_actor'],
            'submission history metadata' => ['submission_history_metadata'],
            'missing resolution final history' => ['resolution_history_missing'],
            'duplicate resolution final history' => ['resolution_history_duplicate'],
        ];
    }

    public function test_reopening_history_is_required_exactly_once_for_an_idempotent_retry(): void
    {
        foreach (['missing', 'duplicate'] as $kind) {
            $fixture = $this->fixture(1, InventoryReservation::EXPIRED, true);
            $case = app(TreasuryPaymentReassignmentService::class)->reassignAndApprove($fixture['submission'], $fixture['treasury']);
            $key = 'resolution-reopened-order-'.$fixture['order']->id.'-case-'.$case->id;
            $history = OrderFulfillmentHistory::where('idempotency_key', $key)->firstOrFail();
            if ($kind === 'missing') {
                DB::table('order_fulfillment_histories')->where('id', $history->id)->delete();
            } else {
                DB::table('order_fulfillment_histories')->insert(array_merge($history->getAttributes(), [
                    'id' => null,
                    'idempotency_key' => 'duplicate-reopen-'.Str::uuid(),
                ]));
            }
            $this->assertReassignmentConflictWithoutMutation($fixture);
        }
    }

    /** @dataProvider invalidActors */
    public function test_only_an_active_treasury_actor_can_reassign(string $role, bool $active): void
    {
        $fixture = $this->fixture();
        $actor = User::factory()->create(['role' => $role, 'is_active' => $active]);
        $before = $this->snapshot($fixture);

        try {
            app(TreasuryPaymentReassignmentService::class)->reassignAndApprove($fixture['submission'], $actor);
            $this->fail('Un actor no autorizado pudo reasignar y aprobar.');
        } catch (PaymentReassignmentConflictException) {
            $this->assertSame($before, $this->snapshot($fixture));
        }
    }

    public static function invalidActors(): array
    {
        return [
            'customer' => ['customer', true],
            'admin' => ['admin', true],
            'inactive treasury' => ['treasury', false],
        ];
    }

    public function test_deleted_historic_actor_is_not_recreated_on_a_coherent_retry(): void
    {
        $fixture = $this->fixture();
        $case = app(TreasuryPaymentReassignmentService::class)->reassignAndApprove($fixture['submission'], $fixture['treasury']);
        $replacement = User::factory()->create(['role' => 'treasury', 'is_active' => true]);
        $fixture['treasury']->delete();
        $before = $this->snapshot($fixture);

        $retry = app(TreasuryPaymentReassignmentService::class)->reassignAndApprove($fixture['submission']->fresh(), $replacement);

        $this->assertSame($case->id, $retry->id);
        $this->assertNull($fixture['transaction']->fresh()->confirmed_by);
        $this->assertNull($fixture['case']->fresh()->requested_by);
        $this->assertNull($fixture['case']->fresh()->resolved_by);
        $this->assertNull($fixture['submission']->fresh()->reviewed_by);
        $this->assertSame($before, $this->snapshot($fixture));
    }

    private function assertReassignmentConflictWithoutMutation(array $fixture): void
    {
        $before = $this->snapshot($fixture);

        try {
            app(TreasuryPaymentReassignmentService::class)->reassignAndApprove($fixture['submission']->fresh(), $fixture['treasury']);
            $this->fail('La reasignación aceptó un estado financiero u operativo incompatible.');
        } catch (PaymentReassignmentConflictException $exception) {
            $message = $exception->getMessage();
            foreach (['select ', 'insert ', 'update ', 'delete ', 'idempotency', 'operation_number', 'receiving_account_snapshot', 'metadata', 'App\\Models'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $message);
            }
            $this->assertSame($before, $this->snapshot($fixture));
        }
    }

    private function resolveFixtureValue(array $fixture, mixed $value): mixed
    {
        if ($value === '__other_treasury__') {
            return User::factory()->create(['role' => 'treasury', 'is_active' => true])->id;
        }
        if ($value === '__unique_key__') {
            return 'reassignment-anomaly-'.Str::uuid();
        }
        if ($value === '__unique_scope__') {
            return 'reassignment-scope-'.Str::uuid();
        }
        if ($value === '__second_order__') {
            return $this->secondaryOrder($fixture)->id;
        }
        if ($value === '__second_submission__') {
            return $this->secondarySubmission($fixture)->id;
        }
        if ($value === '__second_transaction__') {
            return $this->secondaryTransaction($fixture)->id;
        }

        return $value;
    }

    private function secondaryOrder(array $fixture): Order
    {
        return Order::create([
            'user_id' => $fixture['customer']->id,
            'status' => 'pending',
            'fulfillment_status' => Order::FULFILLMENT_RESERVED,
            'total' => $fixture['order']->total,
            'shipping_info' => ['address' => 'Secundario'],
            'payment_method' => 'transferencia',
            'payment_status' => 'pending',
            'reserved_until' => now()->subMinute(),
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
        ]);
    }

    private function secondarySubmission(array $fixture): PaymentSubmission
    {
        $order = $this->secondaryOrder($fixture);

        return PaymentSubmission::create([
            'order_id' => $order->id,
            'channel' => 'yape',
            'status' => PaymentSubmission::PENDING_REVIEW,
            'expected_amount' => $order->total,
            'currency' => 'PEN',
            'operation_number' => 'SECONDARY',
            'normalized_operation_number' => Str::upper(Str::random(12)),
            'duplicate_fingerprint' => hash('sha256', Str::uuid()->toString()),
            'declared_paid_at' => now(),
            'receiving_account_snapshot' => [],
            'submitted_by' => $fixture['customer']->id,
            'submitted_at' => now(),
            'idempotency_key' => 'reassignment-secondary-'.Str::uuid(),
        ]);
    }

    private function secondaryTransaction(array $fixture): PaymentTransaction
    {
        $order = $this->secondaryOrder($fixture);

        return PaymentTransaction::create([
            'order_id' => $order->id,
            'payment_method' => 'transferencia',
            'transaction_type' => PaymentTransaction::PAYMENT,
            'status' => PaymentTransaction::RECEIVED,
            'amount' => $order->total,
            'currency' => 'PEN',
            'idempotency_key' => 'reassignment-secondary-transaction-'.Str::uuid(),
            'collection_method' => 'yape',
            'confirmed_by' => $fixture['treasury']->id,
            'confirmed_at' => now(),
        ]);
    }

    private function applyOrderAnomaly(array $fixture, string $kind): void
    {
        $order = $fixture['order'];
        $attributes = match ($kind) {
            'payment_method' => ['payment_method' => 'contraentrega'],
            'payment_approved' => ['payment_status' => 'approved'],
            'payment_rejected' => ['payment_status' => 'rejected'],
            'payment_refund_pending' => ['payment_status' => 'refund_pending'],
            'payment_refunded' => ['payment_status' => 'refunded'],
            'paid_at' => ['paid_at' => now()],
            'total' => ['total' => '999.00'],
            'status_rejected' => ['status' => 'rejected'],
            'status_delivered' => ['status' => 'delivered'],
            'status_picked_up' => ['status' => 'picked_up'],
            'preparing' => ['preparing_at' => now()],
            'delivery' => ['delivered_at' => now()],
            default => [],
        };
        if ($attributes !== []) {
            DB::table('orders')->where('id', $order->id)->update($attributes);

            return;
        }

        if ($kind === 'manual_cancellation') {
            DB::table('inventory_reservations')->where('order_id', $order->id)->update([
                'status' => InventoryReservation::RELEASED,
                'metadata' => json_encode(['release_reason' => 'order_canceled']),
            ]);
            DB::table('orders')->where('id', $order->id)->update([
                'status' => 'canceled', 'tracking_status' => 'canceled', 'fulfillment_status' => Order::FULFILLMENT_CANCELED,
            ]);

            return;
        }
        if ($kind === 'missing_expiration_history') {
            DB::table('orders')->where('id', $order->id)->update([
                'status' => 'canceled', 'tracking_status' => 'canceled', 'fulfillment_status' => Order::FULFILLMENT_CANCELED,
            ]);

            return;
        }
        if ($kind === 'mixed_cancellation') {
            DB::table('inventory_reservations')->where('id', $fixture['originals'][1]->id)->update([
                'status' => InventoryReservation::RELEASED,
                'metadata' => json_encode(['release_reason' => 'order_canceled']),
            ]);
            DB::table('orders')->where('id', $order->id)->update([
                'status' => 'canceled', 'tracking_status' => 'canceled', 'fulfillment_status' => Order::FULFILLMENT_CANCELED,
            ]);
            OrderFulfillmentHistory::create([
                'order_id' => $order->id,
                'from_status' => Order::FULFILLMENT_RESERVED,
                'to_status' => Order::FULFILLMENT_CANCELED,
                'observation' => 'Expiración parcial incompatible.',
                'metadata' => ['reason' => 'reservation_expired'],
                'idempotency_key' => 'reservation-expired-order-'.$order->id,
                'created_at' => now(),
            ]);

            return;
        }
        if (in_array($kind, ['picking', 'packing', 'handling_canceled'], true)) {
            OrderHandlingProcess::create([
                'order_id' => $order->id,
                'picking_status' => $kind === 'picking' ? OrderHandlingProcess::IN_PROGRESS : OrderHandlingProcess::PENDING,
                'picking_started_at' => $kind === 'picking' ? now() : null,
                'packing_status' => $kind === 'packing' ? OrderHandlingProcess::IN_PROGRESS : OrderHandlingProcess::PENDING,
                'packing_started_at' => $kind === 'packing' ? now() : null,
                'canceled_at' => $kind === 'handling_canceled' ? now() : null,
            ]);

            return;
        }
        if ($kind === 'missing_item') {
            DB::table('order_items')->where('id', $fixture['items'][0]->id)->update(['order_id' => $this->secondaryOrder($fixture)->id]);

            return;
        }
        if ($kind === 'altered_item') {
            DB::table('order_items')->where('id', $fixture['items'][0]->id)->update(['quantity' => 3]);
        }
    }

    private function applyOpeningEvidenceAnomaly(array $fixture, string $kind): void
    {
        $submissionHistory = DB::table('payment_submission_histories')
            ->where('payment_submission_id', $fixture['submission']->id)
            ->where('event', 'resolution_opened');
        $resolutionHistory = DB::table('payment_resolution_histories')
            ->where('payment_resolution_case_id', $fixture['case']->id)
            ->where('event', 'opened');
        if ($kind === 'submission_missing') {
            $submissionHistory->delete();
        } elseif ($kind === 'submission_duplicate') {
            $this->duplicateTableRow('payment_submission_histories', (array) $submissionHistory->first());
        } elseif ($kind === 'submission_transition') {
            $submissionHistory->update(['to_status' => PaymentSubmission::OBSERVED]);
        } elseif ($kind === 'submission_actor') {
            $submissionHistory->update(['actor_id' => User::factory()->create(['role' => 'treasury'])->id]);
        } elseif ($kind === 'submission_timestamp') {
            $submissionHistory->update(['occurred_at' => now()->addMinute()]);
        } elseif ($kind === 'submission_reason') {
            $submissionHistory->update(['reason' => 'wrong_reason']);
        } elseif ($kind === 'submission_metadata') {
            $submissionHistory->update(['safe_metadata' => json_encode(['currency' => 'USD'])]);
        } elseif ($kind === 'resolution_missing') {
            $resolutionHistory->delete();
        } elseif ($kind === 'resolution_duplicate') {
            $this->duplicateTableRow('payment_resolution_histories', (array) $resolutionHistory->first());
        } elseif ($kind === 'resolution_transition') {
            $resolutionHistory->update(['to_status' => PaymentResolutionCase::REASSIGNED]);
        } elseif ($kind === 'resolution_actor') {
            $resolutionHistory->update(['actor_id' => User::factory()->create(['role' => 'treasury'])->id]);
        } elseif ($kind === 'resolution_timestamp') {
            $resolutionHistory->update(['occurred_at' => now()->addMinute()]);
        } elseif ($kind === 'resolution_reason') {
            $resolutionHistory->update(['reason' => 'wrong_reason']);
        } elseif ($kind === 'resolution_metadata') {
            $resolutionHistory->update(['safe_metadata' => json_encode(['currency' => 'USD'])]);
        } elseif ($kind === 'transaction_metadata') {
            DB::table('payment_transactions')->where('id', $fixture['transaction']->id)->update(['metadata' => json_encode(['source' => 'other'])]);
        } elseif ($kind === 'case_reason') {
            DB::table('payment_resolution_cases')->where('id', $fixture['case']->id)->update(['reason' => 'wrong_reason']);
        }
    }

    private function applyFinalStateAnomaly(array $fixture, string $kind): void
    {
        $case = $fixture['case']->fresh();
        $reservation = InventoryReservation::where('resolution_case_id', $case->id)->firstOrFail();
        $movement = InventoryMovement::where('idempotency_key', 'sale-order-item-'.$reservation->order_item_id)->firstOrFail();
        $submissionHistory = DB::table('payment_submission_histories')
            ->where('payment_submission_id', $fixture['submission']->id)
            ->where('event', PaymentSubmissionHistory::REASSIGNMENT_APPROVED);
        $resolutionHistory = DB::table('payment_resolution_histories')
            ->where('payment_resolution_case_id', $case->id)
            ->where('event', 'reassigned');

        $updates = [
            'transaction_status' => ['payment_transactions', $fixture['transaction']->id, ['status' => PaymentTransaction::RECEIVED]],
            'transaction_scope' => ['payment_transactions', $fixture['transaction']->id, ['approved_scope_key' => 'wrong-scope-'.Str::uuid()]],
            'transaction_amount' => ['payment_transactions', $fixture['transaction']->id, ['amount' => '999.00']],
            'transaction_currency' => ['payment_transactions', $fixture['transaction']->id, ['currency' => 'USD']],
            'transaction_order' => ['payment_transactions', $fixture['transaction']->id, ['order_id' => $this->secondaryOrder($fixture)->id]],
            'transaction_confirmed_at' => ['payment_transactions', $fixture['transaction']->id, ['confirmed_at' => null]],
            'submission_status' => ['payment_submissions', $fixture['submission']->id, ['status' => PaymentSubmission::MANUAL_RESOLUTION_REQUIRED]],
            'submission_reviewed_at' => ['payment_submissions', $fixture['submission']->id, ['reviewed_at' => null]],
            'submission_amount' => ['payment_submissions', $fixture['submission']->id, ['expected_amount' => '999.00']],
            'submission_currency' => ['payment_submissions', $fixture['submission']->id, ['currency' => 'USD']],
            'case_status' => ['payment_resolution_cases', $case->id, ['status' => PaymentResolutionCase::OPEN]],
            'case_type' => ['payment_resolution_cases', $case->id, ['type' => PaymentResolutionCase::REFUND]],
            'case_resolved_at' => ['payment_resolution_cases', $case->id, ['resolved_at' => null]],
            'order_payment_status' => ['orders', $fixture['order']->id, ['payment_status' => 'pending']],
            'order_paid_at' => ['orders', $fixture['order']->id, ['paid_at' => null]],
            'order_status' => ['orders', $fixture['order']->id, ['status' => 'delivered']],
            'reservation_active' => ['inventory_reservations', $reservation->id, ['status' => InventoryReservation::ACTIVE]],
            'reservation_released' => ['inventory_reservations', $reservation->id, ['status' => InventoryReservation::RELEASED]],
            'reservation_expired' => ['inventory_reservations', $reservation->id, ['status' => InventoryReservation::EXPIRED]],
            'reservation_consumed_at' => ['inventory_reservations', $reservation->id, ['consumed_at' => null]],
            'movement_incompatible' => ['inventory_movements', $movement->id, ['quantity' => (int) $movement->quantity + 1]],
            'product_stock' => ['products', $reservation->product_id, ['stock' => 999]],
            'negative_stock' => ['warehouse_inventories', $fixture['inventories'][0]->id, ['quantity' => -1]],
        ];
        if (isset($updates[$kind])) {
            [$table, $id, $attributes] = $updates[$kind];
            DB::table($table)->where('id', $id)->update($attributes);

            return;
        }

        if ($kind === 'case_transaction') {
            DB::table('payment_resolution_cases')->where('id', $case->id)->update([
                'received_payment_transaction_id' => $this->secondaryTransaction($fixture)->id,
            ]);

            return;
        }

        if ($kind === 'movement_missing') {
            DB::table('inventory_movements')->where('id', $movement->id)->delete();
        } elseif ($kind === 'movement_duplicate') {
            $row = $movement->getAttributes();
            $row['idempotency_key'] = 'duplicate-sale-'.Str::uuid();
            $this->duplicateTableRow('inventory_movements', $row);
        } elseif ($kind === 'submission_history_missing') {
            $submissionHistory->delete();
        } elseif ($kind === 'submission_history_duplicate') {
            $this->duplicateTableRow('payment_submission_histories', (array) $submissionHistory->first());
        } elseif ($kind === 'submission_history_transition') {
            $submissionHistory->update(['from_status' => PaymentSubmission::OBSERVED]);
        } elseif ($kind === 'submission_history_actor') {
            $submissionHistory->update(['actor_id' => User::factory()->create(['role' => 'treasury'])->id]);
        } elseif ($kind === 'submission_history_metadata') {
            $submissionHistory->update(['safe_metadata' => json_encode(['reservation_count' => 99])]);
        } elseif ($kind === 'resolution_history_missing') {
            $resolutionHistory->delete();
        } elseif ($kind === 'resolution_history_duplicate') {
            $this->duplicateTableRow('payment_resolution_histories', (array) $resolutionHistory->first());
        }
    }

    private function duplicateTableRow(string $table, array $attributes): void
    {
        unset($attributes['id']);
        DB::table($table)->insert($attributes);
    }

    private function fixture(int $itemCount = 1, string $originalStatus = InventoryReservation::EXPIRED, bool $expireAutomatically = false): array
    {
        $treasury = User::factory()->create(['role' => 'treasury', 'is_active' => true]);
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $branch = Branch::create(['code' => 'BR-RA-'.Str::random(8), 'name' => 'Sede reasignación', 'address' => 'Dirección', 'is_active' => true]);
        $order = Order::create([
            'user_id' => $customer->id,
            'status' => 'pending',
            'fulfillment_status' => Order::FULFILLMENT_RESERVED,
            'total' => number_format(25 * $itemCount, 2, '.', ''),
            'shipping_info' => ['address' => 'Prueba'],
            'payment_method' => 'transferencia',
            'payment_status' => 'pending',
            'reserved_until' => now()->subMinute(),
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
        ]);
        $items = $inventories = $products = $originals = [];
        for ($index = 0; $index < $itemCount; $index++) {
            $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'WH-RA-'.Str::random(8), 'name' => 'Almacén '.$index, 'is_active' => true]);
            $product = Product::create(['name' => 'Producto reasignación '.Str::random(8), 'slug' => 'producto-reasignacion-'.Str::uuid(), 'sku' => 'RA-'.Str::upper(Str::random(8)), 'price' => '12.50', 'is_active' => true]);
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
                'status' => $expireAutomatically ? InventoryReservation::ACTIVE : $originalStatus,
                'expires_at' => now()->subHour(),
                'released_at' => $expireAutomatically ? null : now()->subHour(),
                'idempotency_key' => 'reassignment-original-'.$item->id.'-'.Str::uuid(),
                'metadata' => $expireAutomatically ? [] : ['release_reason' => $originalStatus === InventoryReservation::EXPIRED ? 'reservation_expired' : 'order_canceled'],
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
            'operation_number' => 'RA'.Str::upper(Str::random(8)),
            'normalized_operation_number' => Str::upper(Str::random(12)),
            'duplicate_fingerprint' => hash('sha256', Str::uuid()->toString()),
            'declared_paid_at' => now(),
            'receiving_account_snapshot' => [],
            'submitted_by' => $customer->id,
            'submitted_at' => now(),
            'idempotency_key' => 'reassignment-submission-'.Str::uuid(),
        ]);

        if ($expireAutomatically) {
            app(InventoryReservationExpirationService::class)->expireOrder($order->id);
        }
        $case = app(TreasuryPaymentResolutionService::class)->open($submission->id, $treasury->id);
        $transaction = PaymentTransaction::where('payment_submission_id', $submission->id)->firstOrFail();

        return compact('treasury', 'customer', 'order', 'submission', 'case', 'transaction', 'items', 'inventories', 'products', 'originals');
    }

    /** @param array{warehouse: Warehouse, product: Product, inventory: WarehouseInventory} $shared */
    private function sharedLastStockFixture(array $shared, User $treasury, string $label): array
    {
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $order = Order::create([
            'user_id' => $customer->id,
            'status' => 'pending',
            'fulfillment_status' => Order::FULFILLMENT_RESERVED,
            'total' => '20.00',
            'shipping_info' => ['address' => 'Último stock secuencial'],
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
            'idempotency_key' => 'sequential-last-stock-original-'.$label.'-'.Str::uuid(),
            'metadata' => ['release_reason' => 'reservation_expired'],
        ]);
        $submission = PaymentSubmission::create([
            'order_id' => $order->id,
            'channel' => 'yape',
            'status' => PaymentSubmission::PENDING_REVIEW,
            'expected_amount' => '20.00',
            'currency' => 'PEN',
            'operation_number' => 'LS'.Str::upper(Str::random(8)),
            'normalized_operation_number' => 'LS'.Str::upper(Str::random(10)),
            'duplicate_fingerprint' => hash('sha256', $label.Str::uuid()),
            'declared_paid_at' => now(),
            'receiving_account_snapshot' => [],
            'submitted_by' => $customer->id,
            'submitted_at' => now(),
            'idempotency_key' => 'sequential-last-stock-submission-'.$label.'-'.Str::uuid(),
        ]);
        $case = app(TreasuryPaymentResolutionService::class)->open($submission->id, $treasury->id);
        $transaction = PaymentTransaction::where('payment_submission_id', $submission->id)->firstOrFail();
        $items = [$item];
        $inventories = [$shared['inventory']];
        $products = [$shared['product']];
        $originals = [$original];

        return compact('treasury', 'customer', 'order', 'submission', 'case', 'transaction', 'items', 'inventories', 'products', 'originals');
    }

    private function assertSafeUniqueHistories(array $fixture, PaymentResolutionCase $case, bool $reopened, int $reservationCount): void
    {
        $submissionHistory = PaymentSubmissionHistory::where('payment_submission_id', $fixture['submission']->id)
            ->where('event', PaymentSubmissionHistory::REASSIGNMENT_APPROVED)->get();
        $resolutionHistory = PaymentResolutionHistory::where('payment_resolution_case_id', $case->id)
            ->where('event', 'reassigned')->get();
        $expected = ['resolution_case_id' => $case->id, 'reservation_count' => $reservationCount, 'reopened_after_expiration' => $reopened];
        $this->assertCount(1, $submissionHistory);
        $this->assertCount(1, $resolutionHistory);
        $this->assertSame(
            $this->normalizeJsonValue($expected),
            $this->normalizeJsonValue($submissionHistory->first()->safe_metadata)
        );
        $this->assertSame(
            $this->normalizeJsonValue($expected),
            $this->normalizeJsonValue($resolutionHistory->first()->safe_metadata)
        );
        foreach ([$submissionHistory->first()->safe_metadata, $resolutionHistory->first()->safe_metadata] as $metadata) {
            $this->assertArrayNotHasKey('operation_number', $metadata);
            $this->assertArrayNotHasKey('idempotency_key', $metadata);
            $this->assertArrayNotHasKey('receiving_account_snapshot', $metadata);
        }
    }

    private function normalizeJsonValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeJsonValue($item), $value);
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeJsonValue($item);
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function assertNotNullConstraintViolation(QueryException $exception, string $column): void
    {
        [$sqlState, $driverCode, $diagnostic] = $this->constraintErrorInfo($exception);
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->assertSame(19, $driverCode);
            $this->assertStringContainsStringIgnoringCase('not null constraint failed', $diagnostic);
            $this->assertStringContainsStringIgnoringCase($column, $diagnostic);

            return;
        }

        $this->assertSame('mysql', $driver);
        $this->assertSame('23000', $sqlState);
        $this->assertSame(1048, $driverCode);
        $this->assertStringContainsStringIgnoringCase("column '{$column}' cannot be null", $diagnostic);
    }

    private function assertUniqueConstraintViolation(
        QueryException $exception,
        string $sqliteField,
        string $mysqlConstraint
    ): void {
        [$sqlState, $driverCode, $diagnostic] = $this->constraintErrorInfo($exception);
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->assertSame(19, $driverCode);
            $this->assertStringContainsStringIgnoringCase('unique constraint failed', $diagnostic);
            $this->assertStringContainsStringIgnoringCase($sqliteField, $diagnostic);

            return;
        }

        $this->assertSame('mysql', $driver);
        $this->assertSame('23000', $sqlState);
        $this->assertSame(1062, $driverCode);
        $this->assertStringContainsStringIgnoringCase('duplicate entry', $diagnostic);
        $this->assertStringContainsStringIgnoringCase($mysqlConstraint, $diagnostic);
    }

    private function assertForeignKeyConstraintViolation(QueryException $exception, int $mysqlDriverCode): void
    {
        [$sqlState, $driverCode, $diagnostic] = $this->constraintErrorInfo($exception);
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->assertSame(19, $driverCode);
            $this->assertStringContainsStringIgnoringCase('foreign key constraint failed', $diagnostic);

            return;
        }

        $this->assertSame('mysql', $driver);
        $this->assertSame('23000', $sqlState);
        $this->assertContains($mysqlDriverCode, [1451, 1452]);
        $this->assertSame($mysqlDriverCode, $driverCode);
        $this->assertStringContainsStringIgnoringCase('foreign key constraint fails', $diagnostic);
    }

    private function assertNegativeValueRejected(QueryException $exception, string $column): void
    {
        [$sqlState, $driverCode, $diagnostic] = $this->constraintErrorInfo($exception);

        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('22003', $sqlState);
        $this->assertSame(1264, $driverCode);
        $this->assertStringContainsStringIgnoringCase('out of range value', $diagnostic);
        $this->assertStringContainsStringIgnoringCase("column '{$column}'", $diagnostic);
    }

    /** @return array{0: string, 1: int, 2: string} */
    private function constraintErrorInfo(QueryException $exception): array
    {
        $this->assertIsArray($exception->errorInfo);
        $this->assertCount(3, $exception->errorInfo);

        return [
            (string) $exception->errorInfo[0],
            (int) $exception->errorInfo[1],
            (string) $exception->errorInfo[2],
        ];
    }

    private function createTransactionApprovalFailureTrigger(string $trigger, ?int $transactionId, ?PDO $mysqlPdo): void
    {
        $this->assertMatchesRegularExpression('/\Areassign_tx_fail_[a-f0-9]{8}\z/', $trigger);
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->assertIsInt($transactionId);
            $this->assertGreaterThan(0, $transactionId);
            DB::unprepared("CREATE TRIGGER `{$trigger}` BEFORE UPDATE ON payment_transactions WHEN NEW.id = {$transactionId} AND NEW.status = 'approved' AND OLD.status <> 'approved' BEGIN SELECT RAISE(ABORT, 'approval transition failure'); END");

            return;
        }

        $this->assertSame('mysql', $driver);
        $this->assertNull($transactionId);
        $this->assertInstanceOf(PDO::class, $mysqlPdo);
        $mysqlPdo->exec("CREATE TRIGGER `{$trigger}` BEFORE UPDATE ON payment_transactions FOR EACH ROW BEGIN IF NEW.status = 'approved' AND OLD.status <> 'approved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'approval transition failure'; END IF; END");
    }

    private function dropFailureTrigger(string $trigger, ?PDO $mysqlPdo): void
    {
        $this->assertMatchesRegularExpression('/\Areassign_tx_fail_[a-f0-9]{8}\z/', $trigger);
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$trigger}`");

            return;
        }

        $this->assertInstanceOf(PDO::class, $mysqlPdo);
        $mysqlPdo->exec("DROP TRIGGER IF EXISTS `{$trigger}`");
    }

    private function failureTriggerExists(string $trigger, ?PDO $mysqlPdo): bool
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return (int) DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->where('name', $trigger)
                ->count() > 0;
        }

        $this->assertInstanceOf(PDO::class, $mysqlPdo);
        $statement = $mysqlPdo->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?');
        $statement->execute([$trigger]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function mysqlTriggerPdo(): PDO
    {
        $config = config('database.connections.mysql');
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset'] ?? 'utf8mb4'
            ),
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $this->assertSame('lubristore_2b4_resolution_certification_test', $database);

        return $pdo;
    }

    private function snapshot(array $fixture): array
    {
        return [
            'order' => $fixture['order']->fresh()->getAttributes(),
            'submission' => $fixture['submission']->fresh()->getAttributes(),
            'case' => $fixture['case']->fresh()?->getAttributes(),
            'transactions' => PaymentTransaction::orderBy('id')->get()->map(fn ($model) => $model->getAttributes())->all(),
            'cases' => PaymentResolutionCase::orderBy('id')->get()->map(fn ($model) => $model->getAttributes())->all(),
            'refunds' => PaymentRefund::orderBy('id')->get()->map(fn ($model) => $model->getAttributes())->all(),
            'items' => OrderItem::orderBy('id')->get()->map(fn ($model) => $model->getAttributes())->all(),
            'handling' => OrderHandlingProcess::orderBy('id')->get()->map(fn ($model) => $model->getAttributes())->all(),
            'reservations' => InventoryReservation::orderBy('id')->get()->map(fn ($model) => $model->getAttributes())->all(),
            'inventories' => collect($fixture['inventories'])->map(fn ($model) => $model->fresh()->getAttributes())->all(),
            'products' => collect($fixture['products'])->map(fn ($model) => $model->fresh()->getAttributes())->all(),
            'movements' => InventoryMovement::orderBy('id')->get()->map(fn ($model) => $model->getAttributes())->all(),
            'submission_histories' => PaymentSubmissionHistory::orderBy('id')->get()->map(fn ($model) => $model->getAttributes())->all(),
            'resolution_histories' => PaymentResolutionHistory::orderBy('id')->get()->map(fn ($model) => $model->getAttributes())->all(),
            'fulfillment_histories' => OrderFulfillmentHistory::orderBy('id')->get()->map(fn ($model) => $model->getAttributes())->all(),
        ];
    }
}
