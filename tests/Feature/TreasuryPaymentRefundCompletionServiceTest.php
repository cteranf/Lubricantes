<?php

namespace Tests\Feature;

use App\Exceptions\PaymentRefundConflictException;
use App\Models\Order;
use App\Models\PaymentRefund;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\InventoryBalanceLockService;
use App\Services\PaymentReassignmentFinalState;
use App\Services\TreasuryPaymentRefundService;
use App\Services\TreasuryPaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;

/** Reuses the complete, private-safe A1 fixtures and snapshots. */
class TreasuryPaymentRefundCompletionServiceTest extends TreasuryPaymentRefundServiceTest
{
    /** @dataProvider globalReservationStates */
    public function test_completion_reconciles_global_reservations_without_touching_foreign_inventory(string $state): void
    {
        $fixture = $this->pendingFixture();
        $foreign = $this->addForeignActiveReservation($fixture);
        if ($state === 'mismatch') {
            $foreign['balance']->update(['reserved_quantity' => 1]);
        } elseif ($state === 'crossed') {
            DB::table('inventory_reservations')->where('id', $foreign['reservation']->id)->update(['order_id' => $fixture['order']->id]);
        } elseif ($state === 'physical') {
            DB::table('inventory_reservations')->where('id', $foreign['reservation']->id)->update(['released_at' => now()]);
        }
        $before = $this->protectedSnapshot($fixture);
        $foreignBefore = $this->foreignReservationDigest($foreign);
        $operation = fn () => app(TreasuryPaymentRefundService::class)->complete($fixture['refund']->id, $fixture['treasury']->id, 'global-completion-fixture');
        if ($state === 'valid') {
            $this->assertSame(PaymentRefund::COMPLETED, $operation()->status);
        } else {
            $this->assertConflictUnchanged($fixture, $operation);
        }
        $this->assertSnapshotUnchanged($before, $this->protectedSnapshot($fixture));
        $this->assertTrue(hash_equals($foreignBefore, $this->foreignReservationDigest($foreign)), 'Foreign inventory and reservation must remain unchanged.');
    }

    /** @dataProvider completionMethods */
    public function test_completes_total_refunds_without_inventory_or_logistics_writes(string $method, bool $submission, bool $case): void
    {
        $fixture = $this->pendingFixture($method, $submission, $case);
        $before = $this->protectedSnapshot($fixture);
        $requestedAt = $fixture['refund']->requested_at;
        $requestedBy = $fixture['refund']->requested_by;
        $otherTreasury = User::factory()->create(['role' => 'treasury', 'is_active' => true]);
        $reference = '  00-Referencia.Prueba/AbC  ';

        $refund = app(TreasuryPaymentRefundService::class)->complete($fixture['refund']->id, $otherTreasury->id, $reference);

        $this->assertSame(PaymentRefund::COMPLETED, $refund->status);
        $this->assertSame($requestedBy, $refund->requested_by);
        $this->assertTrue($requestedAt->equalTo($refund->requested_at));
        $this->assertSame((int) $otherTreasury->id, $refund->completed_by);
        $this->assertTrue(hash_equals(hash('sha256', trim($reference)), hash('sha256', $refund->external_reference)), 'The private reference must round-trip safely.');
        $cipher = DB::table('payment_refunds')->where('id', $refund->id)->value('external_reference');
        $this->assertFalse(str_contains($cipher, trim($reference)), 'The persisted reference must be encrypted.');
        $this->assertLessThanOrEqual(65535, strlen($cipher));
        $this->assertArrayNotHasKey('external_reference', $refund->toArray());
        $this->assertArrayNotHasKey('idempotency_key', $refund->toArray());
        $this->assertFalse($refund->relationLoaded('refundPaymentTransaction'));
        $this->assertSame('refunded', $fixture['order']->fresh()->payment_status);
        $accounting = $refund->refundPaymentTransaction;
        $this->assertSame(PaymentTransaction::REFUND, $accounting->transaction_type);
        $this->assertSame(PaymentTransaction::APPROVED, $accounting->status);
        $this->assertSame('manual', $accounting->gateway);
        $this->assertSame($method, $accounting->payment_method);
        $this->assertSame('50.00', (string) $accounting->amount);
        $this->assertSame('PEN', $accounting->currency);
        $this->assertSame((int) $otherTreasury->id, (int) $accounting->confirmed_by);
        $this->assertTrue($accounting->confirmed_at->equalTo($refund->completed_at));
        foreach (['approved_scope_key', 'payment_submission_id', 'external_reference', 'manual_reference', 'provider_payment_id', 'payment_preference_id', 'provider_status', 'provider_status_detail', 'verified_at', 'collection_method', 'collected_by', 'collected_at', 'failed_at', 'failure_reason', 'metadata'] as $field) {
            $this->assertTrue($accounting->{$field} === null, 'The refund accounting entry must not contain private or provider data.');
        }
        $events = PaymentSubmissionHistory::where('event', PaymentSubmissionHistory::REFUND_COMPLETED)->get();
        $this->assertCount($submission ? 1 : 0, $events);
        if ($submission) {
            $history = $events->sole();
            $this->assertSame(PaymentSubmission::REFUNDED, $fixture['submission']->fresh()->status);
            $this->assertSame(PaymentSubmission::REFUND_PENDING, $history->from_status);
            $this->assertSame(PaymentSubmission::REFUNDED, $history->to_status);
            $this->assertSame((int) $otherTreasury->id, (int) $history->actor_id);
            $this->assertTrue($history->occurred_at->equalTo($refund->completed_at));
            $this->assertSame(['amount' => '50.00', 'currency' => 'PEN'], $history->safe_metadata);
        }
        $this->assertSnapshotUnchanged($before, $this->protectedSnapshot($fixture));
        if ($case) {
            $this->assertFalse($this->b2Evidence($fixture, false), 'Default B2 validation must not accept refunded.');
            $this->assertTrue($this->b2Evidence($fixture, true), 'Explicit refund validation must preserve B2 evidence.');
        }
    }

