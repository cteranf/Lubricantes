<?php

namespace Tests\Feature;

use App\Services\TreasuryPaymentRefundAvailabilityService;
use App\Services\TreasuryPaymentRefundService;
use App\Services\TreasuryPaymentRefundWorklistService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;

class TreasuryPaymentRefundWorklistServiceTest extends TreasuryPaymentRefundServiceTest
{
    /** @dataProvider worklistMethods */
    public function test_worklist_batch_and_single_availability_agree_for_every_method(string $method, bool $submission, bool $case): void
    {
        $f = $this->fixture($method, $submission, $case);
        if ($method === 'card') {
            DB::table('payment_transactions')->where('id', $f['transaction']->id)->update(['gateway' => 'mercadopago']);
        }
        $service = app(TreasuryPaymentRefundWorklistService::class);
        foreach (['not_started', 'pending', 'completed'] as $status) {
            if ($status === 'pending') {
                $refund = app(TreasuryPaymentRefundService::class)->markPending($f['order'], $f['treasury'], 'Synthetic request');
            }
            if ($status === 'completed') {
                app(TreasuryPaymentRefundService::class)->complete($refund->id, $f['treasury']->id, 'synthetic-completion');
            }
            $availability = app(TreasuryPaymentRefundAvailabilityService::class);
            $single = $availability->forOrder($f['order']->id)->toArray();
            $batch = $availability->forOrders([$f['order']->id])[$f['order']->id]->toArray();
            $this->assertSame($single, $batch);
            $before = $this->snapshot($f);
            $row = $service->forOrder($f['order']->id);
            $this->assertSnapshotUnchanged($before, $this->snapshot($f));
            foreach ($single as $key => $value) {
                $this->assertSame($value, $row[$key]);
            }
            $this->assertSame('50.00', $row['amount']);
            $this->assertSame('PEN', $row['currency']);
            $this->assertSame($method, $row['original_payment_method']);
            $page = $service->paginate(['refund_status' => $status]);
            $this->assertSame(1, $page->total());
            $this->assertTrue($row === $page->items()[0], 'The read state and listed row must agree.');
        }
    }

    public static function worklistMethods(): array
    {
        return ['transfer presentation' => ['transferencia', true, false], 'historical transfer' => ['transferencia', false, false],
            'Mercado Pago' => ['card', false, false], 'COD' => ['contra_entrega', false, false], 'branch' => ['pago_en_sede', false, false], 'B2' => ['transferencia', true, true]];
    }

    public function test_worklist_batch_preserves_normal_exclusions_corruption_and_nullable_historical_identities(): void
    {
        $service = app(TreasuryPaymentRefundWorklistService::class);
        $normal = $this->fixture();
        $normal['order']->update(['status' => 'confirmed']);
        $deleted = DB::table('inventory_movements')->where('id', $normal['return']->id)->delete();
        $this->assertTrue($deleted === 1, 'Only the synthetic movement must change.');
        $this->assertFalse($service->forOrder($normal['order']->id)['requires_manual_review']);
        $this->assertFalse($service->forOrder($normal['order']->id)['can_mark_refund_pending']);
        $partial = $this->fixture(withSubmission: true, withResolutionCase: true);
        DB::table('payment_resolution_cases')->where('id', $partial['case']->id)->update(['resolved_at' => null]);
        $this->assertTrue($service->forOrder($partial['order']->id)['requires_manual_review']);
        $legacy = $this->fixture(withSubmission: true, withResolutionCase: true);
        $refund = app(TreasuryPaymentRefundService::class)->markPending($legacy['order'], $legacy['treasury'], 'Synthetic request');
        DB::table('payment_refunds')->where('id', $refund->id)->update(['original_payment_transaction_id' => null, 'payment_resolution_case_id' => $legacy['case']->id, 'idempotency_key' => 'synthetic-legacy-case']);
        $row = $service->forOrder($legacy['order']->id);
        $this->assertTrue($row['requires_manual_review']);
        $this->assertSame($refund->id, $row['refund']['id']);
        $this->assertNull($row['amount']);
        $this->assertFalse($row['can_complete_refund']);
        $ambiguous = $this->fixture();
        $other = $this->fixture(withSubmission: true, withResolutionCase: true);
        $r = app(TreasuryPaymentRefundService::class)->markPending($ambiguous['order'], $ambiguous['treasury'], 'Synthetic request');
        $r = app(TreasuryPaymentRefundService::class)->complete($r->id, $ambiguous['treasury']->id, 'synthetic-completion');
        DB::table('payment_refunds')->where('id', $r->id)->update(['original_payment_transaction_id' => null, 'payment_resolution_case_id' => $other['case']->id]);
        foreach ([$ambiguous, $other] as $f) {
            $row = $service->forOrder($f['order']->id);
            $this->assertTrue($row['requires_manual_review']);
            $this->assertNull($row['refund']);
            $this->assertNull($row['original_payment_method']);
        }
        $ids = array_map(fn ($f) => (int) $f['order']->id, [$normal, $partial, $legacy, $ambiguous, $other]);
        $availability = app(TreasuryPaymentRefundAvailabilityService::class);
        $batch = $availability->forOrders($ids);
        foreach ($ids as $id) {
            $this->assertSame($availability->forOrder($id)->toArray(), $batch[$id]->toArray());
        }
        $page = $service->paginate(['refund_status' => 'manual_review']);
        $this->assertSame(4, $page->total());
        foreach ($page->items() as $row) {
            $this->assertTrue($row['requires_manual_review']);
        }
    }

