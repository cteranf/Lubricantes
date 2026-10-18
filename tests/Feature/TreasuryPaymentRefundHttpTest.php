<?php

namespace Tests\Feature;

use App\Http\Middleware\TrimStrings;
use App\Models\PaymentRefund;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\InventoryBalanceLockService;
use App\Services\TreasuryPaymentRefundService;
use App\Services\TreasuryPaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Shares certified fixtures and private-safe snapshots, including A1 regressions. */
class TreasuryPaymentRefundHttpTest extends TreasuryPaymentRefundServiceTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'logging.default' => 'null']);
    }

    public function test_trim_exception_matches_only_exact_post_refund_paths_without_request_state(): void
    {
        $cases = [];
        foreach (['/api/v1/treasury/orders/%s/refunds', '/api/v1/treasury/payment-refunds/%s/complete'] as $pattern) {
            foreach (['1', 'not-numeric'] as $id) {
                $path = sprintf($pattern, $id);
                $cases[] = ['POST', $path, true];
                $cases[] = ['POST', $path.'?probe=1', true];
                foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $method) {
                    $cases[] = [$method, $path, false];
                }
            }
        }
        $otherPaths = [
            '/api/v1/treasury/orders/1/extra/refunds',
            '/api/v1/treasury/orders/1/refunds/extra',
            '/api/v1/treasury/orders//refunds',
            '/api/v1/treasury/orders/refunds',
            '/api/v1/treasury/payment-refunds/1/extra/complete',
            '/api/v1/treasury/payment-refunds/1/complete/extra',
            '/api/v1/treasury/payment-refunds//complete',
            '/api/v1/treasury/payment-refunds/complete',
            '/api/v2/treasury/orders/1/refunds',
            '/api/v1/other/payment-refunds/1/complete',
            '/api/v1/auth/login',
            '/api/v1/profile',
        ];
        foreach ($otherPaths as $path) {
            foreach (['POST', 'GET', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $cases[] = [$method, $path, false];
            }
        }
        $middleware = new TrimStrings;
        $raw = " \tFixture\n ";
        // Reuse one instance, then reverse the cases to detect leaked request state.
        foreach (array_merge($cases, array_reverse($cases)) as [$method, $path, $preserve]) {
            $request = Request::create($path, $method, ['reason' => $raw, 'external_reference' => $raw]);
            $result = $middleware->handle($request, fn ($request) => $request->only(['reason', 'external_reference']));
            foreach (['reason', 'external_reference'] as $field) {
                $this->assertTrue($result[$field] === ($preserve ? $raw : 'Fixture'), 'The exact method/path boundary must preserve the expected normalization.');
            }
        }
    }

    /** @dataProvider httpUnauthorizedActors */
    public function test_http_authorization_precedes_resource_lookup(string $action, ?string $role, bool $active): void
    {
        $fixture = $this->fixture();
        $refund = app(TreasuryPaymentRefundService::class)->markPending($fixture['order'], $fixture['treasury'], 'Fixture request');
        $service = \Mockery::mock(TreasuryPaymentRefundService::class);
        $service->shouldNotReceive('markPending');
        $service->shouldNotReceive('complete');
        $this->app->instance(TreasuryPaymentRefundService::class, $service);
        if ($role !== null) {
            $actor = User::factory()->create(['role' => $role, 'is_active' => $active]);
            $this->actingAs($actor, 'sanctum');
        }
        $lookups = 0;
        DB::listen(function ($query) use (&$lookups): void {
            if (preg_match('/\b(?:orders|payment_refunds)\b/i', $query->sql)) {
                $lookups++;
            }
        });
        $existingUrl = $action === 'pending' ? $this->pendingUrl($fixture) : $this->completeUrl($refund);
        $missingUrl = $action === 'pending' ? '/api/v1/treasury/orders/999999999/refunds' : '/api/v1/treasury/payment-refunds/999999999/complete';
        $body = $action === 'pending' ? ['reason' => 'Fixture request'] : ['external_reference' => 'fixture'];
        $existing = $this->postJson($existingUrl, $body)->assertStatus($role === null ? 401 : 403);
        $missing = $this->postJson($missingUrl, $body)->assertStatus($role === null ? 401 : 403);
        $this->assertSame($existing->json(), $missing->json());
        $this->assertSame(0, $lookups, 'Unauthorized requests must not query refund resources.');
    }

    public static function httpUnauthorizedActors(): array
    {
        $cases = [];
        foreach (['pending', 'complete'] as $action) {
            foreach (['anonymous' => [null, true], 'customer' => ['customer', true], 'admin' => ['admin', true], 'warehouse' => ['warehouse', true], 'inactive treasury' => ['treasury', false]] as $name => [$role, $active]) {
                $cases[$action.' '.$name] = [$action, $role, $active];
            }
        }

        return $cases;
    }

    /** @dataProvider httpAuthorizedActions */
    public function test_http_authorized_scalar_identifiers_are_safe_and_reach_the_service(string $action): void
    {
        $fixture = $this->fixture();
        $refund = $action === 'complete' ? app(TreasuryPaymentRefundService::class)->markPending($fixture['order'], $fixture['treasury'], 'Fixture request') : null;
        $this->actingAs($fixture['treasury'], 'sanctum');
        $body = $action === 'pending' ? ['reason' => 'Fixture request'] : ['external_reference' => 'fixture'];
        $lookups = 0;
        DB::listen(function ($query) use (&$lookups): void {
            if (preg_match('/\b(?:orders|payment_refunds)\b/i', $query->sql)) {
                $lookups++;
            }
        });
        foreach (['invalid', '0', '-1', '1.5', '1e2', '+1', str_repeat('9', 30)] as $id) {
            $url = $action === 'pending' ? '/api/v1/treasury/orders/'.$id.'/refunds' : '/api/v1/treasury/payment-refunds/'.$id.'/complete';
            $this->postJson($url, $body)->assertNotFound()->assertExactJson(['message' => 'El recurso no existe.']);
        }
        $this->assertSame(0, $lookups, 'Malformed IDs must fail before resource lookup.');
        $url = $action === 'pending' ? '/api/v1/treasury/orders/999999999/refunds' : '/api/v1/treasury/payment-refunds/999999999/complete';
        $this->postJson($url, $body)->assertNotFound()->assertExactJson(['message' => 'El recurso no existe.']);
        $this->assertSame(1, $lookups, 'A valid missing ID requires exactly one resource lookup.');
        $url = $action === 'pending' ? $this->pendingUrl($fixture) : $this->completeUrl($refund);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.status', $action === 'pending' ? 'pending' : 'completed');
    }

    public static function httpAuthorizedActions(): array
    {
        return ['pending' => ['pending'], 'complete' => ['complete']];
    }

    /** @dataProvider httpInvalidPayloads */
    public function test_http_rejects_invalid_payloads_without_writes(string $action, array $payload, bool $header = false, bool $file = false): void
    {
        $fixture = $this->fixture();
        $url = $this->pendingUrl($fixture);
        if ($action === 'complete') {
            $refund = app(TreasuryPaymentRefundService::class)->markPending($fixture['order'], $fixture['treasury'], 'Fixture request');
            $url = $this->completeUrl($refund);
        }
        $before = $this->snapshot($fixture);
        $this->actingAs($fixture['treasury'], 'sanctum');
        if ($file) {
            $response = $this->call('POST', $url, $payload, [], ['upload' => UploadedFile::fake()->create('fixture.txt', 1)], ['HTTP_ACCEPT' => 'application/json']);
        } else {
            // Form encoding also exercises invalid UTF-8 without JSON encoding it.
            $response = $this->call('POST', $url, $payload, [], [], array_merge(['HTTP_ACCEPT' => 'application/json'], $header ? ['HTTP_IDEMPOTENCY_KEY' => 'synthetic-client-key'] : []));
        }
        $response->assertUnprocessable();
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        $this->assertFalse(str_contains($response->getContent(), 'synthetic-client-key'), 'Private inputs must not be echoed.');
    }

    public static function httpInvalidPayloads(): array
    {
        $cases = [];
        foreach (['pending' => 'reason', 'complete' => 'external_reference'] as $action => $key) {
            foreach (['missing' => [], 'empty' => [$key => ''], 'blank' => [$key => '   '], 'array' => [$key => ['nested' => 'fixture']], 'extra' => [$key => 'fixture', 'actor_id' => 1], 'long' => [$key => str_repeat('a', $action === 'pending' ? 1001 : 256)]] as $name => $body) {
                $cases[$action.' '.$name] = [$action, $body];
            }
            $cases[$action.' header'] = [$action, [$key => 'fixture'], true];
            $cases[$action.' file'] = [$action, [$key => 'fixture'], false, true];
        }
        foreach (['newline' => "fixture\nnext", 'edge newline' => "\nfixture\n", 'tab' => "\tfixture", 'nul' => "fixture\0", 'unicode separator' => "fixture\u{2028}next", 'format control' => "\u{200B}fixture", 'invalid utf8' => "fixture\xFF", 'bytes' => str_repeat('é', 128)] as $name => $reference) {
            $cases['complete '.$name] = ['complete', ['external_reference' => $reference]];
        }

        return $cases;
    }

    /** @dataProvider httpPresentations */
    public function test_http_real_workflow_and_idempotent_retries_preserve_private_state(bool $submission, bool $case): void
    {
        $fixture = $this->fixture(withSubmission: $submission, withResolutionCase: $case);
        $inventory = $this->inventorySnapshot($fixture);
        $reason = 'synthetic-private-http-reason';
        $reference = '00-Ab.C/fixture:Z';
        $this->actingAs($fixture['treasury'], 'sanctum');
        $first = $this->postJson($this->pendingUrl($fixture), ['reason' => '  '.$reason.'  '])->assertOk();
        $this->assertHttpResource($first, 'pending', $reason, $reference);
        $refund = PaymentRefund::findOrFail($first->json('data.id'));
        $this->assertSame('refund_pending', $fixture['order']->fresh()->payment_status);
        $before = $this->snapshot($fixture);
        $this->postJson($this->pendingUrl($fixture), ['reason' => $reason])->assertOk()->assertJsonPath('data.id', $refund->id);
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        $completed = $this->postJson($this->completeUrl($refund), ['external_reference' => "\u{00A0}  ".$reference."  \u{00A0}"])->assertOk();
        $this->assertHttpResource($completed, 'completed', $reason, $reference);
        $refund->refresh();
        $this->assertTrue(hash_equals($reference, $refund->external_reference), 'Reference normalization must preserve content.');
        $this->assertSame('refunded', $fixture['order']->fresh()->payment_status);
        $this->assertSame(1, PaymentTransaction::where('order_id', $fixture['order']->id)->where('transaction_type', PaymentTransaction::REFUND)->count());
        $this->assertSame($submission ? 1 : 0, PaymentSubmissionHistory::where('event', PaymentSubmissionHistory::REFUND_COMPLETED)->count());
        $this->assertSame($submission ? 1 : 0, DB::table('payment_submissions')->count());
        $this->assertSame($case ? 1 : 0, DB::table('payment_resolution_cases')->count());
        if ($submission) {
            $history = PaymentSubmissionHistory::where('event', PaymentSubmissionHistory::REFUND_COMPLETED)->sole();
            $this->assertSame('refund_pending', $history->from_status);
            $this->assertSame('refunded', $history->to_status);
            $this->assertSame((int) $fixture['treasury']->id, (int) $history->actor_id);
            $this->assertSame(['amount' => '50.00', 'currency' => 'PEN'], $history->safe_metadata);
        }
        $before = $this->snapshot($fixture);
        $this->postJson($this->completeUrl($refund), ['external_reference' => $reference])->assertOk()->assertJsonPath('data.id', $refund->id);
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        $other = User::factory()->create(['role' => 'treasury', 'is_active' => true]);
        $this->actingAs($other, 'sanctum')->postJson($this->completeUrl($refund), ['external_reference' => $reference])->assertOk()->assertJsonPath('data.id', $refund->id);
        $this->assertSame((int) $fixture['treasury']->id, $refund->fresh()->completed_by);
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        $conflict = $this->postJson($this->completeUrl($refund), ['external_reference' => 'different-fixture'])->assertConflict();
        $this->assertHttpConflict($conflict);
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
        $this->assertSnapshotUnchanged($inventory, $this->inventorySnapshot($fixture));
    }

    public static function httpPresentations(): array
    {
        return ['without presentation' => [false, false], 'with presentation' => [true, false], 'with B2' => [true, true]];
    }

    /** @dataProvider httpConflictStates */
    public function test_http_financial_conflicts_do_not_repair_or_write(string $state): void
    {
        $fixture = $this->fixture(withSubmission: true, withResolutionCase: true);
        $url = $this->pendingUrl($fixture);
        $body = ['reason' => 'Fixture request'];
        if ($state === 'refund') {
            $refund = app(TreasuryPaymentRefundService::class)->markPending($fixture['order'], $fixture['treasury'], 'Fixture request');
            DB::table('payment_refunds')->where('id', $refund->id)->update(['amount' => '49.00']);
            $url = $this->completeUrl($refund);
            $body = ['external_reference' => 'fixture'];
        } elseif ($state === 'payment') {
            DB::table('payment_transactions')->where('id', $fixture['transaction']->id)->update(['status' => 'received']);
        } elseif ($state === 'order') {
            $fixture['order']->update(['status' => 'pending']);
        } elseif ($state === 'partial') {
            DB::table('payment_transactions')->where('id', $fixture['transaction']->id)->update(['amount' => '49.00']);
        } elseif ($state === 'inventory') {
            // Deliberately corrupt only this synthetic row, before the request snapshot.
            $returnId = $fixture['return']->getKey();
            $affected = DB::table('inventory_movements')->where('id', $returnId)->delete();
            $this->assertTrue($affected === 1, 'Exactly one synthetic return must be removed.');
            $this->assertFalse(DB::table('inventory_movements')->where('id', $returnId)->exists(), 'The synthetic return must be absent.');
        } else {
            DB::table('payment_resolution_cases')->where('id', $fixture['case']->id)->update(['amount' => '49.00']);
        }
        $before = $this->snapshot($fixture);
        $response = $this->actingAs($fixture['treasury'], 'sanctum')->postJson($url, $body)->assertConflict();
        $this->assertHttpConflict($response);
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
    }

    public static function httpConflictStates(): array
    {
        return array_combine(['payment', 'order', 'partial', 'inventory', 'case', 'refund'], array_map(fn ($state) => [$state], ['payment', 'order', 'partial', 'inventory', 'case', 'refund']));
    }

    /** @dataProvider httpUnexpectedErrors */
    public function test_http_unexpected_errors_reach_the_global_safe_500(string $action, string $kind): void
    {
        $fixture = $this->fixture();
        $url = $this->pendingUrl($fixture);
        $payload = ['reason' => 'synthetic-private-http-reason'];
        if ($action === 'complete') {
            $refund = app(TreasuryPaymentRefundService::class)->markPending($fixture['order'], $fixture['treasury'], $payload['reason']);
            $url = $this->completeUrl($refund);
            $payload = ['external_reference' => 'synthetic-private-reference'];
        }
        $service = new class(app(InventoryBalanceLockService::class), app(TreasuryPaymentService::class), $kind) extends TreasuryPaymentRefundService
        {
            public function __construct($locks, $treasury, private string $kind)
            {
                parent::__construct($locks, $treasury);
            }

            protected function afterRefundCreated(PaymentRefund $refund): void
            {
                $this->failPrivately();
            }

            protected function afterRefundAccountingCreated(PaymentTransaction $transaction): void
            {
                $this->failPrivately();
            }

            private function failPrivately(): void
            {
                if ($this->kind === 'query') {
                    DB::select('SELECT * FROM missing_refund_http_fixture_table');
                }
                if ($this->kind === 'error') {
                    throw new \Error('synthetic-private-error');
                }
                throw new \RuntimeException('synthetic-private-runtime');
            }
        };
        $this->app->instance(TreasuryPaymentRefundService::class, $service);
        $before = $this->snapshot($fixture);
        $response = $this->actingAs($fixture['treasury'], 'sanctum')->postJson($url, $payload)->assertStatus(500);
        $response->assertExactJson(['message' => 'Server Error']);
        $this->assertSnapshotUnchanged($before, $this->snapshot($fixture));
    }

    public static function httpUnexpectedErrors(): array
    {
        $cases = [];
        foreach (['pending', 'complete'] as $action) {
            foreach (['query', 'runtime', 'error'] as $kind) {
                $cases[$action.' '.$kind] = [$action, $kind];
            }
        }

        return $cases;
    }

    private function pendingUrl(array $fixture): string
    {
        return '/api/v1/treasury/orders/'.$fixture['order']->id.'/refunds';
    }

    private function completeUrl(PaymentRefund $refund): string
    {
        return '/api/v1/treasury/payment-refunds/'.$refund->id.'/complete';
    }

    private function assertHttpConflict($response): void
    {
        $response->assertExactJson(['message' => 'No se pudo procesar el reembolso en su estado actual.', 'code' => 'payment_refund_conflict'])
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
    }

    private function assertHttpResource($response, string $status, string $reason, string $reference): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(['id', 'order_id', 'status', 'amount', 'currency', 'original_payment_method', 'created_at', 'completed_at', 'message', 'can_complete'], array_keys($response->json('data')));
        $response->assertJsonPath('data.status', $status)->assertJsonPath('data.amount', '50.00')->assertJsonPath('data.currency', 'PEN')->assertJsonPath('data.can_complete', $status === 'pending');
        $this->assertFalse(str_contains($response->getContent(), $reason), 'Private reason must not appear.');
        $this->assertFalse(str_contains($response->getContent(), $reference), 'Private reference must not appear.');
        $this->assertSame($status === 'pending', $response->json('data.completed_at') === null);
    }
}
