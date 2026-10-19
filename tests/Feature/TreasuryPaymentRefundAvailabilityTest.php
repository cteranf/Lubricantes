<?php

namespace Tests\Feature;

use App\Exceptions\PaymentRefundConflictException;
use App\Models\Order;
use App\Models\PaymentRefund;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use App\Services\InventoryBalanceLockService;
use App\Services\TreasuryPaymentRefundAvailabilityService;
use App\Services\TreasuryPaymentRefundService;
use App\ValueObjects\TreasuryPaymentRefundAvailability;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Reuses A1 fixtures and canonical private-safe snapshots, including inherited regressions. */
class TreasuryPaymentRefundAvailabilityTest extends TreasuryPaymentRefundServiceTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'logging.default' => 'null']);
    }

    /** @dataProvider availabilityMethods */
    public function test_availability_is_read_only_and_agrees_with_both_real_commands(string $method, bool $submission, bool $case = false): void
    {
        $fixture = $this->fixture($method, $submission, $case);
        if ($method === 'card') {
            DB::table('payment_transactions')->where('id', $fixture['transaction']->id)->update(['gateway' => 'mercadopago']);
        }
        $this->assertReadOnlyAvailability($fixture, [true, false, false]);
        $refund = app(TreasuryPaymentRefundService::class)->markPending($fixture['order'], $fixture['treasury'], 'Synthetic request');
        $this->assertSame(PaymentRefund::PENDING, $refund->status);
        $this->assertReadOnlyAvailability($fixture, [false, true, false]);
        $completed = app(TreasuryPaymentRefundService::class)->complete($refund->id, $fixture['treasury']->id, 'synthetic-completion');
        $this->assertSame(PaymentRefund::COMPLETED, $completed->status);
        $this->assertReadOnlyAvailability($fixture, [false, false, false]);
    }

    public static function availabilityMethods(): array
    {
        return [
            'transfer with presentation' => ['transferencia', true],
            'historical transfer' => ['transferencia', false],
            'Mercado Pago card' => ['card', false],
            'cash on delivery' => ['contra_entrega', false],
            'branch payment' => ['pago_en_sede', false],
            'coherent B2' => ['transferencia', true, true],
        ];
    }

    /** @dataProvider normalUnavailableStates */
    public function test_normal_business_exclusions_are_not_classified_as_corruption(string $state): void
    {
        $fixture = $this->fixture();
        if ($state === 'not canceled') {
            $fixture['order']->update(['status' => 'confirmed', 'tracking_status' => 'confirmed', 'fulfillment_status' => Order::FULFILLMENT_RESERVED]);
        } elseif ($state === 'delivered') {
            $fixture['order']->update(['status' => 'delivered', 'tracking_status' => 'delivered', 'fulfillment_status' => Order::FULFILLMENT_DELIVERED, 'delivered_at' => now()]);
        } elseif ($state === 'picked up') {
            $fixture['order']->update(['status' => 'delivered', 'tracking_status' => 'picked_up', 'fulfillment_status' => Order::FULFILLMENT_DELIVERED, 'picked_up_at' => now()]);
        }
        $this->assertReadOnlyAvailability($fixture, [false, false, false]);
    }

    public static function normalUnavailableStates(): array
    {
        return ['not canceled' => ['not canceled'], 'delivered' => ['delivered'], 'picked up' => ['picked up']];
    }

    /** @dataProvider transversalFinancialStates */
    public function test_transversal_integrity_precedes_normal_business_exclusions(string $business, string $financial): void
    {
        $withSubmission = ! in_array($financial, ['pending projection', 'refunded projection', 'orphan accounting', 'coherent'], true);
        $fixture = $this->fixture(withSubmission: $withSubmission);
        $refund = null;
        if (in_array($financial, ['pending wrong projection', 'completed wrong projection', 'crossed accounting', 'duplicate history', 'impossible chronology', 'coherent pending', 'coherent completed'], true)) {
            $refund = app(TreasuryPaymentRefundService::class)->markPending($fixture['order'], $fixture['treasury'], 'Synthetic request');
            if (in_array($financial, ['completed wrong projection', 'crossed accounting', 'impossible chronology', 'coherent completed'], true)) {
                $refund = app(TreasuryPaymentRefundService::class)->complete($refund->id, $fixture['treasury']->id, 'synthetic-completion');
            }
        }
        switch ($financial) {
            case 'pending projection':
            case 'refunded projection':
                $fixture['order']->update(['payment_status' => $financial === 'pending projection' ? 'refund_pending' : 'refunded']);
                break;
            case 'pending presentation':
            case 'refunded presentation':
                $fixture['submission']->update(['status' => $financial === 'pending presentation' ? PaymentSubmission::REFUND_PENDING : PaymentSubmission::REFUNDED]);
                break;
            case 'orphan accounting':
                $copy = $fixture['transaction']->getAttributes();
                unset($copy['id']);
                $copy['transaction_type'] = PaymentTransaction::REFUND;
                $copy['approved_scope_key'] = null;
                $copy['idempotency_key'] = 'synthetic-orphan-accounting';
                DB::table('payment_transactions')->insert($copy);
                break;
            case 'orphan pending event':
            case 'orphan completed event':
                DB::table('payment_submission_histories')->insert([
                    'payment_submission_id' => $fixture['submission']->id,
                    'event' => $financial === 'orphan pending event' ? PaymentSubmissionHistory::REFUND_MARKED_PENDING : PaymentSubmissionHistory::REFUND_COMPLETED,
                    'to_status' => PaymentSubmission::REFUND_PENDING,
                    'occurred_at' => now(), 'created_at' => now(),
                ]);
                break;
            case 'pending wrong projection':
            case 'completed wrong projection':
                $updated = DB::table('orders')->where('id', $fixture['order']->id)->update(['payment_status' => 'approved']);
                $this->assertTrue($updated === 1, 'Only the synthetic order projection must change.');
                break;
            case 'crossed accounting':
                $other = $this->fixture();
                DB::table('payment_transactions')->where('id', $refund->refund_payment_transaction_id)->update(['order_id' => $other['order']->id]);
                break;
            case 'duplicate history':
                $copy = (array) DB::table('payment_submission_histories')->where('payment_submission_id', $fixture['submission']->id)->where('event', PaymentSubmissionHistory::REFUND_MARKED_PENDING)->sole();
                unset($copy['id']);
                DB::table('payment_submission_histories')->insert($copy);
                break;
            case 'impossible chronology':
                DB::table('payment_refunds')->where('id', $refund->id)->update(['completed_at' => $refund->requested_at->copy()->subHour()]);
                break;
        }
        $fixture['order']->update(match ($business) {
            'delivered' => ['status' => 'delivered', 'delivered_at' => now()],
            'picked up' => ['status' => 'delivered', 'picked_up_at' => now()],
            default => ['status' => 'confirmed'],
        });
        // No business exclusion may demand A1's compensated inventory evidence.
        $deleted = DB::table('inventory_movements')->where('id', $fixture['return']->id)->delete();
        $this->assertTrue($deleted === 1, 'Only the synthetic return must be removed.');
        $coherent = in_array($financial, ['coherent', 'coherent pending', 'coherent completed'], true);
        $this->assertReadOnlyAvailability($fixture, [false, false, ! $coherent]);
    }

    public static function transversalFinancialStates(): array
    {
        $datasets = [];
        foreach (['not canceled', 'delivered', 'picked up'] as $business) {
            foreach (['pending projection', 'refunded projection', 'pending presentation', 'refunded presentation', 'orphan accounting', 'orphan pending event', 'orphan completed event', 'pending wrong projection', 'completed wrong projection', 'crossed accounting', 'duplicate history', 'impossible chronology', 'coherent', 'coherent pending', 'coherent completed'] as $financial) {
                $datasets[$business.' / '.$financial] = [$business, $financial];
            }
        }

        return $datasets;
    }

    public function test_business_exclusion_does_not_hide_crossed_financial_presentation(): void
    {
        foreach (['presentation', 'B2 history', 'B2 state'] as $state) {
            $fixture = $this->fixture(withSubmission: true, withResolutionCase: $state !== 'presentation');
            $fixture['order']->update(['status' => 'confirmed']);
            if ($state === 'presentation') {
                DB::table('payment_submissions')->where('id', $fixture['submission']->id)->update(['expected_amount' => '49.00']);
            } elseif ($state === 'B2 history') {
                DB::table('payment_resolution_histories')->where('payment_resolution_case_id', $fixture['case']->id)->where('event', 'reassigned')->delete();
            } else {
                DB::table('payment_resolution_cases')->where('id', $fixture['case']->id)->update(['resolved_at' => null]);
            }
            $this->assertReadOnlyAvailability($fixture, [false, false, true]);
        }
    }

    /** @dataProvider manualReviewStates */
    public function test_related_incoherent_evidence_requires_manual_review_without_repair(string $state): void
    {
        $fixture = $this->fixture(withSubmission: true, withResolutionCase: true);
        if (str_starts_with($state, 'refund ') || str_starts_with($state, 'history ')) {
            $fixture['refund'] = app(TreasuryPaymentRefundService::class)->markPending($fixture['order'], $fixture['treasury'], 'Synthetic request');
        }
        switch ($state) {
            case 'duplicate approved':
                $copy = $fixture['transaction']->getAttributes();
                unset($copy['id']);
                $copy['approved_scope_key'] = null;
                $copy['payment_submission_id'] = null;
                $copy['idempotency_key'] = 'synthetic-duplicate-payment';
                DB::table('payment_transactions')->insert($copy);
                break;
            case 'crossed payment':
                $other = $this->fixture();
                DB::table('payment_transactions')->where('id', $fixture['transaction']->id)->update(['order_id' => $other['order']->id]);
                break;
            case 'amount':
            case 'currency':
            case 'method':
                $field = $state === 'method' ? 'payment_method' : $state;
                $value = ['amount' => '49.00', 'currency' => 'USD', 'method' => 'card'][$state];
                DB::table('payment_transactions')->where('id', $fixture['transaction']->id)->update([$field => $value]);
                break;
            case 'paid timestamp':
                $fixture['order']->update(['paid_at' => null]);
                break;
            case 'refund identity':
                DB::table('payment_refunds')->where('id', $fixture['refund']->id)->update(['original_payment_transaction_id' => null]);
                break;
            case 'refund partial':
                DB::table('payment_refunds')->where('id', $fixture['refund']->id)->update(['amount' => '49.00']);
                break;
            case 'refund future':
                DB::table('payment_refunds')->where('id', $fixture['refund']->id)->update(['requested_at' => now()->addHour()]);
                break;
            case 'refund projection':
                $fixture['order']->update(['payment_status' => 'refunded']);
                break;
            case 'presentation':
                DB::table('payment_submissions')->where('id', $fixture['submission']->id)->update(['expected_amount' => '49.00']);
                break;
            case 'B2 partial':
                DB::table('payment_resolution_cases')->where('id', $fixture['case']->id)->update(['resolved_at' => null]);
                break;
            case 'crossed reservation':
                $other = $this->fixture();
                DB::table('inventory_reservations')->where('id', $fixture['reservation']->id)->update(['order_id' => $other['order']->id]);
                break;
            case 'missing return':
                $deleted = DB::table('inventory_movements')->where('id', $fixture['return']->id)->delete();
                $this->assertTrue($deleted === 1, 'Only the synthetic return must be removed.');
                break;
            case 'global balance':
                $this->addForeignActiveReservation($fixture);
                $fixture['inventory']->update(['reserved_quantity' => 1]);
                break;
            case 'global crossed':
                $foreign = $this->addForeignActiveReservation($fixture);
                DB::table('inventory_reservations')->where('id', $foreign['reservation']->id)->update(['order_id' => $fixture['order']->id]);
                break;
            case 'stock':
                $fixture['product']->forceFill(['stock' => 13])->save();
                break;
            case 'physical balance':
                $fixture['inventory']->update(['quantity' => 1, 'reserved_quantity' => 2]);
                break;
            case 'history missing':
                DB::table('payment_submission_histories')->where('payment_submission_id', $fixture['submission']->id)->where('event', PaymentSubmissionHistory::REFUND_MARKED_PENDING)->delete();
                break;
            case 'history duplicate':
                $copy = (array) DB::table('payment_submission_histories')->where('payment_submission_id', $fixture['submission']->id)->where('event', PaymentSubmissionHistory::REFUND_MARKED_PENDING)->sole();
                unset($copy['id']);
                DB::table('payment_submission_histories')->insert($copy);
                break;
        }
        $this->assertReadOnlyAvailability($fixture, [false, false, true]);
    }

    public static function manualReviewStates(): array
    {
        $states = ['duplicate approved', 'crossed payment', 'amount', 'currency', 'method', 'paid timestamp', 'refund identity', 'refund partial', 'refund future', 'refund projection', 'presentation', 'B2 partial', 'crossed reservation', 'missing return', 'global balance', 'global crossed', 'stock', 'physical balance', 'history missing', 'history duplicate'];

        return array_combine($states, array_map(fn ($state) => [$state], $states));
    }

    public function test_legitimate_foreign_global_reservations_do_not_block_either_action(): void
    {
        $fixture = $this->fixture();
        $this->addForeignActiveReservation($fixture);
        $this->assertReadOnlyAvailability($fixture, [true, false, false]);
        $refund = app(TreasuryPaymentRefundService::class)->markPending($fixture['order'], $fixture['treasury'], 'Synthetic request');
        $this->assertReadOnlyAvailability($fixture, [false, true, false]);
        app(TreasuryPaymentRefundService::class)->complete($refund->id, $fixture['treasury']->id, 'synthetic-completion');
        $this->assertReadOnlyAvailability($fixture, [false, false, false]);
    }

    public function test_a_change_after_availability_is_still_rejected_by_each_command(): void
    {
        $fixture = $this->fixture();
        $this->assertReadOnlyAvailability($fixture, [true, false, false]);
        $fixture['order']->update(['status' => 'confirmed']);
        $before = $this->snapshot($fixture);
        try {
            app(TreasuryPaymentRefundService::class)->markPending($fixture['order'], $fixture['treasury'], 'Synthetic request');
            $this->fail('Changed state must be rejected.');
        } catch (PaymentRefundConflictException) {
            $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        }
        $fixture['order']->update(['status' => 'canceled']);
        $refund = app(TreasuryPaymentRefundService::class)->markPending($fixture['order'], $fixture['treasury'], 'Synthetic request');
        $this->assertReadOnlyAvailability($fixture, [false, true, false]);
        DB::table('payment_refunds')->where('id', $refund->id)->update(['amount' => '49.00']);
        $before = $this->snapshot($fixture);
        try {
            app(TreasuryPaymentRefundService::class)->complete($refund->id, $fixture['treasury']->id, 'synthetic-completion');
            $this->fail('Changed state must be rejected.');
        } catch (PaymentRefundConflictException) {
            $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        }
    }

    public function test_query_errors_propagate_without_becoming_availability_flags(): void
    {
        $fixture = $this->fixture();
        $service = new class(app(InventoryBalanceLockService::class)) extends TreasuryPaymentRefundAvailabilityService
        {
            protected function readFinancialState(int $orderId): ?array
            {
                DB::select('SELECT * FROM missing_refund_availability_fixture');

                return null;
            }
        };
        $before = $this->snapshot($fixture);
        $level = DB::connection()->transactionLevel();
        try {
            $service->forOrder($fixture['order']->id);
            $this->fail('The SQL error must propagate.');
        } catch (QueryException $error) {
            $this->assertTrue($error instanceof QueryException, 'The SQL error must remain a SQL error.');
        }
        $this->assertSame($level, DB::connection()->transactionLevel());
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        $this->assertTrue((int) DB::selectOne('SELECT 1 AS usable')->usable === 1);
    }

    public function test_the_result_is_immutable_and_contains_only_three_booleans(): void
    {
        $result = new TreasuryPaymentRefundAvailability(can_mark_refund_pending: true);
        $this->assertSame(['can_mark_refund_pending' => true, 'can_complete_refund' => false, 'requires_manual_review' => false], $result->toArray());
        try {
            $result->can_complete_refund = true;
            $this->fail('Availability must remain immutable.');
        } catch (\Error) {
            $this->assertFalse($result->can_complete_refund);
        }
        $this->assertSame(['can_mark_refund_pending' => false, 'can_complete_refund' => false, 'requires_manual_review' => false], app(TreasuryPaymentRefundAvailabilityService::class)->forOrder(0)->toArray());
        $this->assertSame(['can_mark_refund_pending' => false, 'can_complete_refund' => false, 'requires_manual_review' => false], app(TreasuryPaymentRefundAvailabilityService::class)->forOrder(PHP_INT_MAX)->toArray());
    }

    private function assertReadOnlyAvailability(array $fixture, array $flags): void
    {
        $before = $this->snapshot($fixture);
        $globalBefore = $this->databaseDigest();
        $connection = DB::connection();
        $level = $connection->transactionLevel();
        $originalDispatcher = $connection->getEventDispatcher();
        $probe = clone $originalDispatcher;
        $originalModels = Model::getEventDispatcher();
        $modelProbe = clone $originalModels;
        $writes = 0;
        $transactions = 0;
        $financialEvents = 0;
        $queries = 0;
        $locks = 0;
        $probe->listen(QueryExecuted::class, function ($event) use (&$writes, &$queries, &$locks): void {
            $queries++;
            $writes += (int) preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $event->sql);
            $locks += (int) preg_match('/\bfor\s+update\b/i', $event->sql);
        });
        foreach ([TransactionBeginning::class, TransactionCommitted::class, TransactionRolledBack::class] as $event) {
            $probe->listen($event, function () use (&$transactions): void {
                $transactions++;
            });
        }
        foreach (['creating', 'updating', 'deleting', 'saved'] as $event) {
            $modelProbe->listen('eloquent.'.$event.': *', function () use (&$financialEvents): void {
                $financialEvents++;
            });
        }
        $connection->setEventDispatcher($probe);
        Model::setEventDispatcher($modelProbe);
        try {
            $service = app(TreasuryPaymentRefundAvailabilityService::class);
            $first = $service->forOrder((int) $fixture['order']->id);
            $second = $service->forOrder((int) $fixture['order']->id);
            $queryCount = $queries;
            $expected = array_combine(['can_mark_refund_pending', 'can_complete_refund', 'requires_manual_review'], $flags);
            $this->assertSame($expected, $first->toArray());
            $this->assertSame($expected, $second->jsonSerialize());
            $this->assertSame($queryCount, $queries, 'Result serialization must not query.');
            $this->assertSame(0, $writes);
            $this->assertSame(0, $locks);
            $this->assertSame(0, $transactions);
            $this->assertSame(0, $financialEvents);
            $this->assertSame($level, $connection->transactionLevel());
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
            Model::setEventDispatcher($originalModels);
        }
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        $this->assertTrue(hash_equals($globalBefore, $this->databaseDigest()), 'The complete private database snapshot must remain unchanged.');
        $this->assertTrue((int) DB::selectOne('SELECT 1 AS usable')->usable === 1);
    }

    private function databaseDigest(): string
    {
        $tables = ['users', 'branches', 'warehouses', 'products', 'orders', 'order_items', 'warehouse_inventories', 'inventory_reservations', 'inventory_movements', 'order_fulfillment_histories', 'order_handling_processes', 'order_handling_items', 'order_handling_incidents', 'order_handling_histories', 'order_deliveries', 'order_delivery_attempts', 'order_delivery_histories', 'payment_transactions', 'payment_refunds', 'payment_submissions', 'payment_submission_histories', 'payment_resolution_cases', 'payment_resolution_histories'];
        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return hash('sha256', json_encode($this->canonicalize($rows), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