    public function test_worklist_filters_paginate_in_sql_with_exact_totals_and_stable_order(): void
    {
        $a = $this->fixture('card');
        $b = $this->fixture('contra_entrega');
        $c = $this->fixture('card');
        DB::table('orders')->whereIn('id', [$a['order']->id, $b['order']->id, $c['order']->id])->update(['created_at' => '2026-01-02 12:00:00']);
        $service = app(TreasuryPaymentRefundWorklistService::class);
        $page = $service->paginate(['payment_method' => 'card', 'date_from' => '2026-01-02', 'date_to' => '2026-01-02', 'per_page' => 1, 'page' => 2]);
        $this->assertSame(2, $page->total());
        $this->assertSame(2, $page->lastPage());
        $this->assertSame($c['order']->id, $page->items()[0]['order_id']);
        $this->assertSame(1, $service->paginate(['order' => '#'.str_pad((string) $b['order']->id, 6, '0', STR_PAD_LEFT)])->total());
        $this->assertSame(0, $service->paginate(['date_to' => '2026-01-01'])->total());
        $this->assertSame(20, $service->paginate([])->perPage());
    }

    public function test_worklist_query_budget_is_bounded_and_reads_are_pure_without_lazy_loading(): void
    {
        $fixtures = [];
        for ($i = 0; $i < 20; $i++) {
            $fixtures[] = $this->fixture();
        }
        $service = app(TreasuryPaymentRefundWorklistService::class);
        $before = $this->completeDigest();
        $level = DB::connection()->transactionLevel();
        $dispatcher = DB::connection()->getEventDispatcher();
        $probe = clone $dispatcher;
        $models = Model::getEventDispatcher();
        $modelProbe = clone $models;
        $queries = 0;
        $writes = 0;
        $locks = 0;
        $transactions = 0;
        $events = 0;
        $probe->listen(QueryExecuted::class, function ($event) use (&$queries, &$writes, &$locks): void {
            $queries++;
            $writes += (int) preg_match('/^\s*(insert|update|delete|alter|create|drop)\b/i', $event->sql);
            $locks += (int) preg_match('/\bfor\s+update\b/i', $event->sql);
        });
        foreach ([TransactionBeginning::class, TransactionCommitted::class, TransactionRolledBack::class] as $event) {
            $probe->listen($event, function () use (&$transactions): void {
                $transactions++;
            });
        }
        foreach (['creating', 'updating', 'deleting', 'saved'] as $event) {
            $modelProbe->listen('eloquent.'.$event.': *', function () use (&$events): void {
                $events++;
            });
        }
        DB::connection()->setEventDispatcher($probe);
        Model::setEventDispatcher($modelProbe);
        $lazy = Model::preventsLazyLoading();
        Model::preventLazyLoading(true);
        try {
            foreach ([1, 20, TreasuryPaymentRefundWorklistService::MAX_PER_PAGE] as $perPage) {
                $queries = 0;
                $page = $service->paginate(['per_page' => $perPage]);
                $this->assertSame($perPage, count($page->items()));
                $this->assertTrue($queries <= 24, 'Unfiltered pages must use a bounded batch query budget.');
            }
            $queries = 0;
            $page = $service->paginate(['refund_status' => 'not_started']);
            $this->assertSame(20, $page->total());
            $this->assertTrue($queries <= 48, 'One exact-filter batch and its page must remain bounded.');
            $this->assertSame(0, $writes);
            $this->assertSame(0, $locks);
            $this->assertSame(0, $transactions);
            $this->assertSame(0, $events);
            $this->assertSame($level, DB::connection()->transactionLevel());
        } finally {
            DB::connection()->setEventDispatcher($dispatcher);
            Model::setEventDispatcher($models);
            Model::preventLazyLoading($lazy);
        }
        $this->assertTrue(hash_equals($before, $this->completeDigest()), 'Complete private snapshots must remain unchanged.');
        $this->assertTrue((int) DB::selectOne('SELECT 1 AS usable')->usable === 1);
    }

