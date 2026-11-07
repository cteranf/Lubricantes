<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditSanitizer;
use App\Services\AuditService;
use App\Support\AuditEventContract;
use App\Support\AuditEventData;
use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PDOException;
use Tests\Feature\Concerns\SyntheticMercadoPagoLink;
use Tests\TestCase;

class AdminAuditLogViewerTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as protected refreshDatabaseAfterIsolation;
    }
    use SyntheticMercadoPagoLink;

    public function refreshDatabase()
    {
        $this->assertSyntheticDatabaseIsolation();
        $this->refreshDatabaseAfterIsolation();
    }

    public function test_admin_can_list_only_the_allowlisted_summary_and_access_is_logged_after_page_creation(): void
    {
        $admin = $this->admin();
        $this->event(['metadata' => ['command_name' => 'synthetic:viewer-list'], 'ip' => '192.0.2.123', 'user_agent' => 'private-agent-sentinel']);
        $before = AuditLog::count();

        $response = $this->asAdmin($admin)->getJson('/api/v1/admin/audit-logs');
        $response->assertOk();
        $row = $response->json('data.0');
        $this->assertSame([
            'id', 'occurred_at', 'actor_type', 'actor_id', 'action', 'category', 'result', 'failure_code',
            'auditable_type', 'auditable_id', 'route_name', 'http_method', 'request_id', 'correlation_id',
            'retention_category', 'created_at',
        ], array_keys($row));
        $this->assertSame(25, $response->json('meta.per_page'));
        $this->assertSame($before, count($response->json('data')));
        $this->assertFalse(str_contains($response->getContent(), 'synthetic:viewer-list'));
        $this->assertFalse(str_contains($response->getContent(), 'private-agent-sentinel'));
        $this->assertFalse(str_contains($response->getContent(), 'ip_address'));
        $this->assertSame(1, AuditLog::where('action', AuditEventContract::AUDIT_LOGS_VIEWED)->count());
        $access = AuditLog::where('action', AuditEventContract::AUDIT_LOGS_VIEWED)->firstOrFail();
        $this->assertCount(3, $access->metadata);
        $this->assertSame('list', $access->metadata['scope']);
        $this->assertSame(1, $access->metadata['page']);
        $this->assertSame(25, $access->metadata['per_page']);
        $this->assertSame($before + 1, AuditLog::count());
        $this->assertSame(0, \DB::table('payment_transactions')->count());
        $this->assertSame(0, \DB::table('inventory_movements')->count());
    }

    public function test_admin_can_read_sanitized_detail_and_current_actor_name_never_exposes_email(): void
    {
        $admin = $this->admin();
        $actor = User::factory()->create(['name' => 'Synthetic Actor Name', 'email' => 'private-actor@example.test']);
        $entry = $this->event([
            'actor_type' => AuditEventContract::ACTOR_USER,
            'actor_id' => $actor->id,
            'action' => AuditEventContract::USER_CREATED,
            'category' => AuditEventContract::CATEGORY_USER_SECURITY,
            'auditable_type' => 'user',
            'auditable_id' => (string) $actor->id,
            'old_values' => null,
            'new_values' => ['role' => 'customer', 'is_active' => true],
            'ip' => '198.51.100.45',
            'user_agent' => 'never-return-this-agent',
        ]);
        $before = AuditLog::count();

        $response = $this->asAdmin($admin)->getJson('/api/v1/admin/audit-logs/'.$entry->id)->assertOk();
        $detail = $response->json('data');
        $this->assertSame((string) $entry->id, $detail['id']);
        $this->assertSame(['role' => 'customer', 'is_active' => true], $detail['new_values']);
        $this->assertNull($detail['old_values']);
        $this->assertSame('198.51.100.0', $detail['ip_address']);
        $this->assertArrayNotHasKey('user_agent', $detail);
        $this->assertSame(['id', 'name'], array_keys($detail['actor']));
        $this->assertSame('Synthetic Actor Name', $detail['actor']['name']);
        $this->assertStringContainsString('corresponde al usuario actual', $response->json('actor_name_semantics'));
        $this->assertFalse(str_contains($response->getContent(), 'private-actor@example.test'));
        $this->assertFalse(str_contains($response->getContent(), 'never-return-this-agent'));
        $this->assertSame($before + 1, AuditLog::count());
        $this->assertSame(1, AuditLog::where('action', AuditEventContract::AUDIT_LOG_VIEWED)->count());
        $access = AuditLog::where('action', AuditEventContract::AUDIT_LOG_VIEWED)->firstOrFail();
        $this->assertCount(2, $access->metadata);
        $this->assertSame('detail', $access->metadata['scope']);
        $this->assertSame((string) $entry->id, $access->metadata['audit_log_id']);
        $this->assertSame(0, \DB::table('payment_transactions')->count());
        $this->assertSame(0, \DB::table('payment_webhook_events')->count());
    }

    public function test_guest_customer_and_treasury_cannot_access_the_viewer(): void
    {
        $this->getJson('/api/v1/admin/audit-logs')->assertUnauthorized();
        $this->assertSame(0, AuditLog::count());

        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $this->asAdmin($customer)->getJson('/api/v1/admin/audit-logs')->assertForbidden();
        $this->assertSame(1, AuditLog::where('action', AuditEventContract::SECURITY_ACCESS_DENIED)->count());
        $this->assertSame(0, AuditLog::whereIn('action', [AuditEventContract::AUDIT_LOGS_VIEWED, AuditEventContract::AUDIT_LOG_VIEWED])->count());

        $treasury = User::factory()->create(['role' => 'treasury', 'is_active' => true]);
        $this->asAdmin($treasury)->getJson('/api/v1/admin/audit-logs')->assertForbidden();
        $this->assertSame(2, AuditLog::where('action', AuditEventContract::SECURITY_ACCESS_DENIED)->count());
        $this->assertSame(0, AuditLog::whereIn('action', [AuditEventContract::AUDIT_LOGS_VIEWED, AuditEventContract::AUDIT_LOG_VIEWED])->count());
    }

    public function test_pagination_is_bounded_and_order_is_stable_for_equal_timestamps(): void
    {
        $admin = $this->admin();
        Carbon::setTestNow('2026-11-07 12:00:00');
        try {
            $entries = [];
            for ($index = 0; $index < 105; $index++) {
                $entries[] = $this->event();
            }
            $response = $this->asAdmin($admin)->getJson('/api/v1/admin/audit-logs')->assertOk();
            $this->assertSame(25, $response->json('meta.per_page'));
            $this->assertCount(25, $response->json('data'));
            $this->assertSame(array_reverse(array_slice(array_map(fn (AuditLog $row) => (string) $row->id, $entries), -25)), array_column($response->json('data'), 'id'));

            $bounded = $this->getJson('/api/v1/admin/audit-logs?per_page=100')->assertOk();
            $this->assertSame(100, $bounded->json('meta.per_page'));
            $this->assertCount(100, $bounded->json('data'));
            $this->getJson('/api/v1/admin/audit-logs?per_page=101')->assertUnprocessable();
            $this->assertSame(2, AuditLog::where('action', AuditEventContract::AUDIT_LOGS_VIEWED)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_date_from_filter_excludes_earlier_rows(): void
    {
        Carbon::setTestNow('2020-01-03 12:00:00');
        $target = $this->event();
        Carbon::setTestNow('2020-01-02 12:00:00');
        $decoy = $this->event();
        Carbon::setTestNow();

        $this->assertFilterReturnsOnlyTarget('date_from', '2020-01-03', $target, $decoy);
    }

    public function test_date_to_filter_excludes_later_rows(): void
    {
        Carbon::setTestNow('2020-01-03 12:00:00');
        $target = $this->event();
        Carbon::setTestNow('2020-01-04 12:00:00');
        $decoy = $this->event();
        Carbon::setTestNow();

        $this->assertFilterReturnsOnlyTarget('date_to', '2020-01-03', $target, $decoy);
    }

    public function test_date_filters_include_selected_day_boundaries_and_exclude_adjacent_midnights(): void
    {
        $timezone = new DateTimeZone((string) config('app.timezone', 'UTC'));
        $localTimestamp = static fn (string $value): string => DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone)->format('Y-m-d H:i:s');
        $selectedDate = '2020-01-03';
        $fixtureFilters = [
            'action' => AuditEventContract::SYSTEM_COMMAND_COMPLETED,
            'auditable_type' => 'order',
            'auditable_id' => 'synthetic-date-boundary',
        ];
        $previousDayLate = $this->event([...$fixtureFilters, 'occurred_at' => $localTimestamp('2020-01-02 23:59:59')]);
        $selectedDayStart = $this->event([...$fixtureFilters, 'occurred_at' => $localTimestamp('2020-01-03 00:00:00')]);
        $selectedDayEnd = $this->event([...$fixtureFilters, 'occurred_at' => $localTimestamp('2020-01-03 23:59:59')]);
        $nextDayStart = $this->event([...$fixtureFilters, 'occurred_at' => $localTimestamp('2020-01-04 00:00:00')]);

        $dateFrom = $this->asAdmin($this->admin())->getJson('/api/v1/admin/audit-logs?'.http_build_query([
            ...$fixtureFilters,
            'date_from' => $selectedDate,
        ]))->assertOk();
        $dateFromIds = array_column($dateFrom->json('data'), 'id');
        $this->assertContains((string) $selectedDayStart->id, $dateFromIds);
        $this->assertNotContains((string) $previousDayLate->id, $dateFromIds);
        $this->assertSame(3, $dateFrom->json('meta.total'));

        $dateTo = $this->getJson('/api/v1/admin/audit-logs?'.http_build_query([
            ...$fixtureFilters,
            'date_to' => $selectedDate,
        ]))->assertOk();
        $dateToIds = array_column($dateTo->json('data'), 'id');
        $this->assertContains((string) $selectedDayEnd->id, $dateToIds);
        $this->assertNotContains((string) $nextDayStart->id, $dateToIds);
        $this->assertSame(3, $dateTo->json('meta.total'));

        $oneDay = $this->getJson('/api/v1/admin/audit-logs?'.http_build_query([
            ...$fixtureFilters,
            'date_from' => $selectedDate,
            'date_to' => $selectedDate,
        ]))->assertOk();
        $this->assertSame(2, $oneDay->json('meta.total'));
        $this->assertSame([(string) $selectedDayEnd->id, (string) $selectedDayStart->id], array_column($oneDay->json('data'), 'id'));
        $this->assertNotContains((string) $previousDayLate->id, array_column($oneDay->json('data'), 'id'));
        $this->assertNotContains((string) $nextDayStart->id, array_column($oneDay->json('data'), 'id'));
    }

    public function test_action_filter_excludes_a_different_action(): void
    {
        $target = $this->event(['action' => AuditEventContract::USER_UPDATED]);
        $decoy = $this->event(['action' => AuditEventContract::USER_CREATED]);

        $this->assertFilterReturnsOnlyTarget('action', AuditEventContract::USER_UPDATED, $target, $decoy);
    }

    public function test_category_filter_excludes_another_category(): void
    {
        $target = $this->event(['action' => AuditEventContract::USER_UPDATED]);
        $decoy = $this->event();

        $this->assertFilterReturnsOnlyTarget('category', AuditEventContract::CATEGORY_USER_SECURITY, $target, $decoy);
    }

    public function test_result_filter_excludes_a_different_result(): void
    {
        $target = $this->event(['action' => AuditEventContract::AUTH_LOGIN_FAILED]);
        $decoy = $this->event(['action' => AuditEventContract::AUTH_LOGIN_SUCCEEDED]);

        $this->assertFilterReturnsOnlyTarget('result', AuditEventContract::RESULT_FAILED, $target, $decoy);
    }

    public function test_actor_type_filter_excludes_a_different_actor_type(): void
    {
        $target = $this->event(['actor_type' => AuditEventContract::ACTOR_COMMAND]);
        $decoy = $this->event(['actor_type' => AuditEventContract::ACTOR_SYSTEM]);

        $this->assertFilterReturnsOnlyTarget('actor_type', AuditEventContract::ACTOR_COMMAND, $target, $decoy);
    }

    public function test_actor_id_filter_excludes_a_different_actor(): void
    {
        $targetActor = User::factory()->create();
        $decoyActor = User::factory()->create();
        $target = $this->event(['actor_type' => AuditEventContract::ACTOR_USER, 'actor_id' => $targetActor->id, 'action' => AuditEventContract::USER_UPDATED]);
        $decoy = $this->event(['actor_type' => AuditEventContract::ACTOR_USER, 'actor_id' => $decoyActor->id, 'action' => AuditEventContract::USER_UPDATED]);

        $this->assertFilterReturnsOnlyTarget('actor_id', (string) $targetActor->id, $target, $decoy);
    }

    public function test_auditable_type_filter_excludes_a_different_entity_type(): void
    {
        $target = $this->event(['auditable_type' => 'order', 'auditable_id' => 'same-synthetic-id']);
        $decoy = $this->event(['auditable_type' => 'product', 'auditable_id' => 'same-synthetic-id']);

        $this->assertFilterReturnsOnlyTarget('auditable_type', 'order', $target, $decoy);
    }

    public function test_auditable_id_filter_excludes_a_different_entity_id(): void
    {
        $target = $this->event(['auditable_type' => 'order', 'auditable_id' => 'synthetic-target']);
        $decoy = $this->event(['auditable_type' => 'order', 'auditable_id' => 'synthetic-decoy']);

        $this->assertFilterReturnsOnlyTarget('auditable_id', 'synthetic-target', $target, $decoy);
    }

    public function test_request_id_filter_excludes_a_different_request(): void
    {
        $requestId = (string) Str::uuid();
        $target = $this->event(['request_id' => $requestId]);
        $decoy = $this->event(['request_id' => (string) Str::uuid()]);

        $this->assertFilterReturnsOnlyTarget('request_id', $requestId, $target, $decoy);
    }

    public function test_correlation_id_filter_excludes_a_different_correlation(): void
    {
        $correlationId = (string) Str::uuid();
        $target = $this->event(['correlation_id' => $correlationId]);
        $decoy = $this->event(['correlation_id' => (string) Str::uuid()]);

        $this->assertFilterReturnsOnlyTarget('correlation_id', $correlationId, $target, $decoy);
    }

    public function test_combined_filters_exclude_rows_that_match_only_part_of_the_query(): void
    {
        $actor = User::factory()->create();
        $otherActor = User::factory()->create();
        $target = $this->event([
            'actor_type' => AuditEventContract::ACTOR_USER,
            'actor_id' => $actor->id,
            'action' => AuditEventContract::USER_UPDATED,
            'auditable_type' => 'user',
            'auditable_id' => 'combined-target',
        ]);
        $sameActionAndActor = $this->event([
            'actor_type' => AuditEventContract::ACTOR_USER,
            'actor_id' => $actor->id,
            'action' => AuditEventContract::USER_UPDATED,
            'auditable_type' => 'user',
            'auditable_id' => 'combined-decoy-entity',
        ]);
        $sameActionAndEntity = $this->event([
            'actor_type' => AuditEventContract::ACTOR_USER,
            'actor_id' => $otherActor->id,
            'action' => AuditEventContract::USER_UPDATED,
            'auditable_type' => 'user',
            'auditable_id' => 'combined-target',
        ]);
        $sameActorAndEntity = $this->event([
            'actor_type' => AuditEventContract::ACTOR_USER,
            'actor_id' => $actor->id,
            'action' => AuditEventContract::USER_CREATED,
            'auditable_type' => 'user',
            'auditable_id' => 'combined-target',
        ]);

        $response = $this->asAdmin($this->admin())->getJson('/api/v1/admin/audit-logs?'.http_build_query([
            'action' => AuditEventContract::USER_UPDATED,
            'actor_id' => (string) $actor->id,
            'auditable_id' => 'combined-target',
        ]))->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame([(string) $target->id], array_column($response->json('data'), 'id'));
        $this->assertNotContains((string) $sameActionAndActor->id, array_column($response->json('data'), 'id'));
        $this->assertNotContains((string) $sameActionAndEntity->id, array_column($response->json('data'), 'id'));
        $this->assertNotContains((string) $sameActorAndEntity->id, array_column($response->json('data'), 'id'));
    }

    public function test_inactive_administrator_cannot_list_or_open_detail_and_gets_no_view_success_event(): void
    {
        $inactiveAdmin = User::factory()->create(['role' => 'admin', 'is_active' => false]);
        $entry = $this->event();

        $this->asAdmin($inactiveAdmin)->getJson('/api/v1/admin/audit-logs')->assertForbidden();
        $this->getJson('/api/v1/admin/audit-logs/'.$entry->id)->assertForbidden();
        $this->assertSame(0, AuditLog::whereIn('action', [AuditEventContract::AUDIT_LOGS_VIEWED, AuditEventContract::AUDIT_LOG_VIEWED])->count());
    }

    public function test_invalid_unknown_and_injection_filters_return_422(): void
    {
        $admin = $this->admin();
        foreach ([
            'sort=occurred_at',
            'direction=desc',
            'search=anything',
            'action=unknown.action',
            'category=unknown',
            'result=unknown',
            'actor_type=unknown',
            'auditable_type=unknown',
            'actor_id=0',
            'actor_id[]=1',
            'auditable_id[]=x',
            'request_id=not-a-uuid',
            'correlation_id=not-a-uuid',
            'date_from=2026-02-30',
            'per_page=0',
            'per_page=101',
            'page=0',
            'action[]=user.created',
            'sort=occurred_at%20desc%3Bdrop%20table',
        ] as $query) {
            $this->asAdmin($admin)->getJson('/api/v1/admin/audit-logs?'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/v1/admin/audit-logs?date_from=2026-11-08&date_to=2026-11-07')->assertUnprocessable();
        $this->assertSame(0, AuditLog::whereIn('action', [AuditEventContract::AUDIT_LOGS_VIEWED, AuditEventContract::AUDIT_LOG_VIEWED])->count());
    }

    public function test_deleted_actor_does_not_break_detail_and_missing_record_is_not_logged_as_success(): void
    {
        $admin = $this->admin();
        $actor = User::factory()->create();
        $entry = $this->event(['actor_type' => AuditEventContract::ACTOR_USER, 'actor_id' => $actor->id]);
        $actor->delete();

        $this->asAdmin($admin)->getJson('/api/v1/admin/audit-logs/'.$entry->id)->assertOk()->assertJsonPath('data.actor', null);
        $this->getJson('/api/v1/admin/audit-logs/999999999999')->assertNotFound();
        $this->assertSame(1, AuditLog::where('action', AuditEventContract::AUDIT_LOG_VIEWED)->count());
    }

    public function test_failed_access_audit_fails_closed_and_returns_no_audit_data(): void
    {
        $admin = $this->admin();
        $this->event(['metadata' => ['command_name' => 'synthetic:private-list-sentinel']]);
        $audit = new class(new AuditSanitizer) extends AuditService
        {
            protected function persist(AuditEventData $event): AuditLog
            {
                throw new QueryException('insert into audit_logs', [], new PDOException('synthetic audit failure'));
            }
        };
        $this->app->instance(AuditService::class, $audit);

        $response = $this->asAdmin($admin)->getJson('/api/v1/admin/audit-logs')->assertServerError();
        $this->assertFalse(str_contains($response->getContent(), 'synthetic:private-list-sentinel'));
        $this->assertSame(0, AuditLog::where('action', AuditEventContract::AUDIT_LOGS_VIEWED)->count());
    }

    public function test_only_get_routes_exist_for_the_viewer_and_reads_do_not_mutate_specialized_histories(): void
    {
        $this->event();
        $before = [
            \DB::table('audit_logs')->count(),
            \DB::table('payment_transactions')->count(),
            \DB::table('payment_webhook_events')->count(),
            \DB::table('inventory_movements')->count(),
            \DB::table('inventory_reservations')->count(),
        ];
        $this->asAdmin($this->admin())->getJson('/api/v1/admin/audit-logs')->assertOk();
        $after = [
            \DB::table('audit_logs')->count(),
            \DB::table('payment_transactions')->count(),
            \DB::table('payment_webhook_events')->count(),
            \DB::table('inventory_movements')->count(),
            \DB::table('inventory_reservations')->count(),
        ];
        $this->assertSame([$before[0] + 1, ...array_slice($before, 1)], $after);

        $methods = [];
        foreach (app('router')->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/admin/audit-logs')) {
                $methods[$route->uri()] = $route->methods();
            }
        }
        $this->assertSame(['GET', 'HEAD'], $methods['api/v1/admin/audit-logs']);
        $this->assertSame(['GET', 'HEAD'], $methods['api/v1/admin/audit-logs/{auditLog}']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function assertFilterReturnsOnlyTarget(string $filter, string $value, AuditLog $target, AuditLog $decoy): void
    {
        $response = $this->asAdmin($this->admin())
            ->getJson('/api/v1/admin/audit-logs?'.http_build_query([$filter => $value]))
            ->assertOk();
        $ids = array_column($response->json('data'), 'id');

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame([(string) $target->id], $ids);
        $this->assertNotContains((string) $decoy->id, $ids);
    }

    private function asAdmin(User $user): self
    {
        Sanctum::actingAs($user);

        return $this;
    }

    private function event(array $overrides = []): AuditLog
    {
        $action = $overrides['action'] ?? AuditEventContract::SYSTEM_COMMAND_COMPLETED;
        $actorType = $overrides['actor_type'] ?? AuditEventContract::ACTOR_COMMAND;
        $requestId = $overrides['request_id'] ?? (string) Str::uuid();
        $event = AuditEventData::fromInput(
            app(AuditSanitizer::class),
            $actorType,
            $overrides['actor_id'] ?? null,
            $action,
            $overrides['category'] ?? AuditEventContract::categoryFor($action),
            AuditEventContract::expectedResult($action),
            AuditEventContract::expectedFailureCode($action),
            $overrides['auditable_type'] ?? null,
            $overrides['auditable_id'] ?? null,
            $overrides['old_values'] ?? null,
            $overrides['new_values'] ?? null,
            array_key_exists('metadata', $overrides)
                ? $overrides['metadata']
                : ($action === AuditEventContract::SYSTEM_COMMAND_COMPLETED ? ['command_name' => 'synthetic:fixture'] : null),
            null,
            null,
            $requestId,
            $overrides['correlation_id'] ?? null,
            'admin.audit-logs.index',
            'GET',
            $overrides['ip'] ?? '192.0.2.42',
            $overrides['user_agent'] ?? 'SyntheticAuditViewerTest/1.0',
            AuditEventContract::retentionFor($action)
        );

        if (isset($overrides['occurred_at'])) {
            $values = $event->toPersistenceArray();
            $values['occurred_at'] = $overrides['occurred_at'];
            $values['created_at'] = $overrides['occurred_at'];
            $property = new \ReflectionProperty(AuditEventData::class, 'values');
            $property->setAccessible(true);
            $property->setValue($event, $values);
            $event->assertValid();
        }

        return AuditLog::appendEvent($event);
    }
}
