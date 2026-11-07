<?php

namespace Tests\Feature;

use App\Http\Middleware\AuditRequestContext;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditSanitizer;
use App\Services\AuditService;
use App\Services\UserAdministrationService;
use App\Support\AuditEventContract;
use App\Support\AuditEventData;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PDOException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\Concerns\SyntheticMercadoPagoLink;
use Tests\TestCase;

class AuditSecurityTraceTest extends TestCase
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

    public function test_registration_and_successful_login_have_server_context_and_no_credentials(): void
    {
        $password = 'synthetic-audit-password';
        $correlation = '550e8400-e29b-41d4-a716-446655440000';
        $registration = $this->postJson('/api/v1/auth/register', [
            'name' => 'Audit Fixture',
            'email' => 'audit-fixture@example.test',
            'password' => $password,
            'password_confirmation' => $password,
        ], ['X-Correlation-ID' => $correlation])->assertCreated();
        $user = User::where('email', 'audit-fixture@example.test')->firstOrFail();

        $this->assertTrue(AuditLog::where('action', AuditEventContract::USER_CREATED)->where('auditable_id', (string) $user->id)->exists());
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password], ['X-Correlation-ID' => $correlation])->assertOk();
        $this->assertTrue(AuditLog::where('action', AuditEventContract::AUTH_LOGIN_SUCCEEDED)->where('actor_id', $user->id)->where('correlation_id', $correlation)->exists());
        $this->assertTrue(AuditRequestContextTestHelper::isUuid((string) $login->headers->get('X-Request-ID')));

        $encoded = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString($password, $encoded);
        $this->assertStringNotContainsString($user->email, $encoded);
        $this->assertSame(0, DB::table('payment_transactions')->count());
        $this->assertSame(0, DB::table('inventory_movements')->count());
        $this->assertSame(0, DB::table('payment_webhook_events')->count());
    }

    public function test_failed_and_inactive_logins_are_fail_open_and_do_not_store_attempted_identity(): void
    {
        $wrongPassword = $this->postJson('/api/v1/auth/login', ['email' => 'private-attempt@example.test', 'password' => 'synthetic-wrong-password'])
            ->assertUnauthorized();
        $this->assertSame(['message' => 'Invalid login details'], $wrongPassword->json());
        $failed = AuditLog::where('action', AuditEventContract::AUTH_LOGIN_FAILED)->firstOrFail();
        $this->assertSame(AuditEventContract::FAILURE_INVALID_CREDENTIALS, $failed->failure_code);
        $this->assertNull($failed->actor_id);
        $this->assertNull($failed->auditable_id);
        $this->assertNull($failed->metadata);
        $this->assertStringNotContainsString('private-attempt@example.test', $failed->toJson());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());

        $inactive = User::factory()->create(['is_active' => false, 'password' => Hash::make('inactive-password')]);
        $inactiveResponse = $this->postJson('/api/v1/auth/login', ['email' => $inactive->email, 'password' => 'inactive-password'])
            ->assertUnauthorized();
        $this->assertSame($wrongPassword->json(), $inactiveResponse->json());
        $inactiveAttempt = AuditLog::where('action', AuditEventContract::AUTH_LOGIN_FAILED)->orderByDesc('id')->firstOrFail();
        $this->assertSame(AuditEventContract::FAILURE_INVALID_CREDENTIALS, $inactiveAttempt->failure_code);
        $this->assertNull($inactiveAttempt->actor_id);
        $this->assertNull($inactiveAttempt->auditable_id);
        $this->assertNull($inactiveAttempt->metadata);
        $this->assertSame(0, AuditLog::where('action', AuditEventContract::AUTH_LOGIN_SUCCEEDED)->count());
        $this->assertSame(0, AuditLog::where('action', AuditEventContract::AUTH_LOGIN_DENIED)->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertStringNotContainsString($inactive->email, AuditLog::query()->get()->toJson());
    }

    public function test_successful_login_locks_user_before_token_and_audit_and_returns_persisted_token(): void
    {
        $email = 'synthetic-login@example.test';
        $password = 'synthetic-login-password';
        $user = User::factory()->create(['email' => $email, 'is_active' => true, 'password' => Hash::make($password)]);
        $transactionLevel = DB::connection()->transactionLevel();
        $observedQueries = [];
        DB::listen(static function (QueryExecuted $query) use (&$observedQueries): void {
            $observedQueries[] = [strtolower($query->sql), $query->connection->transactionLevel()];
        });

        $response = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->assertOk();
        $plainTextToken = $response->json('access_token');
        $tokenParts = is_string($plainTextToken) ? explode('|', $plainTextToken, 2) : [];
        $this->assertCount(2, $tokenParts);
        $persistedToken = DB::table('personal_access_tokens')->where('id', $tokenParts[0])->first();
        $this->assertNotNull($persistedToken);
        $this->assertSame(hash('sha256', $tokenParts[1]), $persistedToken->token);
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
        $this->assertSame(1, AuditLog::where('action', AuditEventContract::AUTH_LOGIN_SUCCEEDED)->where('actor_id', $user->id)->count());

        $queryIndex = static function (array $queries, callable $matches): int {
            foreach ($queries as $index => [$sql]) {
                if ($matches($sql)) {
                    return $index;
                }
            }

            return -1;
        };
        $userSelectIndex = $queryIndex($observedQueries, static fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'users'));
        $tokenInsertIndex = $queryIndex($observedQueries, static fn (string $sql): bool => str_starts_with($sql, 'insert') && str_contains($sql, 'personal_access_tokens'));
        $auditInsertIndex = $queryIndex($observedQueries, static fn (string $sql): bool => str_starts_with($sql, 'insert') && str_contains($sql, 'audit_logs'));
        $this->assertTrue($userSelectIndex >= 0 && $tokenInsertIndex > $userSelectIndex && $auditInsertIndex > $tokenInsertIndex);
        $this->assertGreaterThan(0, $observedQueries[$userSelectIndex][1]);
        $this->assertGreaterThan(0, $observedQueries[$tokenInsertIndex][1]);
        $this->assertGreaterThan(0, $observedQueries[$auditInsertIndex][1]);
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->assertStringContainsString('for update', $observedQueries[$userSelectIndex][0]);
        }
        $this->assertSame($transactionLevel, DB::connection()->transactionLevel());

        $auditRows = DB::table('audit_logs')->where('action', AuditEventContract::AUTH_LOGIN_SUCCEEDED)->get()->toJson();
        foreach ([$email, $password, $user->password, $plainTextToken, $tokenParts[1]] as $sentinel) {
            $this->assertFalse(str_contains($auditRows, $sentinel));
        }
    }

    public function test_successful_login_audit_failure_rolls_back_token_and_propagates(): void
    {
        $user = User::factory()->create(['email' => 'synthetic-login-rollback@example.test', 'is_active' => true, 'password' => Hash::make('synthetic-login-rollback-password')]);
        $brokenAudit = new class(new AuditSanitizer) extends AuditService
        {
            protected function persist(AuditEventData $event): AuditLog
            {
                throw new QueryException('insert into audit_logs', [], new PDOException('synthetic failure'));
            }
        };
        $this->app->instance(AuditService::class, $brokenAudit);
        $this->withoutExceptionHandling();
        $connection = DB::connection();
        $transactionLevel = $connection->transactionLevel();
        $queryExceptionPropagated = false;

        try {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'synthetic-login-rollback@example.test',
                'password' => 'synthetic-login-rollback-password',
            ]);
            $this->fail('El error de auditoría de éxito debe propagarse.');
        } catch (QueryException) {
            $queryExceptionPropagated = true;
        }

        $this->assertTrue($queryExceptionPropagated);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
        $this->assertSame(0, AuditLog::where('action', AuditEventContract::AUTH_LOGIN_SUCCEEDED)->count());
        $this->assertSame($transactionLevel, $connection->transactionLevel());
        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS usable')->usable);
    }

    public function test_logout_and_admin_user_security_changes_are_atomic_and_minimized(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $target = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $target->createToken('synthetic-test-token');
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/users', [
            'name' => 'New Fixture',
            'email' => 'new-fixture@example.test',
            'password' => 'synthetic-user-password',
            'password_confirmation' => 'synthetic-user-password',
            'role' => 'customer',
        ])->assertCreated();
        $this->putJson('/api/v1/admin/users/'.$target->id, [
            'name' => 'Changed Fixture',
            'email' => 'changed-fixture@example.test',
            'phone' => '5550001',
            'role' => 'treasury',
        ])->assertOk();
        $this->patchJson('/api/v1/admin/users/'.$target->id.'/status', ['is_active' => false])->assertOk();
        $this->putJson('/api/v1/admin/users/'.$target->id.'/password', [
            'password' => 'synthetic-reset-password',
            'password_confirmation' => 'synthetic-reset-password',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => AuditEventContract::USER_ROLE_CHANGED, 'auditable_id' => (string) $target->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditEventContract::USER_STATUS_CHANGED, 'auditable_id' => (string) $target->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditEventContract::USER_PASSWORD_RESET, 'auditable_id' => (string) $target->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditEventContract::AUTH_SESSION_REVOKED, 'auditable_id' => (string) $target->id]);

        $roleEvent = AuditLog::where('action', AuditEventContract::USER_ROLE_CHANGED)->firstOrFail();
        $this->assertSame(['role' => 'customer'], $roleEvent->old_values);
        $this->assertSame(['role' => 'treasury'], $roleEvent->new_values);
        $this->assertStringNotContainsString('changed-fixture@example.test', AuditLog::query()->get()->toJson());
        $this->assertStringNotContainsString('synthetic-reset-password', AuditLog::query()->get()->toJson());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_profile_changes_and_password_rotation_are_audited_without_personal_values(): void
    {
        $user = User::factory()->create(['is_active' => true, 'password' => Hash::make('old-profile-password')]);
        $user->createToken('synthetic-session');
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/profile', ['name' => 'Private New Name', 'email' => 'private-new@example.test', 'phone' => '5551234'])->assertOk();
        $this->putJson('/api/v1/profile/password', [
            'current_password' => 'old-profile-password',
            'password' => 'new-profile-password',
            'password_confirmation' => 'new-profile-password',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => AuditEventContract::USER_UPDATED, 'auditable_id' => (string) $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditEventContract::USER_PASSWORD_CHANGED, 'auditable_id' => (string) $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditEventContract::AUTH_SESSION_REVOKED, 'auditable_id' => (string) $user->id]);
        $all = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString('private-new@example.test', $all);
        $this->assertStringNotContainsString('new-profile-password', $all);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_profile_password_change_validates_locked_hash_and_audits_no_secret(): void
    {
        $user = User::factory()->create(['is_active' => true, 'password' => Hash::make('synthetic-current-password')]);
        $user->createToken('synthetic-profile-password-token');
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/profile/password', [
            'current_password' => 'synthetic-current-password',
            'password' => 'synthetic-replacement-password',
            'password_confirmation' => 'synthetic-replacement-password',
        ])->assertOk()->assertJsonPath('requires_login', true);

        $storedUser = User::findOrFail($user->id);
        $this->assertTrue(Hash::check('synthetic-replacement-password', $storedUser->password));
        $this->assertFalse(Hash::check('synthetic-current-password', $storedUser->password));
        $this->assertSame(1, AuditLog::where('action', AuditEventContract::USER_PASSWORD_CHANGED)->where('auditable_id', (string) $user->id)->count());
        $this->assertSame(1, AuditLog::where('action', AuditEventContract::AUTH_SESSION_REVOKED)->where('auditable_id', (string) $user->id)->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());

        $auditRows = DB::table('audit_logs')->get()->toJson();
        foreach (['synthetic-current-password', 'synthetic-replacement-password'] as $sentinel) {
            $this->assertFalse(str_contains($auditRows, $sentinel), 'La auditoría no debe contener contraseñas.');
        }
        $this->assertFalse(str_contains($auditRows, $storedUser->password), 'La auditoría no debe contener hashes de contraseña.');
    }

    public function test_incorrect_current_profile_password_preserves_hash_tokens_and_success_audit(): void
    {
        $user = User::factory()->create(['is_active' => true, 'password' => Hash::make('synthetic-current-password')]);
        $user->createToken('synthetic-preserved-password-token');
        Sanctum::actingAs($user);
        $tokenCount = DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count();
        $storedHash = $user->password;

        $this->putJson('/api/v1/profile/password', [
            'current_password' => 'synthetic-wrong-current-password',
            'password' => 'synthetic-replacement-password',
            'password_confirmation' => 'synthetic-replacement-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertSame($storedHash, User::findOrFail($user->id)->password);
        $this->assertSame($tokenCount, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
        $this->assertSame(0, AuditLog::whereIn('action', [AuditEventContract::USER_PASSWORD_CHANGED, AuditEventContract::AUTH_SESSION_REVOKED])->count());
    }

    public function test_profile_password_rejects_a_stale_authenticated_user_instance(): void
    {
        $user = User::factory()->create(['is_active' => true, 'password' => Hash::make('synthetic-password-A')]);
        $user->createToken('synthetic-stale-password-token');
        Sanctum::actingAs($user);
        $tokenCount = DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count();

        DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('synthetic-password-B')]);

        $this->putJson('/api/v1/profile/password', [
            'current_password' => 'synthetic-password-A',
            'password' => 'synthetic-password-C',
            'password_confirmation' => 'synthetic-password-C',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $storedUser = User::findOrFail($user->id);
        $this->assertTrue(Hash::check('synthetic-password-B', $storedUser->password));
        $this->assertFalse(Hash::check('synthetic-password-A', $storedUser->password));
        $this->assertFalse(Hash::check('synthetic-password-C', $storedUser->password));
        $this->assertSame($tokenCount, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
        $this->assertSame(0, AuditLog::whereIn('action', [AuditEventContract::USER_PASSWORD_CHANGED, AuditEventContract::AUTH_SESSION_REVOKED])->count());
    }

    public function test_logout_records_the_actor_and_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('synthetic-logout-token');
        $user->createToken('synthetic-other-token');
        $this->withToken($token->plainTextToken)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => AuditEventContract::AUTH_LOGOUT, 'actor_id' => $user->id]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_protected_403_and_foreign_resource_404_are_recorded_generically(): void
    {
        $this->getJson('/api/v1/products/audit-public-missing-'.Str::uuid())->assertNotFound();
        $this->assertDatabaseCount('audit_logs', 0);

        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/admin/users')->assertForbidden();

        $owner = User::factory()->create();
        $addressId = DB::table('user_addresses')->insertGetId([
            'user_id' => $owner->id,
            'recipient_name' => 'Synthetic Recipient',
            'phone' => '5550000',
            'address' => 'Synthetic Address',
            'department' => 'Synthetic Department',
            'province' => 'Synthetic Province',
            'district' => 'Synthetic District',
            'normalized_department' => 'synthetic department',
            'normalized_province' => 'synthetic province',
            'normalized_district' => 'synthetic district',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->putJson('/api/v1/addresses/'.$addressId, [])->assertNotFound();

        $denials = AuditLog::where('action', AuditEventContract::SECURITY_ACCESS_DENIED)->orderBy('id')->get();
        $this->assertCount(2, $denials);
        $this->assertSame(
            [AuditEventContract::FAILURE_PROTECTED_ACCESS_DENIED, AuditEventContract::FAILURE_PROTECTED_ACCESS_DENIED],
            $denials->pluck('failure_code')->all()
        );
        $this->assertNull($denials[1]->auditable_id);
        $this->assertNull($denials[0]->metadata);
        $this->assertNull($denials[1]->metadata);
    }

    public function test_system_and_command_actors_and_contract_allowlists_are_explicit(): void
    {
        $audit = app(AuditService::class);
        $system = $audit->recordSystem(AuditEventContract::SYSTEM_COMMAND_COMPLETED, AuditEventContract::RESULT_SUCCEEDED, metadata: ['command_name' => 'synthetic:reconcile']);
        $command = $audit->recordCommand(AuditEventContract::SYSTEM_COMMAND_COMPLETED, AuditEventContract::RESULT_SUCCEEDED, metadata: ['command_name' => 'synthetic:reconcile']);

        $this->assertSame('system', $system->actor_type);
        $this->assertSame('command', $command->actor_type);
        $this->assertSame(AuditEventContract::ACTIONS, array_values(array_unique(AuditEventContract::ACTIONS)));
        $this->expectException(\InvalidArgumentException::class);
        $audit->recordSystem('user.created.evil', AuditEventContract::RESULT_SUCCEEDED);
    }

    public function test_failure_codes_and_action_results_cannot_be_supplied_arbitrarily(): void
    {
        $audit = app(AuditService::class);

        try {
            $audit->recordSystem(AuditEventContract::SYSTEM_COMMAND_COMPLETED, AuditEventContract::RESULT_FAILED, 'arbitrary_failure');
            $this->fail('El código de fallo arbitrario debe rechazarse.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $this->expectException(\InvalidArgumentException::class);
        $audit->recordSystem(AuditEventContract::SYSTEM_COMMAND_COMPLETED, AuditEventContract::RESULT_DENIED);
    }

    public function test_fail_closed_user_change_rolls_back_when_audit_persistence_fails(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $brokenAudit = new class(new AuditSanitizer) extends AuditService
        {
            protected function persist(AuditEventData $event): AuditLog
            {
                throw new QueryException('insert into audit_logs', [], new PDOException('synthetic failure'));
            }
        };
        $service = new UserAdministrationService($brokenAudit);

        try {
            $service->create([
                'name' => 'Rollback Fixture',
                'email' => 'rollback-fixture@example.test',
                'password' => 'synthetic-password',
                'role' => 'customer',
            ], $actor);
            $this->fail('La escritura del logger debe abortar la creación crítica.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('users', ['email' => 'rollback-fixture@example.test']);
        }
    }

    public function test_fail_closed_role_status_and_password_updates_roll_back_with_the_audit_row(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $target = User::factory()->create(['role' => 'customer', 'is_active' => true, 'password' => Hash::make('original-password')]);
        $target->createToken('synthetic-preserved-token');
        $brokenAudit = new class(new AuditSanitizer) extends AuditService
        {
            protected function persist(AuditEventData $event): AuditLog
            {
                throw new QueryException('insert into audit_logs', [], new PDOException('synthetic failure'));
            }
        };
        $service = new \App\Services\UserAdministrationService($brokenAudit);

        foreach ([
            fn () => $service->update($actor, $target, ['name' => 'Synthetic Changed', 'email' => 'changed@example.test', 'role' => 'treasury']),
            fn () => $service->changeStatus($actor, $target, false),
            fn () => $service->resetPassword($actor, $target, 'synthetic-new-password'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('La mutación debe abortarse cuando no se persiste su auditoría.');
            } catch (QueryException) {
                $this->assertSame('customer', $target->refresh()->role);
                $this->assertTrue($target->is_active);
                $this->assertTrue(Hash::check('original-password', $target->password));
                $this->assertDatabaseCount('personal_access_tokens', 1);
                $this->assertDatabaseCount('audit_logs', 0);
            }
        }
    }

    public function test_fail_closed_self_profile_and_password_changes_roll_back_on_audit_failure(): void
    {
        $user = User::factory()->create(['is_active' => true, 'password' => Hash::make('profile-original-password')]);
        $user->createToken('synthetic-profile-token');
        Sanctum::actingAs($user);
        $brokenAudit = new class(new AuditSanitizer) extends AuditService
        {
            protected function persist(AuditEventData $event): AuditLog
            {
                throw new QueryException('insert into audit_logs', [], new PDOException('synthetic failure'));
            }
        };
        $this->app->instance(AuditService::class, $brokenAudit);
        $this->withoutExceptionHandling();

        try {
            $this->putJson('/api/v1/profile', ['name' => 'Changed Name', 'email' => 'changed-profile@example.test'])->assertOk();
            $this->fail('El cambio de perfil debe abortarse cuando falla la auditoría.');
        } catch (QueryException) {
            $this->assertNotSame('Changed Name', $user->refresh()->name);
            $this->assertDatabaseCount('audit_logs', 0);
        }
        try {
            $this->putJson('/api/v1/profile/password', [
                'current_password' => 'profile-original-password',
                'password' => 'profile-new-password',
                'password_confirmation' => 'profile-new-password',
            ])->assertOk();
            $this->fail('El cambio de contraseña debe abortarse cuando falla la auditoría.');
        } catch (QueryException) {
            $this->assertTrue(Hash::check('profile-original-password', $user->refresh()->password));
            $this->assertDatabaseCount('personal_access_tokens', 1);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_fail_open_login_and_denial_preserve_http_outcome_when_audit_database_fails(): void
    {
        $brokenAudit = new class(new AuditSanitizer) extends AuditService
        {
            protected function persist(AuditEventData $event): AuditLog
            {
                throw new QueryException('insert into audit_logs', [], new PDOException('synthetic failure'));
            }
        };
        $this->app->instance(AuditService::class, $brokenAudit);

        $this->postJson('/api/v1/auth/login', ['email' => 'synthetic@example.test', 'password' => 'synthetic-wrong'])->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'customer', 'is_active' => true]));
        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_audit_rows_cannot_be_updated_deleted_or_mass_assigned(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $entry = AuditLog::where('action', AuditEventContract::AUTH_LOGIN_SUCCEEDED)->firstOrFail();
        $storedBefore = (array) DB::table('audit_logs')->where('id', $entry->id)->first();

        try {
            AuditLog::create(['action' => 'unsafe']);
            $this->fail('La escritura masiva directa debe estar protegida.');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        try {
            (new AuditLog)->forceFill(['action' => AuditEventContract::AUTH_LOGIN_SUCCEEDED])->save();
            $this->fail('La creación directa del modelo debe rechazarse.');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        try {
            (new AuditLog)->saveQuietly();
            $this->fail('La creación silenciosa directa del modelo debe rechazarse.');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        $mutations = [
            fn () => AuditLog::forceCreate(['action' => 'unsafe']),
            fn () => AuditLog::firstOrCreate(['action' => 'unsafe']),
            fn () => AuditLog::findOrFail($entry->id)->forceFill(['result' => 'failed'])->save(),
            fn () => AuditLog::findOrFail($entry->id)->saveQuietly(),
            fn () => AuditLog::findOrFail($entry->id)->update(['result' => 'failed']),
            fn () => AuditLog::findOrFail($entry->id)->updateQuietly(['result' => 'failed']),
            fn () => AuditLog::findOrFail($entry->id)->delete(),
            fn () => AuditLog::findOrFail($entry->id)->deleteQuietly(),
            fn () => AuditLog::findOrFail($entry->id)->forceDelete(),
            fn () => AuditLog::findOrFail($entry->id)->increment('id'),
            fn () => AuditLog::findOrFail($entry->id)->decrement('id'),
            fn () => AuditLog::findOrFail($entry->id)->touch(),
            fn () => AuditLog::findOrFail($entry->id)->touchQuietly(),
            fn () => AuditLog::findOrFail($entry->id)->push(),
            fn () => AuditLog::findOrFail($entry->id)->pushQuietly(),
            fn () => AuditLog::whereKey($entry->id)->update(['result' => 'failed']),
            fn () => AuditLog::whereKey($entry->id)->delete(),
            fn () => AuditLog::whereKey($entry->id)->increment('id'),
            fn () => AuditLog::whereKey($entry->id)->decrement('id'),
            fn () => AuditLog::whereKey($entry->id)->incrementEach(['id' => 1]),
            fn () => AuditLog::whereKey($entry->id)->decrementEach(['id' => 1]),
            fn () => AuditLog::whereKey($entry->id)->touch(),
            fn () => AuditLog::whereKey($entry->id)->updateOrCreate([], ['result' => 'failed']),
            fn () => AuditLog::whereKey($entry->id)->updateFrom(['result' => 'failed']),
            fn () => AuditLog::upsert([['id' => $entry->id, 'result' => 'failed']], ['id'], ['result']),
            fn () => AuditLog::insert(['action' => 'unsafe']),
            fn () => AuditLog::insertOrIgnore(['action' => 'unsafe']),
            fn () => AuditLog::insertUsing(['action'], DB::table('audit_logs')->select('action')),
            fn () => AuditLog::insertOrIgnoreUsing(['action'], DB::table('audit_logs')->select('action')),
            fn () => AuditLog::insertGetId(['action' => 'unsafe']),
            fn () => AuditLog::truncate(),
        ];
        foreach ($mutations as $mutation) {
            try {
                $mutation();
                $this->fail('La operación alternativa debe rechazarse.');
            } catch (\LogicException) {
                $this->assertSame($storedBefore, (array) DB::table('audit_logs')->where('id', $entry->id)->first());
                $this->assertDatabaseHas('audit_logs', ['id' => $entry->id]);
            }
        }
        $this->assertSame(1, AuditLog::count());
        $followup = app(AuditService::class)->recordSystem(
            AuditEventContract::SYSTEM_COMMAND_COMPLETED,
            AuditEventContract::RESULT_SUCCEEDED,
            metadata: ['command_name' => 'synthetic:after-mutations']
        );
        $this->assertTrue($followup->exists);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_update_or_insert_is_rejected_for_existing_and_missing_rows(): void
    {
        $existing = app(AuditService::class)->recordSystem(
            AuditEventContract::SYSTEM_COMMAND_COMPLETED,
            AuditEventContract::RESULT_SUCCEEDED,
            metadata: ['command_name' => 'synthetic:existing']
        );
        $before = (array) DB::table('audit_logs')->where('id', $existing->id)->first();
        $missingId = (int) DB::table('audit_logs')->max('id') + 100000;
        $expectedCount = 1;

        foreach ([
            ['id' => $existing->id],
            ['id' => $missingId],
        ] as $attributes) {
            try {
                AuditLog::query()->updateOrInsert($attributes, ['result' => AuditEventContract::RESULT_FAILED]);
                $this->fail('updateOrInsert debe rechazarse en todos los casos.');
            } catch (\LogicException) {
                $this->assertSame($before, (array) DB::table('audit_logs')->where('id', $existing->id)->first());
                $this->assertDatabaseCount('audit_logs', $expectedCount);
            }

            $created = app(AuditService::class)->recordSystem(
                AuditEventContract::SYSTEM_COMMAND_COMPLETED,
                AuditEventContract::RESULT_SUCCEEDED,
                metadata: ['command_name' => 'synthetic:after-update-or-insert']
            );
            $this->assertTrue($created->exists);
            $expectedCount++;
        }

        $this->assertDatabaseCount('audit_logs', $expectedCount);
    }

    public function test_append_event_does_not_dispatch_eloquent_write_listeners_or_allow_reentry(): void
    {
        $dispatcher = AuditLog::getEventDispatcher();
        $listenerCalls = 0;
        $reentryAttempted = false;
        $event = $this->validAuditEventData();
        $saving = 'eloquent.saving: '.AuditLog::class;
        $creating = 'eloquent.creating: '.AuditLog::class;
        $listener = static function () use (&$listenerCalls, &$reentryAttempted, $event): void {
            $listenerCalls++;
            if (! $reentryAttempted) {
                $reentryAttempted = true;
                try {
                    AuditLog::appendEvent($event);
                    AuditLog::query()->insert(['action' => 'synthetic:unvalidated']);
                } catch (\Throwable) {
                    // The callback is not part of the validated append path.
                }
            }
        };
        $dispatcher->listen($saving, $listener);
        $dispatcher->listen($creating, $listener);

        try {
            $entry = AuditLog::appendEvent($event);
        } finally {
            $dispatcher->forget($saving);
            $dispatcher->forget($creating);
        }

        $this->assertTrue($entry->exists);
        $this->assertSame(0, $listenerCalls);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', ['id' => $entry->id, 'action' => AuditEventContract::SYSTEM_COMMAND_COMPLETED]);
    }

    public function test_two_consecutive_valid_audit_events_create_independent_rows(): void
    {
        $first = app(AuditService::class)->recordSystem(
            AuditEventContract::SYSTEM_COMMAND_COMPLETED,
            AuditEventContract::RESULT_SUCCEEDED,
            metadata: ['command_name' => 'synthetic:first']
        );
        $second = app(AuditService::class)->recordSystem(
            AuditEventContract::SYSTEM_COMMAND_COMPLETED,
            AuditEventContract::RESULT_SUCCEEDED,
            metadata: ['command_name' => 'synthetic:second']
        );

        $this->assertTrue($first->exists);
        $this->assertTrue($second->exists);
        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_sensitive_user_agent_is_not_persisted_in_any_audit_field(): void
    {
        $sentinel = 'Bearer synthetic-token-secret-password-api_key-authorization-cookies-sentinel';
        $request = Request::create('/api/v1/auth/login', 'POST', [], [], [], ['HTTP_USER_AGENT' => $sentinel]);
        $event = app(AuditService::class)->recordCommand(
            AuditEventContract::SYSTEM_COMMAND_COMPLETED,
            AuditEventContract::RESULT_SUCCEEDED,
            metadata: ['command_name' => 'synthetic:user-agent-test'],
            request: $request
        );
        $stored = DB::table('audit_logs')->where('id', $event->id)->first();
        $encoded = json_encode((array) $stored);

        $this->assertNull($event->user_agent);
        $this->assertNull($event->old_values);
        $this->assertNull($event->new_values);
        $this->assertFalse(str_contains((string) json_encode($event->metadata), $sentinel), 'La metadata no debe revelar el encabezado original.');
        $this->assertFalse(str_contains((string) $encoded, $sentinel), 'La fila almacenada no debe revelar el encabezado original.');
        $this->assertFalse(str_contains(serialize($event), $sentinel), 'La representación serializada no debe revelar el encabezado original.');
    }

    public function test_audit_insert_boundary_rejects_arbitrary_arrays_and_revalidates_contract(): void
    {
        try {
            AuditLog::appendEvent([]);
            $this->fail('La frontera no debe aceptar arreglos como eventos.');
        } catch (\TypeError) {
            $this->assertTrue(true);
        }

        $audit = app(AuditService::class);
        $user = User::factory()->create();
        foreach (['password', 'token', 'secret', 'Authorization'] as $sensitiveKey) {
            try {
                $audit->recordUser(
                    AuditEventContract::USER_UPDATED,
                    $user,
                    AuditEventContract::RESULT_SUCCEEDED,
                    auditableType: 'user',
                    auditableId: (string) $user->id,
                    metadata: [$sensitiveKey => 'synthetic-sensitive-sentinel']
                );
                $this->fail('La metadata con claves sensibles debe rechazarse.');
            } catch (\InvalidArgumentException) {
                $this->assertDatabaseCount('audit_logs', 0);
                $this->assertFalse(str_contains(AuditLog::query()->get()->toJson(), 'synthetic-sensitive-sentinel'), 'El sentinel no debe persistirse.');
            }
        }
        try {
            $audit->recordUser(
                AuditEventContract::USER_UPDATED,
                $user,
                AuditEventContract::RESULT_SUCCEEDED,
                auditableType: 'user',
                auditableId: (string) $user->id,
                metadata: ['changed_fields' => ['email']]
            );
            $this->fail('La metadata anidada debe rechazarse.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('audit_logs', 0);
        }

        $event = $this->validAuditEventData();
        foreach ([
            ['category' => AuditEventContract::CATEGORY_ACCESS],
            ['retention_category' => AuditEventContract::RETENTION_ACCESS],
        ] as $tampering) {
            $values = $event->toPersistenceArray();
            $values[key($tampering)] = current($tampering);
            $property = new \ReflectionProperty(AuditEventData::class, 'values');
            $property->setAccessible(true);
            $property->setValue($event, $values);
            try {
                AuditLog::appendEvent($event);
                $this->fail('El modelo debe revalidar categorías antes del INSERT.');
            } catch (\InvalidArgumentException) {
                $this->assertDatabaseCount('audit_logs', 0);
            }
            $property->setValue($event, $this->validAuditEventData()->toPersistenceArray());
        }
    }

    public function test_real_sql_insert_failure_rolls_back_and_connection_remains_usable(): void
    {
        $driver = DB::connection()->getDriverName();
        $existing = app(AuditService::class)->recordSystem(
            AuditEventContract::SYSTEM_COMMAND_COMPLETED,
            AuditEventContract::RESULT_SUCCEEDED,
            metadata: ['command_name' => 'synthetic:before-sql-failure']
        );
        $event = $this->validAuditEventData();
        $trigger = 'audit_reject_'.Str::lower(Str::random(20));
        $connection = DB::connection();
        $transactionLevel = $connection->transactionLevel();
        $countBefore = DB::table('audit_logs')->count();
        $quotedTrigger = '"'.$trigger.'"';
        if ($driver === 'sqlite') {
            $connection->unprepared("CREATE TEMP TRIGGER {$quotedTrigger} BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'synthetic audit insert rejection'); END");
        } elseif ($driver === 'mysql') {
            $column = $connection->selectOne(
                'SELECT DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['audit_logs', 'occurred_at']
            );
            $this->assertSame('timestamp', strtolower((string) ($column->type ?? '')), 'La fixture MySQL requiere la columna timestamp física existente.');
            $mode = $connection->selectOne('SELECT @@SESSION.sql_mode AS mode');
            $this->assertTrue(str_contains(strtoupper((string) ($mode->mode ?? '')), 'STRICT'), 'La fixture MySQL requiere modo estricto para rechazar el timestamp fuera de rango.');
            $valuesProperty = new \ReflectionProperty(AuditEventData::class, 'values');
            $valuesProperty->setAccessible(true);
            $values = $event->toPersistenceArray();
            $values['occurred_at'] = '9999-12-31 23:59:59';
            $valuesProperty->setValue($event, $values);
            $event->assertValid();
        } else {
            $this->fail('La fixture SQL solo admite SQLite o MySQL aislado.');
        }
        $queryExceptionPropagated = false;

        try {
            AuditLog::appendEvent($event);
            $this->fail('El motor debe rechazar el INSERT real.');
        } catch (QueryException) {
            $queryExceptionPropagated = true;
        } finally {
            if ($driver === 'sqlite') {
                $connection->unprepared("DROP TRIGGER IF EXISTS {$quotedTrigger}");
            }
        }

        $this->assertTrue($queryExceptionPropagated);
        $this->assertSame($countBefore, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('audit_logs', ['id' => $existing->id, 'action' => AuditEventContract::SYSTEM_COMMAND_COMPLETED]);
        $this->assertSame($transactionLevel, $connection->transactionLevel());
        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS usable')->usable);

        $created = app(AuditService::class)->recordSystem(
            AuditEventContract::SYSTEM_COMMAND_COMPLETED,
            AuditEventContract::RESULT_SUCCEEDED,
            metadata: ['command_name' => 'synthetic:after-failure']
        );
        $this->assertTrue($created->exists);
        $this->assertDatabaseCount('audit_logs', $countBefore + 1);
    }

    public function test_request_context_is_fresh_and_cleared_after_an_exception(): void
    {
        $firstCorrelation = '550e8400-e29b-41d4-a716-446655440000';
        $request = Request::create('/api/v1/admin/users', 'GET', [], [], [], [
            'HTTP_X_CORRELATION_ID' => $firstCorrelation,
            'HTTP_X_AUDIT_PROBE' => 'synthetic-header-sentinel',
        ]);
        $route = app('router')->getRoutes()->getByName('admin.users.index');
        $this->assertNotNull($route);
        $request->setRouteResolver(static fn () => $route);
        $middleware = app(AuditRequestContext::class);
        $firstRequestId = null;

        try {
            $middleware->handle($request, function (Request $current) use (&$firstRequestId) {
                $firstRequestId = $current->attributes->get(AuditRequestContext::REQUEST_ID_ATTRIBUTE);
                throw new NotFoundHttpException;
            });
            $this->fail('La excepción protegida debe propagarse.');
        } catch (NotFoundHttpException) {
            $this->assertNull($request->attributes->get(AuditRequestContext::REQUEST_ID_ATTRIBUTE));
            $this->assertNull($request->attributes->get(AuditRequestContext::CORRELATION_ID_ATTRIBUTE));
            $this->assertFalse($request->attributes->has(AuditRequestContext::REQUEST_ID_ATTRIBUTE));
            $this->assertFalse($request->attributes->has(AuditRequestContext::CORRELATION_ID_ATTRIBUTE));
        }
        $firstEvent = AuditLog::where('action', AuditEventContract::SECURITY_ACCESS_DENIED)->firstOrFail();
        $this->assertSame($firstRequestId, $firstEvent->request_id);
        $this->assertSame($firstCorrelation, $firstEvent->correlation_id);

        $request->headers->set('X-Correlation-ID', 'invalid-correlation-sentinel');
        $request->headers->set('X-Request-ID', 'client-request-id-sentinel');
        $secondRequestId = null;
        $response = $middleware->handle($request, function (Request $current) use (&$secondRequestId) {
            $secondRequestId = $current->attributes->get(AuditRequestContext::REQUEST_ID_ATTRIBUTE);
            $this->assertNull($current->attributes->get(AuditRequestContext::CORRELATION_ID_ATTRIBUTE));
            app(AuditService::class)->recordFailureOpen(
                AuditEventContract::AUTH_LOGIN_FAILED,
                null,
                AuditEventContract::RESULT_FAILED,
                AuditEventContract::FAILURE_INVALID_CREDENTIALS,
                request: $current
            );

            return response()->json(['ok' => true]);
        });

        $this->assertTrue(AuditRequestContext::isUuid((string) $firstRequestId));
        $this->assertTrue(AuditRequestContext::isUuid((string) $secondRequestId));
        $this->assertNotSame($firstRequestId, $secondRequestId);
        $this->assertNotSame('client-request-id-sentinel', $secondRequestId);
        $this->assertSame($secondRequestId, $response->headers->get('X-Request-ID'));
        $secondEvent = AuditLog::where('action', AuditEventContract::AUTH_LOGIN_FAILED)->firstOrFail();
        $this->assertSame($secondRequestId, $secondEvent->request_id);
        $this->assertNull($secondEvent->correlation_id);
        $this->assertNull($request->attributes->get(AuditRequestContext::REQUEST_ID_ATTRIBUTE));
        $this->assertStringNotContainsString('invalid-correlation-sentinel', AuditLog::query()->get()->toJson());
        $this->assertStringNotContainsString('synthetic-header-sentinel', AuditLog::query()->get()->toJson());
        $this->assertStringNotContainsString('client-request-id-sentinel', AuditLog::query()->get()->toJson());
    }

    public function test_request_context_restores_preexisting_attributes_after_normal_return_and_mutation(): void
    {
        $request = Request::create('/api/v1/auth/login', 'POST', [], [], [], [
            'HTTP_X_CORRELATION_ID' => '550e8400-e29b-41d4-a716-446655440000',
        ]);
        $request->attributes->set(AuditRequestContext::REQUEST_ID_ATTRIBUTE, 'previous-request-context');
        $request->attributes->set(AuditRequestContext::CORRELATION_ID_ATTRIBUTE, null);
        $seenDuringRequest = [];

        app(AuditRequestContext::class)->handle($request, function (Request $current) use (&$seenDuringRequest) {
            $seenDuringRequest = [
                $current->attributes->get(AuditRequestContext::REQUEST_ID_ATTRIBUTE),
                $current->attributes->get(AuditRequestContext::CORRELATION_ID_ATTRIBUTE),
            ];
            $current->attributes->set(AuditRequestContext::REQUEST_ID_ATTRIBUTE, 'mutated-request-context');
            $current->attributes->set(AuditRequestContext::CORRELATION_ID_ATTRIBUTE, 'mutated-correlation-context');

            return response()->noContent();
        });

        $this->assertTrue(AuditRequestContext::isUuid((string) $seenDuringRequest[0]));
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $seenDuringRequest[1]);
        $this->assertSame('previous-request-context', $request->attributes->get(AuditRequestContext::REQUEST_ID_ATTRIBUTE));
        $this->assertTrue($request->attributes->has(AuditRequestContext::CORRELATION_ID_ATTRIBUTE));
        $this->assertNull($request->attributes->get(AuditRequestContext::CORRELATION_ID_ATTRIBUTE));
    }

    public function test_request_context_restores_present_null_attributes(): void
    {
        $request = Request::create('/api/v1/auth/login', 'POST');
        $request->attributes->set(AuditRequestContext::REQUEST_ID_ATTRIBUTE, null);
        $request->attributes->set(AuditRequestContext::CORRELATION_ID_ATTRIBUTE, null);

        app(AuditRequestContext::class)->handle($request, function (Request $current) {
            $this->assertTrue(AuditRequestContext::isUuid((string) $current->attributes->get(AuditRequestContext::REQUEST_ID_ATTRIBUTE)));
            $this->assertNull($current->attributes->get(AuditRequestContext::CORRELATION_ID_ATTRIBUTE));

            return response()->noContent();
        });

        $this->assertTrue($request->attributes->has(AuditRequestContext::REQUEST_ID_ATTRIBUTE));
        $this->assertNull($request->attributes->get(AuditRequestContext::REQUEST_ID_ATTRIBUTE));
        $this->assertTrue($request->attributes->has(AuditRequestContext::CORRELATION_ID_ATTRIBUTE));
        $this->assertNull($request->attributes->get(AuditRequestContext::CORRELATION_ID_ATTRIBUTE));
    }

    public function test_request_context_restores_preexisting_attributes_when_next_middleware_throws(): void
    {
        $request = Request::create('/api/v1/auth/login', 'POST');
        $request->attributes->set(AuditRequestContext::REQUEST_ID_ATTRIBUTE, 'prior-request');
        $request->attributes->set(AuditRequestContext::CORRELATION_ID_ATTRIBUTE, 'prior-correlation');

        try {
            app(AuditRequestContext::class)->handle($request, static function (Request $current) {
                if (! AuditRequestContext::isUuid((string) $current->attributes->get(AuditRequestContext::REQUEST_ID_ATTRIBUTE))) {
                    throw new \RuntimeException('request context not installed');
                }
                throw new \RuntimeException('synthetic middleware failure');
            });
            $this->fail('La excepción del middleware posterior debe propagarse.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('synthetic middleware failure', $exception->getMessage());
        }

        $this->assertSame('prior-request', $request->attributes->get(AuditRequestContext::REQUEST_ID_ATTRIBUTE));
        $this->assertSame('prior-correlation', $request->attributes->get(AuditRequestContext::CORRELATION_ID_ATTRIBUTE));
    }

    public function test_invalid_external_correlation_is_discarded_and_retention_is_configured(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'invalid-correlation@example.test',
            'password' => 'synthetic-password',
        ], ['X-Correlation-ID' => 'not-a-correlation-id'])->assertUnauthorized();

        $event = AuditLog::where('action', AuditEventContract::AUTH_LOGIN_FAILED)->firstOrFail();
        $this->assertNull($event->correlation_id);
        $this->assertTrue(AuditRequestContextTestHelper::isUuid((string) $response->headers->get('X-Request-ID')));
        $this->assertSame(12, config('audit.retention_months.access'));
        $this->assertSame(60, config('audit.retention_months.administrative'));
    }

    private function validAuditEventData(): AuditEventData
    {
        return AuditEventData::fromInput(
            app(AuditSanitizer::class),
            AuditEventContract::ACTOR_SYSTEM,
            null,
            AuditEventContract::SYSTEM_COMMAND_COMPLETED,
            AuditEventContract::CATEGORY_SYSTEM,
            AuditEventContract::RESULT_SUCCEEDED,
            null,
            null,
            null,
            null,
            null,
            ['command_name' => 'synthetic:fixture'],
            null,
            null,
            '550e8400-e29b-41d4-a716-446655440000',
            null,
            null,
            'POST',
            '192.0.2.45',
            'SyntheticAuditTest/1.0',
            AuditEventContract::RETENTION_ADMINISTRATIVE
        );
    }
}

final class AuditRequestContextTestHelper
{
    public static function isUuid(string $value): bool
    {
        return \App\Http\Middleware\AuditRequestContext::isUuid($value);
    }
}