    public static function completionMethods(): array
    {
        return [
            'transfer with B2' => ['transferencia', true, true],
            'transfer without case' => ['transferencia', true, false],
            'transfer without submission' => ['transferencia', false, false],
            'Mercado Pago card' => ['card', false, false],
            'COD' => ['contra_entrega', false, false],
            'pickup payment' => ['pago_en_sede', false, false],
        ];
    }

    public function test_retry_by_any_active_treasury_preserves_first_completion_and_all_timestamps(): void
    {
        $fixture = $this->pendingFixture();
        $service = app(TreasuryPaymentRefundService::class);
        $first = $service->complete($fixture['refund']->id, $fixture['treasury']->id, '00/Ref-A');
        $other = User::factory()->create(['role' => 'treasury', 'is_active' => true]);
        $before = $this->snapshot($fixture);
        foreach ([$fixture['treasury']->id, $other->id] as $actorId) {
            $retry = $service->complete($first->id, $actorId, ' 00/Ref-A ');
            $this->assertSame($first->id, $retry->id);
            $this->assertSame($first->completed_by, $retry->completed_by);
            $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        }
        $this->assertConflictUnchanged($fixture, fn () => $service->complete($first->id, $other->id, '00/Ref-B'));
    }

    /** @dataProvider invalidCompletionReferences */
    public function test_rejects_invalid_references_without_writes(string $reference): void
    {
        $fixture = $this->pendingFixture();
        $this->assertConflictUnchanged($fixture, fn () => app(TreasuryPaymentRefundService::class)->complete($fixture['refund']->id, $fixture['treasury']->id, $reference));
    }

    public static function invalidCompletionReferences(): array
    {
        return [
            'empty' => [''], 'spaces' => ['   '], 'newline' => ["Ref\n"], 'tab' => ["\tRef"],
            'nul' => ["Ref\0"], 'invalid UTF8' => ["\xff"], 'Unicode line' => ["Ref\u{2028}line"],
            'Unicode control' => ["Ref\u{200B}"], '256 ASCII bytes' => [str_repeat('a', 256)],
            '256 UTF8 bytes' => [str_repeat('á', 128)],
        ];
    }

    public function test_accepts_the_utf8_byte_boundary_and_preserves_case_punctuation_and_zeroes(): void
    {
        $fixture = $this->pendingFixture();
        $reference = str_repeat('á', 127).'0';
        $refund = app(TreasuryPaymentRefundService::class)->complete($fixture['refund']->id, $fixture['treasury']->id, $reference);
        $this->assertTrue(hash_equals(hash('sha256', $reference), hash('sha256', $refund->external_reference)), 'The exact private byte boundary must round-trip.');
    }