    public function test_logical_completion_between_classification_and_materialization_restarts(): void
    {
        $f = $this->fixture();
        $refund = app(TreasuryPaymentRefundService::class)->markPending($f['order'], $f['treasury'], 'Synthetic request');
        $attempts = [];
        $service = $this->readProbe(function (int $attempt) use ($f, $refund, &$attempts): void {
            $attempts[] = $attempt;
            if ($attempt === 1) {
                app(TreasuryPaymentRefundService::class)->complete($refund->id, $f['treasury']->id, 'synthetic-completion');
            }
        });
        $page = $service->paginate(['refund_status' => 'pending']);
        $this->assertSame(0, $page->total());
        $this->assertTrue($page->items() === [], 'A pending page must never return a completed row.');
        $this->assertTrue($attempts === [1, 2], 'The entire read must restart once.');
        $this->assertSame('completed', app(TreasuryPaymentRefundWorklistService::class)->forOrder($f['order']->id)['refund']['status']);
    }

    public function test_logical_read_exhausts_its_small_retry_budget_without_partial_results(): void
    {
        $f = $this->fixture();
        $attempts = [];
        $service = $this->readProbe(function (int $attempt) use ($f, &$attempts): void {
            $attempts[] = $attempt;
            DB::table('orders')->where('id', $f['order']->id)->update(['tracking_status' => $attempt % 2 ? 'confirmed' : 'canceled']);
        });
        try {
            $service->paginate(['refund_status' => 'not_started']);
            $this->fail('An unstable read must not return a page.');
        } catch (\App\Exceptions\PaymentRefundReadConflictException) {
            $this->assertTrue($attempts === [1, 2, 3], 'Only the fixed read-attempt budget is allowed.');
        }
    }

    public function test_frontier_excludes_new_candidates_and_multiple_batches_keep_sql_pagination(): void
    {
        $f = $this->fixture();
        $ids = $this->projectedOrders($f, 45);
        $inserted = null;
        $service = $this->readProbe(static function (): void {}, function () use ($f, &$inserted): void {
            $inserted = $this->projectedOrders($f, 1)[0];
        });
        $dispatcher = DB::connection()->getEventDispatcher();
        $probe = clone $dispatcher;
        $cursors = [];
        $inBound = true;
        $probe->listen(QueryExecuted::class, function ($q) use (&$cursors, &$inBound): void {
            if (str_contains($q->sql, ' > ?') && str_contains($q->sql, 'orders')) {
                $cursors[] = (int) $q->bindings[count($q->bindings) - 2];
            }
            preg_match_all('/\bin\s*\(([^()]*)\)/i', $q->sql, $lists);
            foreach ($lists[1] as $list) {
                if (substr_count($list, '?') > 20) {
                    $inBound = false;
                }
            }
        });
        DB::connection()->setEventDispatcher($probe);
        try {
            $page = $service->paginate(['payment_method' => 'card', 'refund_status' => 'manual_review', 'per_page' => 7, 'page' => 4]);
        } finally {
            DB::connection()->setEventDispatcher($dispatcher);
        }
        $this->assertSame(45, $page->total());
        $returned = array_column($page->items(), 'order_id');
        $this->assertTrue($returned === array_slice($ids, 21, 7), 'IDs and public states must belong to the definitive observation.');
        $this->assertTrue(! in_array($inserted, $returned, true), 'Post-frontier candidates must remain outside this read.');
        $this->assertTrue($inBound, 'Every IN lookup must remain bounded.');
        $expected = [0, $ids[19], $ids[39], $ids[44], 0, $ids[19], $ids[39], $ids[44]];
        $this->assertTrue($cursors === $expected, 'Each pass must advance strictly and terminate.');
        foreach ($page->items() as $row) {
            $this->assertTrue($row['requires_manual_review']);
        }
        $outside = app(TreasuryPaymentRefundWorklistService::class)->paginate(['payment_method' => 'card', 'refund_status' => 'manual_review', 'per_page' => 7, 'page' => 99]);
        $this->assertSame(46, $outside->total());
        $this->assertSame(7, $outside->lastPage());
        $this->assertTrue($outside->items() === [], 'An out-of-range page must preserve its exact total and empty data.');
    }

