<?php

namespace Tests\Feature;

use App\Http\Resources\TreasuryPaymentRefundWorklistResource;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\TreasuryPaymentRefundAvailabilityService;
use App\Services\TreasuryPaymentRefundService;
use App\Services\TreasuryPaymentRefundWorklistService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class TreasuryPaymentRefundWorklistHttpTest extends TreasuryPaymentRefundServiceTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'logging.default' => 'null']);
    }

    /** @dataProvider readActors */
    public function test_read_authorization_precedes_all_financial_lookups(?string $role, bool $active, string $endpoint): void
    {
        $f = $this->fixture();
        if ($role !== null) {
            $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => $active]), 'sanctum');
        }
        $original = $this->app->make(TreasuryPaymentRefundWorklistService::class);
        $mock = \Mockery::mock(TreasuryPaymentRefundWorklistService::class);
        $mock->shouldNotReceive('paginate');
        $mock->shouldNotReceive('forOrder');
        $this->app->instance(TreasuryPaymentRefundWorklistService::class, $mock);
        $dispatcher = DB::connection()->getEventDispatcher();
        $probe = clone $dispatcher;
        $lookups = 0;
        $probe->listen(\Illuminate\Database\Events\QueryExecuted::class, function ($query) use (&$lookups): void {
            $lookups += (int) preg_match('/\b(?:orders|payment_refunds)\b/i', $query->sql);
        });
        DB::connection()->setEventDispatcher($probe);
        try {
            $urls = $endpoint === 'list' ? ['/api/v1/treasury/refund-worklist?order='.$f['order']->id, '/api/v1/treasury/refund-worklist?order=999999999']
                : ['/api/v1/treasury/orders/'.$f['order']->id.'/refund-state', '/api/v1/treasury/orders/999999999/refund-state'];
            $a = $this->getJson($urls[0])->assertStatus($role === null ? 401 : 403);
            $b = $this->getJson($urls[1])->assertStatus($role === null ? 401 : 403);
            $this->assertTrue($a->getContent() === $b->getContent(), 'Unauthorized replies must not reveal existence.');
            $this->assertSame(0, $lookups);
        } finally {
            DB::connection()->setEventDispatcher($dispatcher);
            $this->app->instance(TreasuryPaymentRefundWorklistService::class, $original);
        }
    }

    public static function readActors(): array
    {
        $cases = [];
        foreach (['list', 'state'] as $endpoint) {
            foreach (['anonymous' => [null, true], 'customer' => ['customer', true], 'admin' => ['admin', true], 'inactive treasury' => ['treasury', false]] as $name => [$role, $active]) {
                $cases[$endpoint.' '.$name] = [$role, $active, $endpoint];
            }
        }

        return $cases;
    }

    public function test_raw_query_duplicates_and_invalid_structure_never_reach_service(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['treasury'], 'sanctum');
        $original = $this->app->make(TreasuryPaymentRefundWorklistService::class);
        $mock = \Mockery::mock(TreasuryPaymentRefundWorklistService::class);
        $mock->shouldNotReceive('paginate');
        $mock->shouldNotReceive('forOrder');
        $this->app->instance(TreasuryPaymentRefundWorklistService::class, $mock);
        $queries = [
            'refund_status=pending&refund_status=completed', 'page=1&page=2', 'per_page=10&per_page=20',
            'payment_method=transfer&payment_method=mercadopago', 'order_id=1&order_id=2',
            'created_from=2026-01-01&created_from=2026-02-01', 'created_to=2026-01-01&created_to=2026-02-01',
            'order=1&order=2', 'date_from=2026-01-01&date_from=2026-02-01', 'date_to=2026-01-01&date_to=2026-02-01',
            'refund_status=pending&%72efund_status=completed', 'pa%67e=1&pa%67%65=2',
            '%70%61%67%65=1&page=2', 'date_%66rom=2026-01-01&date_%66%72%6Fm=2026-02-01',
            'page=1&per_page=10&page=2', 'refund_status[]=pending&refund_status[]=completed',
            'refund_status=pending&refund_status[]=completed', 'refund_status%5B%5D=pending',
            '=pending', 'page', '&page=1', 'page=1&', 'page=1&&per_page=2',
            'pa+ge=1', 'pa%20ge=1', 'page%2520=1', 'refund_status=',
        ];
        try {
            foreach (['/api/v1/treasury/refund-worklist', '/api/v1/treasury/orders/'.$f['order']->id.'/refund-state'] as $url) {
                foreach ($queries as $query) {
                    $response = $this->getJson($url.'?'.$query);
                    $this->assertTrue($response->status() === 422, 'Malformed raw queries must be rejected before service invocation.');
                    $this->assertPrivate($response);
                    $this->assertTrue($response->json() === ['message' => 'Filtros inválidos.', 'errors' => ['request' => ['Revisa los filtros autorizados.']]], 'Validation must return only a constant safe envelope.');
                }
            }
        } finally {
            $this->app->instance(TreasuryPaymentRefundWorklistService::class, $original);
        }
    }

    /** @dataProvider readActors */
    public function test_raw_query_authorization_precedes_duplicate_validation(?string $role, bool $active, string $endpoint): void
    {
        $f = $this->fixture();
        if ($role !== null) {
            $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => $active]), 'sanctum');
        }
        $original = $this->app->make(TreasuryPaymentRefundWorklistService::class);
        $mock = \Mockery::mock(TreasuryPaymentRefundWorklistService::class);
        $mock->shouldNotReceive('paginate');
        $mock->shouldNotReceive('forOrder');
        $this->app->instance(TreasuryPaymentRefundWorklistService::class, $mock);
        try {
            $url = $endpoint === 'list' ? '/api/v1/treasury/refund-worklist' : '/api/v1/treasury/orders/'.$f['order']->id.'/refund-state';
            $response = $this->getJson($url.'?page=1&page=2');
            $this->assertTrue($response->status() === ($role === null ? 401 : 403), 'Authorization must precede query validation.');
        } finally {
            $this->app->instance(TreasuryPaymentRefundWorklistService::class, $original);
        }
    }

    public function test_raw_query_valid_filters_preserve_the_existing_contract(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['treasury'], 'sanctum');
        $url = '/api/v1/treasury/refund-worklist';
        $queries = ['', 'order='.$f['order']->id, 'payment_method=transferencia', 'refund_status=not_started',
            'date_from=2020-01-01', 'date_to=2099-01-01', 'page=1', 'per_page=10',
            'order=%23'.$f['order']->id, '%70age=1',
            'per_page=10&page=1&date_to=2099-01-01&date_from=2020-01-01&refund_status=not_started&payment_method=transferencia',
            'payment_method=transferencia&refund_status=not_started&date_from=2020-01-01&date_to=2099-01-01&page=1&per_page=10'];
        foreach ($queries as $query) {
            $response = $this->getJson($url.($query === '' ? '' : '?'.$query));
            $this->assertTrue($response->status() === 200, 'Valid raw query filters must remain accepted.');
            $this->assertPrivate($response);
        }
        $response = $this->getJson('/api/v1/treasury/orders/'.$f['order']->id.'/refund-state');
        $this->assertTrue($response->status() === 200, 'State reads without filters remain available.');
        $this->assertPrivate($response);
    }

    public function test_authorized_state_is_safe_and_available_after_each_real_command(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['treasury'], 'sanctum');
        foreach (['invalid', '0', '-1', '1.5', '1e2', str_repeat('9', 30), '999999999'] as $id) {
            $this->getJson('/api/v1/treasury/orders/'.$id.'/refund-state')->assertNotFound()->assertExactJson(['message' => 'El recurso no existe.']);
        }
        $url = '/api/v1/treasury/orders/'.$f['order']->id.'/refund-state';
        $list = '/api/v1/treasury/refund-worklist?order='.$f['order']->id;
        $before = $this->snapshot($f);
        $state = $this->getJson($url)->assertOk();
        $this->assertPrivate($state);
        $row = $state->json('data');
        $this->assertSafeShape($row);
        $this->assertTrue($row === $this->getJson($list)->assertOk()->json('data.0'), 'The list and state must expose the same public row.');
        $this->assertTrue($row['can_mark_refund_pending']);
        $this->assertSnapshotUnchanged($before, $this->snapshot($f));
        $refund = app(TreasuryPaymentRefundService::class)->markPending($f['order'], $f['treasury'], 'synthetic-private-history');
        $pending = $this->getJson($url)->assertOk();
        $this->assertPrivate($pending);
        $this->assertTrue($pending->json('data.can_complete_refund'));
        $this->assertFalse($pending->json('data.can_mark_refund_pending'));
        $this->assertSame(0, $this->getJson($list.'&refund_status=not_started')->assertOk()->json('meta.total'));
        app(TreasuryPaymentRefundService::class)->complete($refund->id, $f['treasury']->id, 'synthetic-private-completion');
        $completed = $this->getJson($url)->assertOk();
        $this->assertPrivate($completed);
        $this->assertFalse($completed->json('data.can_complete_refund'));
        $this->assertSame(PaymentRefund::COMPLETED, $completed->json('data.refund.status'));
        foreach ([$pending, $completed, $this->getJson($list)->assertOk()] as $response) {
            foreach (['synthetic-private-history', 'synthetic-private-completion'] as $value) {
                $this->assertFalse(str_contains($response->getContent(), $value), 'Private fixture values must not be serialized.');
            }
        }
        $f['order']->update(['status' => 'confirmed']);
        $this->getJson($url)->assertOk();
    }

    /** @dataProvider invalidReadFilters */
    public function test_read_filters_are_strict_and_never_echo_values(array $query): void
    {
        $f = $this->fixture();
        $this->actingAs($f['treasury'], 'sanctum');
        $before = $this->snapshot($f);
        $response = $this->getJson('/api/v1/treasury/refund-worklist?'.http_build_query($query))->assertUnprocessable();
        $this->assertPrivate($response);
        $this->assertSnapshotUnchanged($before, $this->snapshot($f));
        $this->assertFalse(str_contains($response->getContent(), 'synthetic-private'), 'Invalid values must not be echoed.');
    }

    public static function invalidReadFilters(): array
    {
        return ['extra' => [['reason' => 'synthetic-private']], 'nested' => [['order' => ['id' => 'synthetic-private']]], 'list' => [['payment_method' => ['card']]],
            'page zero' => [['page' => '0']], 'page array' => [['page' => ['1']]], 'page fraction' => [['page' => '1.5']],
            'page size' => [['per_page' => '21']], 'huge size' => [['per_page' => '99999']], 'method' => [['payment_method' => 'synthetic-private']],
            'status' => [['refund_status' => 'synthetic-private']], 'bad date' => [['date_from' => '2026-02-30']], 'reverse dates' => [['date_from' => '2026-02-02', 'date_to' => '2026-01-01']],
            'private search' => [['external_reference' => 'synthetic-private']], 'invalid order' => [['order' => 'synthetic-private']], 'zero order' => [['order' => '0']], 'huge order' => [['order' => str_repeat('9', 30)]]];
    }

    public function test_http_pagination_filters_and_resource_envelope_are_explicit(): void
    {
        $a = $this->fixture('card');
        $b = $this->fixture('card');
        $other = $this->fixture('contra_entrega');
        $this->actingAs($a['treasury'], 'sanctum');
        DB::table('orders')->whereIn('id', [$a['order']->id, $b['order']->id, $other['order']->id])->update(['created_at' => '2026-02-02 12:00:00']);
        $response = $this->getJson('/api/v1/treasury/refund-worklist?payment_method=card&date_from=2026-02-02&date_to=2026-02-02&per_page=1&page=2')->assertOk();
        $this->assertPrivate($response);
        $this->assertSame(['data', 'links', 'meta'], array_keys($response->json()));
        foreach (['current_page' => 2, 'last_page' => 2, 'per_page' => 1, 'total' => 2] as $key => $value) {
            $this->assertSame($value, $response->json('meta.'.$key));
        }
        $this->assertSame($b['order']->id, $response->json('data.0.order_id'));
        $this->assertSafeShape($response->json('data.0'));
        $this->assertSame(1, $this->getJson('/api/v1/treasury/refund-worklist?order=%23'.str_pad((string) $a['order']->id, 6, '0', STR_PAD_LEFT))->assertOk()->json('meta.total'));
        $this->getJson('/api/v1/treasury/refund-worklist?date_to=2026-02-02')->assertOk();
        $this->getJson('/api/v1/treasury/orders/'.$a['order']->id.'/refund-state?reason=synthetic-private')->assertUnprocessable();
        $fileResponse = $this->call('GET', '/api/v1/treasury/refund-worklist', [], [], ['upload' => UploadedFile::fake()->create('fixture.txt', 1)], ['HTTP_ACCEPT' => 'application/json']);
        $fileResponse->assertUnprocessable();
    }

    public function test_read_query_exception_reaches_safe_500_without_becoming_manual_review(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['treasury'], 'sanctum');
        $original = $this->app->make(TreasuryPaymentRefundWorklistService::class);
        $broken = new class(app(TreasuryPaymentRefundAvailabilityService::class), app(\App\Services\InventoryBalanceLockService::class)) extends TreasuryPaymentRefundWorklistService
        {
            protected function candidates(): Builder
            {
                return \App\Models\Order::query()->from('missing_synthetic_worklist_table');
            }
        };
        $this->app->instance(TreasuryPaymentRefundWorklistService::class, $broken);
        $before = $this->snapshot($f);
        try {
            $response = $this->getJson('/api/v1/treasury/refund-worklist')->assertStatus(500);
            $this->assertTrue($response->json() === ['message' => 'Server Error'], 'Unexpected database errors must remain generic.');
            foreach (['SQLSTATE', 'bindings', 'trace', 'missing_synthetic_worklist_table', 'synthetic-private'] as $private) {
                $this->assertFalse(str_contains($response->getContent(), $private), 'Error diagnostics must remain private.');
            }
        } finally {
            $this->app->instance(TreasuryPaymentRefundWorklistService::class, $original);
        }
        $this->assertSnapshotUnchanged($before, $this->snapshot($f));
        $this->assertTrue((int) DB::selectOne('SELECT 1 AS usable')->usable === 1);
    }

    /** @dataProvider controlledReadConflicts */
    public function test_read_conflicts_are_private_and_never_return_partial_data(string $mode): void
    {
        $f = $this->fixture();
        $this->actingAs($f['treasury'], 'sanctum');
        $original = $this->app->make(TreasuryPaymentRefundWorklistService::class);
        $service = $original;
        if ($mode === 'unstable') {
            $service = new class(app(TreasuryPaymentRefundAvailabilityService::class), app(\App\Services\InventoryBalanceLockService::class), (int) $f['order']->id) extends TreasuryPaymentRefundWorklistService
            {
                public int $attempts = 0;

                public function __construct($availability, $locks, private int $orderId)
                {
                    parent::__construct($availability, $locks);
                }

                protected function afterClassification(int $attempt, int $frontier): void
                {
                    $this->attempts++;
                    DB::table('orders')->where('id', $this->orderId)->update(['tracking_status' => $attempt % 2 ? 'confirmed' : 'canceled']);
                }
            };
        } else {
            $count = $mode === 'candidates' ? 201 : 101;
            for ($i = 0; $i < $count; $i++) {
                \App\Models\Order::create(['user_id' => $f['customer']->id, 'status' => 'confirmed', 'total' => '50.00', 'shipping_info' => ['address' => 'Fixture'], 'payment_method' => 'card', 'payment_status' => 'refund_pending']);
            }
        }
        $this->app->instance(TreasuryPaymentRefundWorklistService::class, $service);
        try {
            $query = $mode === 'unstable' ? 'refund_status=not_started' : 'payment_method=card&refund_status='.($mode === 'candidates' ? 'pending' : 'manual_review');
            $response = $this->getJson('/api/v1/treasury/refund-worklist?'.$query);
            $this->assertTrue($response->status() === 409, 'A controlled read conflict must remain a conflict.');
            $this->assertTrue($response->json() === ['message' => 'No se pudo obtener la lectura. Ajusta los filtros e inténtalo nuevamente.', 'code' => 'payment_refund_read_conflict'], 'Read conflicts must omit all partial data, totals and internal evidence.');
            $this->assertPrivate($response);
            if ($mode === 'unstable') {
                $this->assertSame(3, $service->attempts);
            }
        } finally {
            $this->app->instance(TreasuryPaymentRefundWorklistService::class, $original);
        }
    }

    public static function controlledReadConflicts(): array
    {
        return ['unstable' => ['unstable'], 'candidates' => ['candidates'], 'matches' => ['matches']];
    }

    private function assertPrivate($response): void
    {
        $cache = $response->headers->get('Cache-Control');
        $this->assertTrue(str_contains($cache, 'no-store') && str_contains($cache, 'private'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
    }

    private function assertSafeShape(array $row): void
    {
        $expected = ['order_id', 'order_number', 'order_payment_status', 'original_payment_method', 'amount', 'currency', 'refund', 'can_mark_refund_pending', 'can_complete_refund', 'requires_manual_review', 'relevant_at', 'message'];
        $keys = array_keys($row);
        sort($keys);
        sort($expected);
        $this->assertTrue($keys === $expected, 'Only public read fields may be serialized.');
        $forbidden = ['reason', 'external_reference', 'metadata', 'fingerprint', 'idempotency_key', 'approved_scope_key', 'provider_payment_id', 'payment_preference_id', 'manual_reference', 'actor_id', 'completed_by', 'payment_resolution_case_id', 'original_payment_transaction_id', 'refund_payment_transaction_id', 'email', 'phone', 'address'];
        $walk = function (array $data) use (&$walk, $forbidden): bool {
            foreach ($data as $key => $value) {
                if (in_array($key, $forbidden, true)) {
                    return false;
                } if (is_array($value) && ! $walk($value)) {
                    return false;
                }
            }

            return true;
        };
        $this->assertTrue($walk($row), 'Private keys must remain absent recursively.');
        $service = app(TreasuryPaymentRefundWorklistService::class);
        $prepared = $service->forOrder($row['order_id']);
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $probe = clone $dispatcher;
        $queries = 0;
        $probe->listen(\Illuminate\Database\Events\QueryExecuted::class, function () use (&$queries): void {
            $queries++;
        });
        $connection->setEventDispatcher($probe);
        try {
            (new TreasuryPaymentRefundWorklistResource($prepared))->resolve(Request::create('/'));
            $this->assertSame(0, $queries);
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }
    }
}