    public function test_rejects_invalid_ids_and_missing_or_unauthorized_actors_even_on_retry(): void
    {
        $fixture = $this->pendingFixture();
        $service = app(TreasuryPaymentRefundService::class);
        $inactive = User::factory()->create(['role' => 'treasury', 'is_active' => false]);
        foreach ([0, -1, PHP_INT_MAX, $inactive->id, $fixture['customer']->id] as $actorId) {
            $this->assertConflictUnchanged($fixture, fn () => $service->complete($fixture['refund']->id, $actorId, 'fixture-ref'));
        }
        foreach ([0, -1, PHP_INT_MAX] as $refundId) {
            $this->assertConflictUnchanged($fixture, fn () => $service->complete($refundId, $fixture['treasury']->id, 'fixture-ref'));
        }
        $service->complete($fixture['refund']->id, $fixture['treasury']->id, 'fixture-ref');
        $this->assertConflictUnchanged($fixture, fn () => $service->complete($fixture['refund']->id, $inactive->id, 'fixture-ref'));
    }

    /** @dataProvider incompatibleCompletionStates */
    public function test_rejects_partial_crossed_and_incompatible_financial_states(string $corruption): void
    {
        $fixture = $this->pendingFixture();
        $refund = $fixture['refund'];
        match ($corruption) {
            'failed' => DB::table('payment_refunds')->where('id', $refund->id)->update(['status' => PaymentRefund::FAILED]),
            'completed partial' => DB::table('payment_refunds')->where('id', $refund->id)->update(['status' => PaymentRefund::COMPLETED]),
            'missing original' => DB::table('payment_refunds')->where('id', $refund->id)->update(['original_payment_transaction_id' => null]),
            'partial timestamp' => DB::table('payment_refunds')->where('id', $refund->id)->update(['completed_at' => now()]),
            'partial reference' => $refund->forceFill(['external_reference' => 'fixture-partial'])->save(),
            'no requester' => DB::table('payment_refunds')->where('id', $refund->id)->update(['requested_by' => null]),
            'wrong refund amount' => DB::table('payment_refunds')->where('id', $refund->id)->update(['amount' => '1.00']),
            'order not canceled' => DB::table('orders')->where('id', $fixture['order']->id)->update(['status' => 'pending']),
            'order delivered' => DB::table('orders')->where('id', $fixture['order']->id)->update(['delivered_at' => now()]),
            'order picked up' => DB::table('orders')->where('id', $fixture['order']->id)->update(['picked_up_at' => now()]),
            'order not pending refund' => DB::table('orders')->where('id', $fixture['order']->id)->update(['payment_status' => 'approved']),
            'submission not pending refund' => DB::table('payment_submissions')->where('id', $fixture['submission']->id)->update(['status' => PaymentSubmission::APPROVED]),
            'wrong original amount' => DB::table('payment_transactions')->where('id', $fixture['transaction']->id)->update(['amount' => '1.00']),
            'wrong original type' => DB::table('payment_transactions')->where('id', $fixture['transaction']->id)->update(['transaction_type' => PaymentTransaction::REFUND]),
            'wrong original status' => DB::table('payment_transactions')->where('id', $fixture['transaction']->id)->update(['status' => PaymentTransaction::RECEIVED]),
            'wrong original method' => DB::table('payment_transactions')->where('id', $fixture['transaction']->id)->update(['payment_method' => 'card']),
            'missing A1 history' => DB::table('payment_submission_histories')->where('payment_submission_id', $fixture['submission']->id)->where('event', PaymentSubmissionHistory::REFUND_MARKED_PENDING)->delete(),
            'wrong A1 history' => DB::table('payment_submission_histories')->where('payment_submission_id', $fixture['submission']->id)->where('event', PaymentSubmissionHistory::REFUND_MARKED_PENDING)->update(['safe_metadata' => '{}']),
            'duplicate A1 history' => $this->duplicateHistory($fixture, PaymentSubmissionHistory::REFUND_MARKED_PENDING),
            'premature final history' => $this->duplicateHistory($fixture, PaymentSubmissionHistory::REFUND_MARKED_PENDING, PaymentSubmissionHistory::REFUND_COMPLETED),
            'previous refund transaction' => $this->priorAccounting($fixture),
            'crossed accounting' => $refund->forceFill(['refund_payment_transaction_id' => $this->priorAccounting($this->pendingFixture())->id])->save(),
            'duplicate refund transaction' => [$this->priorAccounting($fixture), $this->priorAccounting($fixture)],
        };
        $this->assertConflictUnchanged($fixture, fn () => app(TreasuryPaymentRefundService::class)->complete($refund->id, $fixture['treasury']->id, 'fixture-ref'));
    }

    public static function incompatibleCompletionStates(): array
    {
        return array_map(fn ($state) => [$state], ['failed', 'completed partial', 'missing original', 'partial timestamp', 'partial reference', 'no requester', 'wrong refund amount', 'order not canceled', 'order delivered', 'order picked up', 'order not pending refund', 'submission not pending refund', 'wrong original amount', 'wrong original type', 'wrong original status', 'wrong original method', 'missing A1 history', 'wrong A1 history', 'duplicate A1 history', 'premature final history', 'previous refund transaction', 'crossed accounting', 'duplicate refund transaction']);
    }

