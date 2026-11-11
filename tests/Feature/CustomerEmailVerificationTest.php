<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Jobs\SendCustomerEmailVerification;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\VerifyCustomerEmail;
use App\Services\AuditService;
use App\Support\AuditEventContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class CustomerEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
    }

    public function test_registration_creates_unverified_customer_without_token_and_queues_fixed_response(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Synthetic Customer',
            'email' => 'new-customer@example.test',
            'password' => 'synthetic-password',
            'password_confirmation' => 'synthetic-password',
        ])->assertCreated()->assertExactJson([
            'message' => 'Registro completado. Revisa tu correo para verificar la cuenta.',
            'code' => 'email_verification_required',
        ]);

        $user = User::where('email', 'new-customer@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        Queue::assertPushed(SendCustomerEmailVerification::class, fn (SendCustomerEmailVerification $job): bool => $job->userId === $user->id
            && $job->normalizedEmail === null
            && $job->connection === 'email_verification'
            && $job->queue === 'email-verification');
        $this->runUserVerificationJob($user->id);
        Notification::assertSentTo($user, VerifyCustomerEmail::class);
        Notification::assertSentToTimes($user, VerifyCustomerEmail::class, 1);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertStringNotContainsString($user->email, $response->getContent());
        $this->assertStringNotContainsString('signature=', $response->getContent());
        $this->assertStringNotContainsString('signature=', $response->getContent());
        $sentEvent = AuditLog::where('action', AuditEventContract::EMAIL_VERIFICATION_SENT)->firstOrFail();
        $this->assertSame((string) $user->id, $sentEvent->auditable_id);
        $this->assertStringNotContainsString($user->email, AuditLog::query()->get()->toJson());
        $this->assertStringNotContainsString('signature', AuditLog::query()->get()->toJson());
    }

    public function test_admin_created_customer_is_unverified_and_notified_after_creation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name' => 'Admin Created Customer',
            'email' => 'admin-created@example.test',
            'password' => 'synthetic-password',
            'password_confirmation' => 'synthetic-password',
            'role' => 'customer',
        ])->assertCreated()->assertJsonMissingPath('data.password');

        $customer = User::where('email', 'admin-created@example.test')->firstOrFail();
        $this->assertNull($customer->email_verified_at);
        Queue::assertPushed(SendCustomerEmailVerification::class, fn (SendCustomerEmailVerification $job): bool => $job->userId === $customer->id);
        $this->runUserVerificationJob($customer->id);
        Notification::assertSentTo($customer, VerifyCustomerEmail::class);
        $this->assertStringNotContainsString('access_token', $response->getContent());
    }

    public function test_unverified_customer_cannot_login_until_valid_explicit_post_confirmation(): void
    {
        $user = User::factory()->unverified()->create([
            'role' => 'customer',
            'email' => 'pending-customer@example.test',
            'password' => Hash::make('synthetic-password'),
        ]);

        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'synthetic-password'])
            ->assertForbidden()->assertJsonPath('code', 'email_verification_required')
            ->assertJsonMissingPath('access_token');
        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue(AuditLog::where('action', AuditEventContract::AUTH_LOGIN_UNVERIFIED_REJECTED)->where('actor_id', $user->id)->exists());

        $url = $this->verificationUrl($user);
        $urlParts = parse_url($url);
        parse_str($urlParts['query'] ?? '', $query);
        $this->assertSame(['expires', 'signature'], array_keys($query));
        $this->assertSame('/email/verify/'.$user->id.'/'.sha1((string) $user->email), $urlParts['path']);
        $this->get($url)->assertOk();
        $this->assertNull($user->fresh()->email_verified_at, 'Opening the signed link must not verify the account.');
        $this->postJson($url)->assertOk()->assertJsonPath('code', 'email_verified');
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'synthetic-password'])
            ->assertOk()->assertJsonPath('user.email', $user->email)->assertJsonStructure(['access_token']);
    }

    public function test_verification_confirmation_is_idempotent_and_audited_once(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'customer']);
        $url = $this->verificationUrl($user);

        $this->postJson($url)->assertOk()->assertJsonPath('code', 'email_verified');
        $verifiedAt = $user->fresh()->email_verified_at->toISOString();
        $this->postJson($url)->assertOk()->assertJsonPath('code', 'email_already_verified');

        $this->assertSame($verifiedAt, $user->fresh()->email_verified_at->toISOString());
        $this->assertSame(1, AuditLog::where('action', AuditEventContract::EMAIL_VERIFICATION_COMPLETED)->count());
    }

    public function test_pending_get_is_read_only_and_verified_get_redirects_without_duplicate_audit(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'customer']);
        $url = $this->verificationUrl($user);

        $this->get($url)->assertOk();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame(0, AuditLog::where('action', AuditEventContract::EMAIL_VERIFICATION_COMPLETED)->count());

        $this->postJson($url)->assertOk()->assertJsonPath('code', 'email_verified');
        $verifiedAt = $user->fresh()->email_verified_at->toISOString();
        $this->assertSame(1, AuditLog::where('action', AuditEventContract::EMAIL_VERIFICATION_COMPLETED)->count());

        $this->postJson($url)->assertOk()->assertJsonPath('code', 'email_already_verified');
        $this->assertSame($verifiedAt, $user->fresh()->email_verified_at->toISOString());
        $this->assertSame(1, AuditLog::where('action', AuditEventContract::EMAIL_VERIFICATION_COMPLETED)->count());

        $this->get($url)->assertRedirect('/email/verification?result=verified');
        $this->assertSame($verifiedAt, $user->fresh()->email_verified_at->toISOString());
        $this->assertSame(1, AuditLog::where('action', AuditEventContract::EMAIL_VERIFICATION_COMPLETED)->count());
    }

    public function test_invalid_altered_and_expired_links_are_rejected_without_writes(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'customer']);
        $url = $this->verificationUrl($user);
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $altered = $parts['scheme'].'://'.$parts['host'].$parts['path'].'?'.http_build_query(array_merge($query, ['signature' => str_repeat('a', 40)]));
        $withoutSignature = $parts['scheme'].'://'.$parts['host'].$parts['path'].'?'.http_build_query(array_diff_key($query, ['signature' => true]));
        $alteredHash = $parts['scheme'].'://'.$parts['host'].$parts['path'].'?'.http_build_query($query).'&hash='.str_repeat('b', 40);

        $this->postJson($withoutSignature)->assertForbidden()->assertJsonPath('code', 'email_verification_link_invalid');
        $this->postJson($altered)->assertForbidden()->assertJsonPath('code', 'email_verification_link_invalid');
        $this->postJson($alteredHash)->assertForbidden()->assertJsonPath('code', 'email_verification_link_invalid');
        $this->assertNull($user->fresh()->email_verified_at);

        $expiredUrl = URL::temporarySignedRoute(
            'email.verification.confirm',
            now()->subMinute(),
            ['id' => $user->id, 'hash' => sha1((string) $user->email)]
        );
        $this->postJson($expiredUrl)->assertForbidden()->assertJsonPath('code', 'email_verification_link_invalid');
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame(0, AuditLog::where('action', AuditEventContract::EMAIL_VERIFICATION_COMPLETED)->count());
    }

    public function test_previous_link_is_invalid_after_customer_email_changes_and_existing_session_survives(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => 'before@example.test']);
        $token = $user->createToken('existing-session')->plainTextToken;
        $oldUrl = $this->verificationUrl($user);
        Notification::fake();

        $this->withToken($token)->putJson('/api/v1/profile', [
            'name' => $user->name,
            'email' => 'after@example.test',
            'phone' => $user->phone,
        ])->assertOk();

        $this->assertNull($user->fresh()->email_verified_at);
        $this->postJson($oldUrl)->assertForbidden()->assertJsonPath('code', 'email_verification_link_invalid');
        $this->withToken($token)->getJson('/api/v1/profile')->assertOk();
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_admin_email_change_resets_verification_and_invalidates_previous_link(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create(['role' => 'customer', 'email' => 'admin-before@example.test']);
        $oldUrl = $this->verificationUrl($customer);

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/users/'.$customer->id, [
            'name' => $customer->name,
            'email' => 'admin-after@example.test',
            'role' => 'customer',
        ])->assertOk();

        $this->assertNull($customer->fresh()->email_verified_at);
        Queue::assertPushed(SendCustomerEmailVerification::class, fn (SendCustomerEmailVerification $job): bool => $job->userId === $customer->id);
        $this->postJson($oldUrl)->assertForbidden()->assertJsonPath('code', 'email_verification_link_invalid');
    }

    public function test_administrative_role_change_to_customer_requires_verification_and_queues_by_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'treasury', 'email_verified_at' => now()]);

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/users/'.$target->id, [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'customer',
        ])->assertOk();

        $this->assertNull($target->fresh()->email_verified_at);
        Queue::assertPushed(SendCustomerEmailVerification::class, fn (SendCustomerEmailVerification $job): bool => $job->userId === $target->id
            && $job->normalizedEmail === null);
    }

    public function test_audit_rollback_does_not_leave_a_verification_job(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => 'rollback-before@example.test']);
        $audit = \Mockery::mock(AuditService::class);
        $audit->shouldReceive('recordUser')->once()->andThrow(new \RuntimeException('synthetic failure'));
        $this->app->instance(AuditService::class, $audit);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/profile', [
            'name' => $user->name,
            'email' => 'rollback-after@example.test',
            'phone' => $user->phone,
        ])->assertStatus(500);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'rollback-before@example.test']);
        Queue::assertNothingPushed();
    }

    public function test_email_change_winning_race_invalidates_old_confirmation(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'customer', 'email' => 'race-before@example.test']);
        $oldUrl = $this->verificationUrl($user);
        $changed = false;

        DB::connection()->beforeExecuting(function (string $sql) use (&$changed, $user): void {
            if (! $changed && str_contains(strtolower($sql), 'select') && str_contains(strtolower($sql), 'users')) {
                $changed = true;
                DB::table('users')->where('id', $user->id)->update([
                    'email' => 'race-after@example.test',
                    'email_verified_at' => null,
                ]);
            }
        });

        $this->postJson($oldUrl)->assertForbidden()->assertJsonPath('code', 'email_verification_link_invalid');
        $this->assertTrue($changed);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'race-after@example.test',
            'email_verified_at' => null,
        ]);
    }

    public function test_only_exact_confirmation_post_route_excludes_csrf_and_signed_get_is_read_only(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'customer']);
        $url = $this->verificationUrl($user);
        $this->get($url)->assertOk();
        $this->assertNull($user->fresh()->email_verified_at);

        $post = Route::getRoutes()->getByName('email.verification.confirm');
        $get = Route::getRoutes()->getByName('email.verification.landing');
        $this->assertNotNull($post);
        $this->assertNotNull($get);
        $this->assertContains(VerifyCsrfToken::class, $post->excludedMiddleware());
        $this->assertNotContains(VerifyCsrfToken::class, $get->excludedMiddleware());

        Route::post('/email/verify/unrelated', static fn () => response()->noContent())->middleware('web');
        $unrelated = collect(Route::getRoutes())->first(fn ($route) => $route->uri() === 'email/verify/unrelated');
        $this->assertNotNull($unrelated);
        $this->assertNotContains(VerifyCsrfToken::class, $unrelated->excludedMiddleware());
    }

    public function test_admin_and_treasury_accounts_do_not_require_customer_email_verification(): void
    {
        foreach (['admin', 'treasury'] as $role) {
            $user = User::factory()->unverified()->create([
                'role' => $role,
                'password' => Hash::make('synthetic-password'),
            ]);
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'synthetic-password'])
                ->assertOk()->assertJsonStructure(['access_token']);
        }
    }

    public function test_resend_response_is_indistinguishable_for_missing_pending_and_verified_addresses(): void
    {
        $pending = User::factory()->unverified()->create(['role' => 'customer', 'email' => 'pending-resend@example.test']);
        $verified = User::factory()->create(['role' => 'customer', 'email' => 'verified-resend@example.test']);
        $responses = [
            $this->postJson('/api/v1/auth/email/verification-notification', ['email' => 'missing@example.test']),
            $this->postJson('/api/v1/auth/email/verification-notification', ['email' => $pending->email]),
            $this->postJson('/api/v1/auth/email/verification-notification', ['email' => $verified->email]),
        ];

        foreach ($responses as $response) {
            $response->assertAccepted()->assertExactJson([
                'message' => 'Si la cuenta puede verificarse, enviaremos instrucciones al correo indicado.',
            ]);
        }
        $queued = Queue::pushed(SendCustomerEmailVerification::class);
        $this->assertCount(3, $queued);
        foreach ($queued as $job) {
            $this->assertSame('email-verification', $job->queue);
            $this->assertNull($job->userId);
            $this->assertNotNull($job->normalizedEmail);
            $job->handle(app(\App\Services\CustomerEmailVerificationService::class));
        }
        Notification::assertSentTo($pending, VerifyCustomerEmail::class, 1);
        Notification::assertNotSentTo($verified, VerifyCustomerEmail::class);
        $this->assertSame(0, AuditLog::where('action', AuditEventContract::EMAIL_VERIFICATION_SENT)->count());
        $this->assertSame(3, AuditLog::where('action', AuditEventContract::EMAIL_VERIFICATION_RESEND_REQUESTED)->count());
        $audit = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString($pending->email, $audit);
        $this->assertStringNotContainsString($verified->email, $audit);
    }

    public function test_resend_throttles_by_ip_and_normalized_email_hmac(): void
    {
        config(['email_verification.resend.ip_max_attempts' => 1, 'email_verification.resend.email_max_attempts' => 5]);
        $first = User::factory()->unverified()->create(['role' => 'customer', 'email' => 'limit-one@example.test']);
        $second = User::factory()->unverified()->create(['role' => 'customer', 'email' => 'limit-two@example.test']);
        RateLimiter::clear('email-verification:ip:'.hash_hmac('sha256', '127.0.0.1', (string) config('app.key')));
        RateLimiter::clear('email-verification:email:'.hash_hmac('sha256', $first->email, (string) config('app.key')));
        RateLimiter::clear('email-verification:email:'.hash_hmac('sha256', $second->email, (string) config('app.key')));

        $this->postJson('/api/v1/auth/email/verification-notification', ['email' => $first->email])->assertAccepted();
        $this->postJson('/api/v1/auth/email/verification-notification', ['email' => $second->email])->assertAccepted();
        $this->assertCount(1, Queue::pushed(SendCustomerEmailVerification::class));

        RateLimiter::clear('email-verification:ip:'.hash_hmac('sha256', '127.0.0.1', (string) config('app.key')));
        config(['email_verification.resend.ip_max_attempts' => 5, 'email_verification.resend.email_max_attempts' => 1]);
        $normalized = User::factory()->unverified()->create(['role' => 'customer', 'email' => 'normalized@example.test']);
        RateLimiter::clear('email-verification:email:'.hash_hmac('sha256', $normalized->email, (string) config('app.key')));
        $this->postJson('/api/v1/auth/email/verification-notification', ['email' => ' NORMALIZED@example.test '])->assertAccepted();
        $this->postJson('/api/v1/auth/email/verification-notification', ['email' => 'normalized@example.test'])->assertAccepted();
        $this->assertCount(2, Queue::pushed(SendCustomerEmailVerification::class));
    }

    public function test_transport_failure_does_not_rollback_registration_or_log_message_data(): void
    {
        $dispatcher = \Mockery::mock(\Illuminate\Contracts\Notifications\Dispatcher::class);
        $dispatcher->shouldReceive('send')->once()->andThrow(new TransportException('synthetic-private-transport-sentinel'));
        $this->app->instance(\Illuminate\Contracts\Notifications\Dispatcher::class, $dispatcher);

        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()->with('Customer email verification delivery failed.');

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Transport Failure Customer',
            'email' => 'transport-failure@example.test',
            'password' => 'synthetic-password',
            'password_confirmation' => 'synthetic-password',
        ])->assertCreated()->assertJsonPath('code', 'email_verification_required');

        $this->runUserVerificationJob((int) User::where('email', 'transport-failure@example.test')->value('id'));

        $this->assertDatabaseHas('users', ['email' => 'transport-failure@example.test', 'email_verified_at' => null]);
        $this->assertStringNotContainsString('synthetic-private-transport-sentinel', $response->getContent());
        $this->assertTrue(AuditLog::where('action', AuditEventContract::USER_CREATED)->exists());
        $this->assertFalse(AuditLog::where('action', AuditEventContract::EMAIL_VERIFICATION_SENT)->exists());
    }

    public function test_transport_failure_does_not_rollback_customer_email_change(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => 'transport-before@example.test']);
        $dispatcher = \Mockery::mock(\Illuminate\Contracts\Notifications\Dispatcher::class);
        $dispatcher->shouldReceive('send')->once()->andThrow(new TransportException('synthetic-private-transport-sentinel'));
        $this->app->instance(\Illuminate\Contracts\Notifications\Dispatcher::class, $dispatcher);
        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()->with('Customer email verification delivery failed.');

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/profile', [
            'name' => $user->name,
            'email' => 'transport-after@example.test',
            'phone' => $user->phone,
        ])->assertOk()->assertJsonPath('data.email', 'transport-after@example.test');

        $this->runUserVerificationJob($user->id);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'transport-after@example.test', 'email_verified_at' => null]);
        $this->assertFalse(AuditLog::where('action', AuditEventContract::EMAIL_VERIFICATION_SENT)->exists());
    }

    public function test_audit_and_responses_never_contain_verification_link_material(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'customer', 'email' => 'audit-verification@example.test']);
        $url = $this->verificationUrl($user);
        $response = $this->postJson($url)->assertOk();
        $audit = AuditLog::query()->get()->toJson();

        $this->assertStringNotContainsString($url, $audit);
        $this->assertStringNotContainsString('signature', $audit);
        $this->assertStringNotContainsString(sha1($user->email), $audit);
        $this->assertStringNotContainsString('audit-verification@example.test', $audit);
        $this->assertStringNotContainsString($url, $response->getContent());
        $this->assertStringNotContainsString('signature', $response->getContent());
    }

    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'email.verification.confirm',
            now()->addMinutes((int) config('email_verification.expire', 60)),
            ['id' => $user->getKey(), 'hash' => sha1((string) $user->email)]
        );
    }

    private function runUserVerificationJob(int $userId): void
    {
        $job = Queue::pushed(SendCustomerEmailVerification::class)
            ->first(fn (SendCustomerEmailVerification $queued): bool => $queued->userId === $userId);

        $this->assertNotNull($job);
        $job->handle(app(\App\Services\CustomerEmailVerificationService::class));
    }
}