    /** @dataProvider readLimits */
    public function test_read_limits_never_return_a_partial_total(string $limit): void
    {
        $f = $this->fixture();
        $this->projectedOrders($f, $limit === 'candidates' ? 201 : 101);
        $before = $this->completeDigest();
        try {
            app(TreasuryPaymentRefundWorklistService::class)->paginate(['payment_method' => 'card', 'refund_status' => $limit === 'candidates' ? 'pending' : 'manual_review']);
            $this->fail('A bounded read must fail without returning partial pagination.');
        } catch (\App\Exceptions\PaymentRefundReadConflictException) {
            $this->assertTrue(hash_equals($before, $this->completeDigest()), 'Read limits must never repair or mutate fixtures.');
        }
    }

    public static function readLimits(): array
    {
        return ['candidates' => ['candidates'], 'matches' => ['matches']];
    }

    public function test_global_item_acquisition_ignores_mass_history_but_keeps_foreign_active_reservations(): void
    {
        $f = $this->fixture();
        $foreign = $this->addForeignActiveReservation($f);
        for ($i = 0; $i < 250; $i++) {
            \App\Models\OrderItem::forceCreate(['order_id' => $foreign['order']->id, 'product_id' => $f['product']->id, 'warehouse_id' => $foreign['warehouse']->id, 'quantity' => 1, 'price' => '25.00', 'subtotal' => '25.00']);
        }
        $dispatcher = DB::connection()->getEventDispatcher();
        $probe = clone $dispatcher;
        $queries = 0;
        $modelDispatcher = Model::getEventDispatcher();
        $modelProbe = clone $modelDispatcher;
        $itemReads = 0;
        $modelProbe->listen('eloquent.retrieved: App\\Models\\OrderItem', function () use (&$itemReads): void {
            $itemReads++;
        });
        $probe->listen(QueryExecuted::class, function () use (&$queries): void {
            $queries++;
        });
        DB::connection()->setEventDispatcher($probe);
        Model::setEventDispatcher($modelProbe);
        try {
            $evidence = new \App\Services\TreasuryPaymentRefundReadEvidence([$f['order']->id], app(\App\Services\InventoryBalanceLockService::class));
        } finally {
            DB::connection()->setEventDispatcher($dispatcher);
            Model::setEventDispatcher($modelDispatcher);
        }
        $items = (new \ReflectionProperty($evidence, 'globalItems'))->getValue($evidence);
        $this->assertSame(2, $items->count());
        $this->assertSame(2, $itemReads, 'Unreferenced historical items must never be hydrated.');
        $this->assertTrue($items->contains('id', $f['item']->id) && $items->contains('id', $foreign['item']->id), 'Only own and actively referenced items may be acquired.');
        $this->assertTrue($queries <= 24, 'Unreferenced history must not increase the acquisition query budget.');
        $availability = app(TreasuryPaymentRefundAvailabilityService::class);
        $this->assertSame($availability->forOrder($f['order']->id)->toArray(), $availability->forOrders([$f['order']->id], $evidence)[$f['order']->id]->toArray());
        $this->assertTrue(app(TreasuryPaymentRefundWorklistService::class)->forOrder($f['order']->id)['can_mark_refund_pending']);
        DB::table('inventory_reservations')->where('id', $foreign['reservation']->id)->update(['order_id' => $f['order']->id]);
        $this->assertTrue(app(TreasuryPaymentRefundWorklistService::class)->forOrder($f['order']->id)['requires_manual_review']);
    }

