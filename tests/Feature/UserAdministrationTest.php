<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => 'admin', 'is_active' => true], $attributes));
    }

    public function test_admin_lists_only_safe_paginated_user_fields(): void
    {
        Sanctum::actingAs($this->admin());
        $user = User::factory()->create(['password' => Hash::make('secret-password')]);
        $this->getJson('/api/v1/admin/users?search='.$user->email)
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', $user->email)
            ->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.0.remember_token');
    }

    public function test_customer_cannot_administer_users_and_guest_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'customer']));
        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_admin_creates_normalized_user_with_hashed_password_and_no_secret_response(): void
    {
        Sanctum::actingAs($this->admin());
        $response = $this->postJson('/api/v1/admin/users', ['name' => 'Ana', 'email' => ' ANA@Example.test ', 'phone' => '999', 'password' => 'password-new', 'password_confirmation' => 'password-new', 'role' => 'customer', 'is_active' => true]);
        $response->assertCreated()->assertJsonPath('data.email', 'ana@example.test')->assertJsonMissingPath('data.password');
        $this->assertTrue(Hash::check('password-new', User::where('email', 'ana@example.test')->firstOrFail()->password));
    }

    public function test_duplicate_email_and_sensitive_extra_fields_are_rejected_or_ignored(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/users', ['name' => 'Ana', 'email' => $admin->email, 'password' => 'password-new', 'password_confirmation' => 'password-new', 'role' => 'customer'])->assertUnprocessable();
        $target = User::factory()->create(['role' => 'customer']);
        $this->putJson('/api/v1/admin/users/'.$target->id, ['name' => 'Changed', 'email' => 'changed@example.test', 'role' => 'customer', 'password' => 'not-allowed', 'created_at' => '2000-01-01'])->assertOk();
        $this->assertFalse(Hash::check('not-allowed', $target->refresh()->password));
    }

    public function test_admin_partial_update_preserves_legacy_delivery_capability(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['role' => 'customer', 'can_deliver' => true]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/v1/admin/users/'.$target->id, [
            'name' => 'Actualizado', 'email' => 'actualizado@example.test', 'role' => 'customer',
        ])->assertOk();

        $this->assertTrue($target->refresh()->can_deliver);
    }

    public function test_status_revokes_tokens_and_inactive_user_cannot_login_or_use_a_previous_token(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $token = $target->createToken('test')->plainTextToken;
        Sanctum::actingAs($admin);
        $this->patchJson('/api/v1/admin/users/'.$target->id.'/status', ['is_active' => false])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/profile')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => $target->email, 'password' => 'password'])->assertForbidden()->assertJsonPath('code', 'account_inactive');
    }

    public function test_a_manually_surviving_token_is_blocked_by_active_user_middleware(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $token = $user->createToken('manually-surviving-token')->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/profile')
            ->assertForbidden()->assertJsonPath('code', 'account_inactive');
    }

    public function test_admin_cannot_deactivate_self_or_the_last_active_admin_or_demote_it(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $this->patchJson('/api/v1/admin/users/'.$admin->id.'/status', ['is_active' => false])->assertConflict();
        $other = $this->admin(['email' => 'other@example.test']);
        $this->patchJson('/api/v1/admin/users/'.$other->id.'/status', ['is_active' => false])->assertOk();
        $this->putJson('/api/v1/admin/users/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, 'role' => 'customer'])->assertConflict();
    }

    public function test_admin_cannot_self_demote_even_when_another_admin_exists(): void
    {
        $admin = $this->admin();
        $this->admin(['email' => 'other-admin@example.test']);
        Sanctum::actingAs($admin);

        $this->putJson('/api/v1/admin/users/'.$admin->id, [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'customer',
        ])->assertConflict();

        $this->assertSame('admin', $admin->refresh()->role);
    }

    public function test_profile_uses_authenticated_user_and_only_allows_personal_fields(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $other = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);
        $this->putJson('/api/v1/profile', ['user_id' => $other->id, 'name' => 'Nuevo nombre', 'email' => ' NUEVO@example.test ', 'phone' => '123', 'role' => 'admin', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.name', 'Nuevo nombre')->assertJsonPath('data.email', 'nuevo@example.test')->assertJsonPath('data.role', 'customer');
        $this->assertSame($other->email, $other->refresh()->email);
    }

    public function test_profile_password_requires_current_password_and_revokes_all_tokens(): void
    {
        $user = User::factory()->create(['password' => Hash::make('current-password'), 'is_active' => true]);
        $user->createToken('first');
        $user->createToken('second');
        Sanctum::actingAs($user);
        $this->putJson('/api/v1/profile/password', ['current_password' => 'wrong', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertUnprocessable();
        $this->putJson('/api/v1/profile/password', ['current_password' => 'current-password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertOk()->assertJsonPath('requires_login', true);
        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_password_reset_revokes_tokens_without_exposing_hash(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['is_active' => true]);
        $user->createToken('test');
        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/admin/users/'.$user->id.'/password', ['password' => 'reset-password', 'password_confirmation' => 'reset-password'])->assertOk()->assertJsonMissingPath('data.password');
        $this->assertTrue(Hash::check('reset-password', $user->refresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_search_filters_are_allowlisted_and_safe(): void
    {
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/admin/users?role=unknown')->assertUnprocessable();
        $this->getJson('/api/v1/admin/users?sort=name%20desc')->assertOk();
    }

    public function test_authentication_and_profile_responses_have_only_safe_user_fields(): void
    {
        $user = User::factory()->create(['email' => 'safe@example.test', 'password' => Hash::make('safe-password')]);
        $login = $this->postJson('/api/v1/auth/login', ['email' => ' SAFE@EXAMPLE.TEST ', 'password' => 'safe-password'])
            ->assertOk()->assertJsonPath('user.email', 'safe@example.test');
        $json = json_encode($login->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($user->password, $json);
        $this->assertStringNotContainsString('remember_token', $json);
        $this->assertStringNotContainsString('personal_access_tokens', $json);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/profile')->assertOk()->assertJsonMissingPath('data.can_deliver');
    }

    public function test_public_registration_normalizes_email_and_rejects_case_insensitive_duplicate(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'Registro', 'email' => ' Registro@Example.test ', 'password' => 'register-password', 'password_confirmation' => 'register-password'])
            ->assertCreated()->assertJsonPath('user.email', 'registro@example.test');
        $this->postJson('/api/v1/auth/register', ['name' => 'Duplicado', 'email' => 'REGISTRO@EXAMPLE.TEST', 'password' => 'register-password', 'password_confirmation' => 'register-password'])
            ->assertUnprocessable();
    }
}
