<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessProfileTest extends TestCase
{
    private string $connectionName = 'business_profile_feature_test';

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = config('database.default');
        config(['database.connections.'.$this->connectionName => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge($this->connectionName);
        DB::setDefaultConnection($this->connectionName);
        DB::connection()->statement('PRAGMA foreign_keys = ON');
        Artisan::call('migrate:fresh', ['--force' => true]);
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        DB::disconnect($this->connectionName);
        DB::purge($this->connectionName);
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_read_returns_a_stable_empty_profile_contract_and_current_operational_configuration(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/admin/business-profile')->assertOk()
            ->assertJsonPath('data.profile', null)
            ->assertJsonPath('data.defaults.country_code', 'PE')
            ->assertJsonPath('data.defaults.tax_id_type', 'RUC')
            ->assertJsonPath('data.operational_settings.currency', config('payment.currency'))
            ->assertJsonPath('data.operational_settings.currency_source', 'config/payment.php')
            ->assertJsonPath('data.operational_settings.timezone', config('app.timezone'))
            ->assertJsonPath('data.operational_settings.timezone_source', 'config/app.php')
            ->assertJsonPath('data.pending_fields.0.field', 'commercial_name');
    }

    public function test_admin_can_save_an_incomplete_profile_then_update_the_same_singleton(): void
    {
        Sanctum::actingAs($this->admin());
        $this->putJson('/api/v1/admin/business-profile', ['commercial_name' => 'LubriStore Norte'])
            ->assertOk()->assertJsonPath('data.profile.commercial_name', 'LubriStore Norte')
            ->assertJsonPath('data.profile.country_code', 'PE')
            ->assertJsonPath('data.pending_fields.0.field', 'legal_name');

        $profileId = BusinessProfile::query()->value('id');
        $this->putJson('/api/v1/admin/business-profile', ['commercial_name' => 'LubriStore Central'])
            ->assertOk()->assertJsonPath('data.profile.id', $profileId)
            ->assertJsonPath('data.profile.commercial_name', 'LubriStore Central');
        $this->assertSame(1, BusinessProfile::query()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'business_profile.updated',
            'auditable_type' => 'business_profile',
            'auditable_id' => 'primary',
        ]);
        $auditMetadata = DB::table('audit_logs')->where('action', 'business_profile.updated')->value('metadata');
        $this->assertStringContainsString('commercial_name', (string) $auditMetadata);
        $this->assertStringNotContainsString('LubriStore Central', (string) $auditMetadata);
    }

    public function test_post_multipart_method_override_accepts_a_logo_upload(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->postProfile([
            'commercial_name' => 'LubriStore multipart',
            'logo' => UploadedFile::fake()->image('business-logo.png', 120, 60),
        ]);

        $response->assertOk()->assertJsonPath('data.profile.commercial_name', 'LubriStore multipart');
        $logoPath = BusinessProfile::query()->value('logo_path');
        $this->assertNotNull($logoPath);
        Storage::disk('public')->assertExists($logoPath);
        $this->assertSame(1, BusinessProfile::query()->count());
    }

    public function test_only_active_administrators_can_read_and_update(): void
    {
        $this->getJson('/api/v1/admin/business-profile')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'customer', 'is_active' => true]));
        $this->getJson('/api/v1/admin/business-profile')->assertForbidden();
        $this->putJson('/api/v1/admin/business-profile', ['commercial_name' => 'No autorizado'])->assertForbidden();
        $this->withHeader('Accept', 'application/json')->post('/api/v1/admin/business-profile', [
            '_method' => 'PUT', 'commercial_name' => 'No autorizado',
        ])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => false]));
        $this->getJson('/api/v1/admin/business-profile')->assertForbidden();
        $this->putJson('/api/v1/admin/business-profile', ['commercial_name' => 'Inactivo'])->assertForbidden();
        $this->assertSame(0, BusinessProfile::query()->count());
    }

    public function test_validates_ruc_country_unknown_fields_and_preserves_existing_values_on_failure(): void
    {
        Sanctum::actingAs($this->admin());
        $this->putJson('/api/v1/admin/business-profile', ['commercial_name' => 'Nombre conservado'])->assertOk();
        $this->putJson('/api/v1/admin/business-profile', [
            'commercial_name' => 'No debe guardarse',
            'tax_id_type' => 'RUC',
            'tax_id_number' => '20123456789',
        ])->assertUnprocessable()->assertJsonValidationErrors('tax_id_number');
        $this->putJson('/api/v1/admin/business-profile', ['country_code' => 'US'])->assertUnprocessable()->assertJsonValidationErrors('country_code');
        $this->putJson('/api/v1/admin/business-profile', ['commercial_name' => 'X', 'logo_path' => 'arbitrary/path'])->assertUnprocessable()->assertJsonValidationErrors('request');
        $this->assertSame('Nombre conservado', BusinessProfile::query()->value('commercial_name'));

        $ruc = $this->validRuc('2012345678');
        $this->putJson('/api/v1/admin/business-profile', ['tax_id_type' => 'RUC', 'tax_id_number' => $ruc])
            ->assertOk()->assertJsonPath('data.profile.tax_id_number', $ruc);
        $this->putJson('/api/v1/admin/business-profile', ['tax_id_type' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('tax_id_number');
        $this->assertSame('RUC', BusinessProfile::query()->value('tax_id_type'));
        $this->assertSame($ruc, BusinessProfile::query()->value('tax_id_number'));
    }

    public function test_logo_upload_replacement_and_explicit_removal_keep_only_public_url_and_clean_old_files_after_commit(): void
    {
        Sanctum::actingAs($this->admin());
        $first = UploadedFile::fake()->image('first.png', 120, 60);
        $created = $this->postProfile(['commercial_name' => 'Negocio', 'logo' => $first]);
        $created->assertOk();
        $firstPath = BusinessProfile::query()->value('logo_path');
        $this->assertNotNull($firstPath);
        Storage::disk('public')->assertExists($firstPath);
        $this->assertNotNull($created->json('data.profile.logo_url'));
        $this->assertArrayNotHasKey('logo_path', $created->json('data.profile'));

        $this->postProfile(['commercial_name' => 'Negocio actualizado'])
            ->assertOk()->assertJsonPath('data.profile.commercial_name', 'Negocio actualizado');
        $this->assertSame($firstPath, BusinessProfile::query()->value('logo_path'));
        Storage::disk('public')->assertExists($firstPath);

        $second = UploadedFile::fake()->image('second.png', 80, 80);
        $this->postProfile(['commercial_name' => 'Negocio', 'logo' => $second])->assertOk();
        $secondPath = BusinessProfile::query()->value('logo_path');
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);

        $this->postProfile(['remove_logo' => true])->assertOk()->assertJsonPath('data.profile.logo_url', null);
        $this->assertNull(BusinessProfile::query()->value('logo_path'));
        Storage::disk('public')->assertMissing($secondPath);
    }

    public function test_unknown_field_with_method_override_and_file_is_rejected_without_changes_or_orphans(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postProfile(['commercial_name' => 'Conservado', 'logo' => UploadedFile::fake()->image('saved.png', 100, 50)])->assertOk();
        $oldPath = BusinessProfile::query()->value('logo_path');
        $this->assertNotNull($oldPath);

        $this->postProfile([
            'commercial_name' => 'No debe guardarse',
            'logo' => UploadedFile::fake()->image('rejected.png', 80, 40),
            'unexpected_business_field' => 'not allowed',
        ])->assertUnprocessable()->assertJsonValidationErrors('request');

        $this->assertSame('Conservado', BusinessProfile::query()->value('commercial_name'));
        $this->assertSame($oldPath, BusinessProfile::query()->value('logo_path'));
        Storage::disk('public')->assertExists($oldPath);
        $this->assertCount(1, Storage::disk('public')->allFiles('business/logos'));
    }

    public function test_invalid_logo_and_storage_failure_do_not_change_the_profile_or_leave_referenced_files(): void
    {
        Sanctum::actingAs($this->admin());
        $this->putJson('/api/v1/admin/business-profile', ['commercial_name' => 'Antes'])->assertOk();
        $this->put('/api/v1/admin/business-profile', [
            'commercial_name' => 'No cambia',
            'logo' => UploadedFile::fake()->createWithContent('not-an-image.jpg', 'not an image'),
        ])->assertUnprocessable()->assertJsonValidationErrors('logo');
        $this->assertSame('Antes', BusinessProfile::query()->value('commercial_name'));

        Storage::shouldReceive('disk')->twice()->with('public')->andThrow(new \RuntimeException('simulated storage failure'));
        try {
            app(\App\Services\BusinessProfileService::class)->save([
                'commercial_name' => 'Tampoco cambia',
                'logo' => UploadedFile::fake()->image('failure.png', 40, 40),
            ], auth()->user());
            $this->fail('The storage error should propagate before the database update.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('No se pudo guardar el logo.', $exception->getMessage());
        }
        $this->assertSame('Antes', BusinessProfile::query()->value('commercial_name'));
    }

    public function test_database_rollback_after_upload_keeps_the_old_logo_and_removes_only_the_unreferenced_new_file(): void
    {
        Sanctum::actingAs($this->admin());
        $this->put('/api/v1/admin/business-profile', ['commercial_name' => 'Negocio', 'logo' => UploadedFile::fake()->image('kept.png', 100, 50)])->assertOk();
        $oldPath = BusinessProfile::query()->value('logo_path');
        Storage::disk('public')->assertExists($oldPath);

        $this->mock(\App\Services\AuditService::class, function ($mock): void {
            $mock->shouldReceive('recordUser')->once()->andThrow(new \RuntimeException('simulated transaction failure'));
        });
        try {
            app(\App\Services\BusinessProfileService::class)->save([
                'commercial_name' => 'Cambio sin confirmar',
                'logo' => UploadedFile::fake()->image('not-committed.png', 70, 70),
            ], auth()->user());
            $this->fail('The transaction failure should abort the profile update.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated transaction failure', $exception->getMessage());
        }

        $this->assertSame('Negocio', BusinessProfile::query()->value('commercial_name'));
        $this->assertSame($oldPath, BusinessProfile::query()->value('logo_path'));
        Storage::disk('public')->assertExists($oldPath);
        $this->assertSame(1, count(Storage::disk('public')->allFiles('business/logos')));
    }

    public function test_database_unique_key_enforces_one_business_profile(): void
    {
        DB::table('business_profiles')->insert(['singleton_key' => 'primary', 'country_code' => 'PE', 'created_at' => now(), 'updated_at' => now()]);
        try {
            DB::table('business_profiles')->insert(['singleton_key' => 'primary', 'country_code' => 'PE', 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('The singleton unique constraint must reject a second profile.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(1, DB::table('business_profiles')->count());
        }
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function postProfile(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Accept', 'application/json')->post(
            '/api/v1/admin/business-profile',
            ['_method' => 'PUT'] + $payload,
        );
    }

    private function validRuc(string $firstTenDigits): string
    {
        $weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        foreach ($weights as $index => $weight) {
            $sum += ((int) $firstTenDigits[$index]) * $weight;
        }
        $check = 11 - ($sum % 11);
        $check = $check === 10 ? 0 : ($check === 11 ? 1 : $check);

        return $firstTenDigits.$check;
    }
}