    /** @dataProvider evidenceLimits */
    public function test_evidence_row_limits_fail_privately_instead_of_loading_unbounded_rows(string $limit): void
    {
        $f = $this->fixture();
        if (in_array($limit, ['rows', 'queries'], true)) {
            $count = $limit === 'queries' ? 999 : 1000;
            for ($i = 0; $i < $count; $i++) {
                \App\Models\OrderItem::forceCreate(['order_id' => $f['order']->id, 'product_id' => $f['product']->id, 'warehouse_id' => $f['warehouse']->id, 'quantity' => 1, 'price' => '25.00', 'subtotal' => '25.00']);
            }
        } else {
            $foreign = $this->addForeignActiveReservation($f);
            for ($i = 0; $i < 200; $i++) {
                $item = \App\Models\OrderItem::forceCreate(['order_id' => $foreign['order']->id, 'product_id' => $f['product']->id, 'warehouse_id' => $foreign['warehouse']->id, 'quantity' => 1, 'price' => '25.00', 'subtotal' => '25.00']);
                \App\Models\InventoryReservation::forceCreate(['order_id' => $foreign['order']->id, 'order_item_id' => $item->id, 'product_id' => $f['product']->id, 'warehouse_id' => $foreign['warehouse']->id, 'quantity' => 1, 'reservation_sequence' => 1, 'status' => 'active', 'expires_at' => now()->addHour(), 'idempotency_key' => 'synthetic-evidence-'.$i]);
            }
        }
        $before = $this->completeDigest();
        $dispatcher = DB::connection()->getEventDispatcher();
        $probe = clone $dispatcher;
        $queries = 0;
        $probe->listen(QueryExecuted::class, function () use (&$queries): void {
            $queries++;
        });
        DB::connection()->setEventDispatcher($probe);
        try {
            app(TreasuryPaymentRefundWorklistService::class)->forOrder($f['order']->id);
            $this->fail('Evidence overflow must not return a partial row.');
        } catch (\App\Exceptions\PaymentRefundReadConflictException) {
            DB::connection()->setEventDispatcher($dispatcher);
            $this->assertTrue(hash_equals($before, $this->completeDigest()), 'Evidence overflow must not mutate the fixtures.');
            if ($limit === 'queries') {
                $this->assertSame(64, $queries, 'Acquisition must stop before its next query.');
            }
        } finally {
            DB::connection()->setEventDispatcher($dispatcher);
        }
    }

    public static function evidenceLimits(): array
    {
        return ['rows' => ['rows'], 'active' => ['active'], 'queries' => ['queries']];
    }

    private function projectedOrders(array $f, int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = \App\Models\Order::create(['user_id' => $f['customer']->id, 'status' => 'confirmed', 'total' => '50.00', 'shipping_info' => ['address' => 'Fixture'], 'payment_method' => 'card', 'payment_status' => 'refund_pending'])->id;
        }

        return $ids;
    }

    private function readProbe(\Closure $after, ?\Closure $frontier = null): TreasuryPaymentRefundWorklistService
    {
        return new class(app(TreasuryPaymentRefundAvailabilityService::class), app(\App\Services\InventoryBalanceLockService::class), $after, $frontier) extends TreasuryPaymentRefundWorklistService
        {
            public function __construct($availability, $locks, private \Closure $after, private ?\Closure $frontier)
            {
                parent::__construct($availability, $locks);
            }

            protected function afterClassification(int $attempt, int $frontier): void
            {
                ($this->after)($attempt);
            }

            protected function afterFrontierCaptured(int $attempt, int $frontier): void
            {
                if ($this->frontier !== null) {
                    ($this->frontier)();
                }
            }
        };
    }

    private function completeDigest(): string
    {
        $rows = [];
        foreach (['users', 'branches', 'warehouses', 'products', 'orders', 'order_items', 'inventory_reservations', 'inventory_movements', 'warehouse_inventories', 'order_fulfillment_histories', 'order_handling_processes', 'order_handling_items', 'order_handling_incidents', 'order_handling_histories', 'order_deliveries', 'order_delivery_attempts', 'order_delivery_histories', 'payment_transactions', 'payment_submissions', 'payment_submission_histories', 'payment_refunds', 'payment_resolution_cases', 'payment_resolution_histories'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return hash('sha256', json_encode($this->canonicalize($rows), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