    /** @dataProvider completionWriteStages */
    public function test_failure_after_each_write_rolls_back_the_entire_completion(string $stage): void
    {
        $fixture = $this->pendingFixture();
        $service = $this->completionProbe($stage, new RuntimeException('Completion rollback probe.'));
        $before = $this->snapshot($fixture);
        try {
            $service->complete($fixture['refund']->id, $fixture['treasury']->id, 'fixture-ref');
            $this->fail('The induced failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Completion rollback probe.', $exception->getMessage());
        }
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
    }

    public static function completionWriteStages(): array
    {
        return array_map(fn ($stage) => [$stage], ['accounting', 'refund', 'order', 'submission', 'history']);
    }

    public function test_nonconcurrent_sql_error_propagates_without_retry_or_writes(): void
    {
        $fixture = $this->pendingFixture();
        $failure = new QueryException('completion probe', [], new PDOException('synthetic SQL failure', 9999));
        $service = $this->completionProbe('accounting', $failure);
        $before = $this->snapshot($fixture);
        try {
            $service->complete($fixture['refund']->id, $fixture['treasury']->id, 'fixture-ref');
            $this->fail('The SQL exception must propagate.');
        } catch (QueryException $caught) {
            $this->assertTrue($caught === $failure, 'The original SQL exception must propagate privately.');
        }
        $this->assertSame(1, $service->attempts);
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
    }

    public function test_recognized_retry_rehydrates_every_model_and_rolls_back_partial_writes(): void
    {
        $fixture = $this->pendingFixture();
        $service = $this->completionProbe('refund', new QueryException('completion retry probe', [], new PDOException('database is locked', 40001)), false);
        $before = $this->protectedSnapshot($fixture);
        $refund = $service->complete($fixture['refund']->id, $fixture['treasury']->id, 'fixture-ref');
        $this->assertSame(2, $service->attempts);
        foreach (['order', 'originalTransaction', 'actor'] as $field) {
            $this->assertFalse($service->hydrations[0][$field] === $service->hydrations[1][$field], 'Retry must hydrate fresh models.');
        }
        $this->assertSame(PaymentRefund::COMPLETED, $refund->status);
        $this->assertSame(1, PaymentTransaction::where('transaction_type', PaymentTransaction::REFUND)->count());
        $this->assertSame(1, PaymentSubmissionHistory::where('event', PaymentSubmissionHistory::REFUND_COMPLETED)->count());
        $this->assertSnapshotUnchanged($before, $this->protectedSnapshot($fixture));
    }

    public function test_persistent_concurrency_failure_exhausts_exactly_three_attempts(): void
    {
        $fixture = $this->pendingFixture();
        $failure = new QueryException('completion retry probe', [], new PDOException('database is locked', 40001));
        $service = $this->completionProbe('accounting', $failure);
        $before = $this->snapshot($fixture);
        try {
            $service->complete($fixture['refund']->id, $fixture['treasury']->id, 'fixture-ref');
            $this->fail('The exhausted exception must propagate.');
        } catch (QueryException $caught) {
            $this->assertTrue($caught === $failure, 'The exhausted error must remain private.');
        }
        $this->assertSame(3, $service->attempts);
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
    }

    public function test_unique_collision_recovery_does_not_repair_a_partial_completion(): void
    {
        $fixture = $this->pendingFixture();
        $previous = new PDOException('UNIQUE constraint failed: payment_transactions.idempotency_key', 19);
        $previous->errorInfo = ['23000', 19, 'UNIQUE constraint failed: payment_transactions.idempotency_key'];
        $service = $this->completionProbe('accounting', new QueryException('completion unique probe', [], $previous));
        $this->assertConflictUnchanged($fixture, fn () => $service->complete($fixture['refund']->id, $fixture['treasury']->id, 'fixture-ref'));
    }

    public function test_completed_state_must_remain_complete_and_private_on_retry(): void
    {
        $fixture = $this->pendingFixture();
        $service = app(TreasuryPaymentRefundService::class);
        $refund = $service->complete($fixture['refund']->id, $fixture['treasury']->id, 'fixture-ref');
        $this->duplicateHistory($fixture, PaymentSubmissionHistory::REFUND_COMPLETED);
        $this->assertConflictUnchanged($fixture, fn () => $service->complete($refund->id, $fixture['treasury']->id, 'fixture-ref'));
    }

