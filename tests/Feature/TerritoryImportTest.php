<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\District;
use App\Models\Province;
use App\Models\TerritoryImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TerritoryImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = 'department_code,department_name,province_code,province_name,district_code,district_name,ubigeo';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sanctum::actingAs($this->admin());
    }

    public function test_admin_downloads_the_exact_utf8_csv_template(): void
    {
        $response = $this->get('/api/v1/admin/territories/import/template')->assertOk();

        $this->assertStringStartsWith("\xEF\xBB\xBF".self::HEADERS, $response->getContent());
        $this->assertStringContainsString('plantilla-catalogo-territorial-peru.csv', $response->headers->get('Content-Disposition'));
    }

    public function test_preview_is_read_only_and_reports_new_entities_without_exposing_a_path(): void
    {
        $response = $this->preview($this->validCsv())->assertOk()
            ->assertJsonPath('total_rows', 2)
            ->assertJsonPath('new_departments', 1)
            ->assertJsonPath('new_provinces', 1)
            ->assertJsonPath('new_districts', 2)
            ->assertJsonPath('can_confirm', true)
            ->assertJsonMissingPath('path');

        $this->assertSame(64, strlen($response->json('checksum')));
        $this->assertDatabaseCount('departments', 0);
        $this->assertDatabaseCount('territory_imports', 0);
        $this->assertCount(1, Storage::disk('local')->files('territory-import-previews'));
    }

    public function test_confirmation_is_atomic_audited_and_removes_the_temporary_preview(): void
    {
        $admin = auth()->user();
        $token = $this->preview($this->validCsv())->assertOk()->json('preview_token');

        $this->postJson('/api/v1/admin/territories/import/confirm', ['preview_token' => $token])
            ->assertCreated()
            ->assertJsonPath('created_departments', 1)
            ->assertJsonPath('created_provinces', 1)
            ->assertJsonPath('created_districts', 2)
            ->assertJsonMissingPath('checksum');

        $this->assertDatabaseHas('departments', ['code' => '15', 'name' => 'Lima', 'is_active' => 1]);
        $this->assertDatabaseHas('districts', ['code' => '150101', 'ubigeo' => '150101']);
        $this->assertDatabaseHas('territory_imports', ['user_id' => $admin->id, 'status' => 'completed', 'total_rows' => 2]);
        $this->assertSame([], Storage::disk('local')->files('territory-import-previews'));
    }

    public function test_reimporting_the_same_catalog_is_idempotent_and_reports_no_changes(): void
    {
        $first = $this->preview($this->validCsv())->json('preview_token');
        $this->postJson('/api/v1/admin/territories/import/confirm', ['preview_token' => $first])->assertCreated();
        $second = $this->preview($this->validCsv())->assertJsonPath('unchanged_rows', 2)->json('preview_token');
        $this->postJson('/api/v1/admin/territories/import/confirm', ['preview_token' => $second])
            ->assertCreated()
            ->assertJsonPath('created_departments', 0)
            ->assertJsonPath('created_provinces', 0)
            ->assertJsonPath('created_districts', 0)
            ->assertJsonPath('summary.message', 'Sin cambios');

        $this->assertDatabaseCount('departments', 1);
        $this->assertDatabaseCount('provinces', 1);
        $this->assertDatabaseCount('districts', 2);
        $this->assertDatabaseCount('territory_imports', 2);
    }

    public function test_two_stale_previews_are_revalidated_and_cannot_create_concurrent_duplicates(): void
    {
        $firstToken = $this->preview($this->validCsv())->assertJsonPath('new_districts', 2)->json('preview_token');
        $secondToken = $this->preview($this->validCsv())->assertJsonPath('new_districts', 2)->json('preview_token');

        $this->postJson('/api/v1/admin/territories/import/confirm', ['preview_token' => $firstToken])
            ->assertCreated()->assertJsonPath('created_districts', 2);
        $this->postJson('/api/v1/admin/territories/import/confirm', ['preview_token' => $secondToken])
            ->assertCreated()->assertJsonPath('created_districts', 0)->assertJsonPath('summary.message', 'Sin cambios');

        $this->assertDatabaseCount('departments', 1);
        $this->assertDatabaseCount('provinces', 1);
        $this->assertDatabaseCount('districts', 2);
    }

    public function test_confirm_revalidates_and_rolls_back_when_catalog_changed_after_preview(): void
    {
        $token = $this->preview($this->csv(['D1,Lima,P1,Lima,DI1,Lima,150101']))->assertJsonPath('can_confirm', true)->json('preview_token');
        Department::create(['code' => 'D2', 'name' => 'LÍMA', 'is_active' => true]);

        $this->postJson('/api/v1/admin/territories/import/confirm', ['preview_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('preview_token');

        $this->assertDatabaseCount('departments', 1);
        $this->assertDatabaseCount('provinces', 0);
        $this->assertDatabaseCount('districts', 0);
        $this->assertDatabaseCount('territory_imports', 0);
    }

    public function test_preview_rejects_hierarchy_conflicts_duplicate_ubigeo_and_produces_report(): void
    {
        $csv = $this->csv([
            'D1,Lima,P1,Lima,DI1,Centro,150101',
            'D2,Cusco,P1,Cusco,DI2,Centro,150101',
        ]);
        $response = $this->preview($csv)->assertOk()->assertJsonPath('can_confirm', false);
        $this->assertGreaterThan(0, $response->json('conflict_rows'));

        $report = $this->get('/api/v1/admin/territories/import/report?preview_token='.urlencode($response->json('preview_token')))->assertOk()->getContent();
        $this->assertStringContainsString('conflict', $report);
        $this->assertStringContainsString('UBIGEO', $report);
        $this->assertDatabaseCount('departments', 0);
    }

    public function test_normalization_handles_accents_case_whitespace_and_invisible_characters(): void
    {
        $department = Department::create(['code' => '15', 'name' => 'San Martín', 'is_active' => true]);
        $province = Province::create(['department_id' => $department->id, 'code' => '1501', 'name' => 'Lima Norte', 'is_active' => true]);
        District::create(['province_id' => $province->id, 'code' => '150101', 'name' => 'Jesús María', 'ubigeo' => '150101', 'is_active' => true]);
        $csv = $this->csv([" 15 , SAN   MARTIN , 1501 , lima norte , 150101 , JESUS\u{200B} MARIA , 150101"]);

        $this->preview($csv)->assertOk()->assertJsonPath('unchanged_rows', 1)->assertJsonPath('can_confirm', true);
    }

    public function test_scoped_codes_are_resolved_with_their_existing_hierarchy(): void
    {
        $firstDepartment = Department::create(['code' => 'A', 'name' => 'Primero', 'is_active' => true]);
        $secondDepartment = Department::create(['code' => 'B', 'name' => 'Segundo', 'is_active' => true]);
        $firstProvince = Province::create(['department_id' => $firstDepartment->id, 'code' => 'P', 'name' => 'Provincia uno', 'is_active' => true]);
        $secondProvince = Province::create(['department_id' => $secondDepartment->id, 'code' => 'P', 'name' => 'Provincia dos', 'is_active' => true]);
        District::create(['province_id' => $firstProvince->id, 'code' => 'D', 'name' => 'Distrito uno', 'ubigeo' => '010101', 'is_active' => true]);
        District::create(['province_id' => $secondProvince->id, 'code' => 'D', 'name' => 'Distrito dos', 'ubigeo' => '020202', 'is_active' => true]);

        $this->preview($this->csv(['A,Primero,P,Provincia uno,D,Distrito uno,010101']))
            ->assertOk()->assertJsonPath('unchanged_rows', 1)->assertJsonPath('can_confirm', true);
    }

    public function test_invalid_files_headers_formulas_empty_fields_and_limits_are_rejected_or_blocked(): void
    {
        $this->withHeader('Accept', 'application/json')->post('/api/v1/admin/territories/import/preview', ['file' => UploadedFile::fake()->createWithContent('catalog.xlsx', 'x')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->withHeader('Accept', 'application/json')->post('/api/v1/admin/territories/import/preview', [
            'file' => UploadedFile::fake()->create('catalog.csv', 10, 'image/png'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->preview("department_code,department_name\n15,Lima\n")
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->preview($this->csv(['15,=HYPERLINK("x"),1501,Lima,150101,Lima,150101']))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->preview($this->csv(['15,Lima,1501,Lima,,Lima,150101']))
            ->assertOk()->assertJsonPath('can_confirm', false)->assertJsonPath('error_rows', 1);

        config(['territory_import.max_kilobytes' => 1]);
        $large = $this->csv(array_fill(0, 60, '15,Lima,1501,Lima,150101,Lima,150101'));
        $this->preview($large)->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_exact_duplicate_rows_are_blocking(): void
    {
        $row = '15,Lima,1501,Lima,150101,Lima,150101';

        $this->preview($this->csv([$row, $row]))->assertOk()
            ->assertJsonPath('duplicate_rows', 1)
            ->assertJsonPath('can_confirm', false);
    }

    public function test_preview_token_is_bound_to_the_admin_and_cannot_be_reused(): void
    {
        $token = $this->preview($this->validCsv())->json('preview_token');
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/territories/import/confirm', ['preview_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('preview_token');

        Sanctum::actingAs(User::where('role', 'admin')->first());
        $this->postJson('/api/v1/admin/territories/import/confirm', ['preview_token' => $token])->assertCreated();
        $this->postJson('/api/v1/admin/territories/import/confirm', ['preview_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('preview_token');
    }

    public function test_customer_cannot_use_import_or_audit_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'customer']));
        $this->get('/api/v1/admin/territories/import/template')->assertForbidden();
        $this->preview($this->validCsv())->assertForbidden();
        $this->postJson('/api/v1/admin/territories/import/confirm', ['preview_token' => 'invalid'])->assertForbidden();
        $this->getJson('/api/v1/admin/territories/imports')->assertForbidden();
    }

    public function test_import_never_deactivates_or_deletes_unlisted_territories(): void
    {
        $oldDepartment = Department::create(['code' => '99', 'name' => 'Territorio previo', 'is_active' => false]);
        $token = $this->preview($this->validCsv())->json('preview_token');
        $this->postJson('/api/v1/admin/territories/import/confirm', ['preview_token' => $token])->assertCreated();

        $this->assertDatabaseHas('departments', ['id' => $oldDepartment->id, 'is_active' => 0]);
    }

    public function test_imported_catalog_is_available_in_dependent_location_options(): void
    {
        $token = $this->preview($this->validCsv())->json('preview_token');
        $this->postJson('/api/v1/admin/territories/import/confirm', ['preview_token' => $token])->assertCreated();
        $department = Department::where('code', '15')->firstOrFail();
        $province = Province::where('code', '1501')->firstOrFail();

        $this->getJson('/api/v1/location/departments')->assertOk()->assertJsonFragment(['id' => $department->id, 'name' => 'Lima']);
        $this->getJson('/api/v1/location/provinces?department_id='.$department->id)->assertOk()->assertJsonFragment(['id' => $province->id]);
        $this->getJson('/api/v1/location/districts?province_id='.$province->id)->assertOk()->assertJsonFragment(['ubigeo' => '150101']);
    }

    public function test_audit_history_is_admin_only_and_paginated(): void
    {
        TerritoryImport::create([
            'user_id' => auth()->id(), 'original_filename' => 'catalog.csv', 'checksum' => str_repeat('a', 64),
            'status' => 'completed', 'total_rows' => 1, 'completed_at' => now(),
        ]);

        $this->getJson('/api/v1/admin/territories/imports')->assertOk()
            ->assertJsonPath('per_page', 20)->assertJsonPath('data.0.original_filename', 'catalog.csv')
            ->assertJsonMissingPath('data.0.checksum');
    }

    private function preview(string $contents)
    {
        return $this->withHeader('Accept', 'application/json')->post('/api/v1/admin/territories/import/preview', [
            'file' => UploadedFile::fake()->createWithContent('catalog.csv', $contents),
        ]);
    }

    private function validCsv(): string
    {
        return $this->csv([
            '15,Lima,1501,Lima,150101,Lima,150101',
            '15,Lima,1501,Lima,150102,Ancón,150102',
        ]);
    }

    private function csv(array $rows): string
    {
        return self::HEADERS."\n".implode("\n", $rows)."\n";
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }
}