    private function pendingFixture(string $method = 'transferencia', bool $submission = true, bool $case = false): array
    {
        $fixture = $this->fixture($method, $submission, $case);
        if ($method === 'card') {
            DB::table('payment_transactions')->where('id', $fixture['transaction']->id)->update(['gateway' => 'mercadopago']);
        }
        $fixture['refund'] = app(TreasuryPaymentRefundService::class)->markPending($fixture['order'], $fixture['treasury'], 'Solicitud total de prueba');

        return $fixture;
    }

    private function duplicateHistory(array $fixture, string $event, ?string $replacement = null): void
    {
        $attributes = PaymentSubmissionHistory::where('payment_submission_id', $fixture['submission']->id)->where('event', $event)->firstOrFail()->getAttributes();
        unset($attributes['id']);
        $attributes['event'] = $replacement ?? $event;
        DB::table('payment_submission_histories')->insert($attributes);
    }

    private function priorAccounting(array $fixture): PaymentTransaction
    {
        return PaymentTransaction::forceCreate([
            'order_id' => $fixture['order']->id, 'payment_method' => $fixture['order']->payment_method,
            'gateway' => 'manual', 'transaction_type' => PaymentTransaction::REFUND, 'status' => PaymentTransaction::APPROVED,
            'amount' => '50.00', 'currency' => 'PEN', 'idempotency_key' => bin2hex(random_bytes(16)),
        ]);
    }

    private function assertConflictUnchanged(array $fixture, callable $operation): void
    {
        $before = $this->snapshot($fixture);
        try {
            $operation();
            $this->fail('An incompatible refund must fail without writes.');
        } catch (PaymentRefundConflictException $exception) {
            $this->assertSame('La operación de reembolso no es compatible con el estado actual.', $exception->getMessage());
        }
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
    }

    private function protectedSnapshot(array $fixture): array
    {
        $tables = ['inventory_reservations', 'warehouse_inventories', 'products', 'inventory_movements', 'order_items', 'order_fulfillment_histories', 'order_handling_processes', 'order_handling_items', 'order_handling_incidents', 'order_handling_histories', 'order_deliveries', 'order_delivery_histories', 'order_delivery_attempts', 'payment_resolution_cases', 'payment_resolution_histories'];
        $parts = [];
        foreach ($tables as $table) {
            $parts[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $parts['original'] = (array) DB::table('payment_transactions')->where('id', $fixture['transaction']->id)->first();
        $order = (array) DB::table('orders')->where('id', $fixture['order']->id)->first();
        unset($order['payment_status'], $order['updated_at']);
        $parts['order'] = $order;

        // No confidential value or digest is sent to PHPUnit's diff printer.
        return ['public' => [], 'confidential_digest' => hash('sha256', json_encode($this->canonicalize($parts), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))];
    }

    private function b2Evidence(array $fixture, bool $context): bool
    {
        return PaymentReassignmentFinalState::isCoherent(
            $fixture['order']->fresh(), $fixture['submission']->fresh(), $fixture['transaction']->fresh(), $fixture['case']->fresh(),
            $fixture['submission']->histories()->get(), $fixture['case']->histories()->get(), $fixture['order']->fulfillmentHistory()->get(), 1, $context,
        );
    }

    private function completionProbe(string $stage, \Throwable $failure, bool $always = true): TreasuryPaymentRefundService
    {
        return new class(app(InventoryBalanceLockService::class), app(TreasuryPaymentService::class), $stage, $failure, $always) extends TreasuryPaymentRefundService
        {
            public int $attempts = 0;

            public array $hydrations = [];

            public function __construct(InventoryBalanceLockService $locks, TreasuryPaymentService $treasury, private string $stage, private \Throwable $failure, private bool $always)
            {
                parent::__construct($locks, $treasury);
            }

            protected function afterCompletionFinancialStateLocked(array $state): void
            {
                $this->attempts++;
                $this->hydrations[] = $state;
            }

            protected function afterRefundAccountingCreated(PaymentTransaction $transaction): void
            {
                $this->failAt('accounting');
            }

            protected function afterRefundCompleted(PaymentRefund $refund): void
            {
                $this->failAt('refund');
            }

            protected function afterOrderRefunded(Order $order): void
            {
                $this->failAt('order');
            }

            protected function afterSubmissionRefunded(PaymentSubmission $submission): void
            {
                $this->failAt('submission');
            }

            protected function afterRefundCompletionHistoryCreated(PaymentSubmission $submission): void
            {
                $this->failAt('history');
            }

            private function failAt(string $stage): void
            {
                if ($this->stage === $stage && ($this->always || $this->attempts === 1)) {
                    throw $this->failure;
                }
            }
        };
    }
}
