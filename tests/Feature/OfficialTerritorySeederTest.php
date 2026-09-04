<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\District;
use App\Models\Province;
use Database\Seeders\OfficialTerritorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficialTerritorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_csv_integrity_and_shape(): void
    {
        $path = database_path('data/territories/inei_ubigeo_2022_1891.csv');
        $this->assertFileExists($path);
        $this->assertSame('3e05506c50da1982c13bb4b48cde1bb40c01c349e0151d84a878865561eeb41a', hash_file('sha256', $path));
        $rows = array_map('str_getcsv', array_slice(file($path), 1));
        $this->assertCount(1891, $rows);
        $this->assertCount(25, array_unique(array_column($rows, 0)));
        $this->assertCount(196, array_unique(array_column($rows, 2)));
        $this->assertCount(1891, array_unique(array_column($rows, 6)));
        $this->assertSame('01', $rows[0][0]);
        $this->assertSame('010101', $rows[0][6]);
    }

    public function test_seeder_creates_catalog_and_is_idempotent(): void
    {
        $this->seed(OfficialTerritorySeeder::class);
        $departmentIds = Department::orderBy('code')->pluck('id', 'code')->all();
        $provinceIds = Province::orderBy('code')->pluck('id', 'code')->all();
        $districtIds = District::orderBy('ubigeo')->pluck('id', 'ubigeo')->all();
        $this->assertCount(25, $departmentIds);
        $this->assertCount(196, $provinceIds);
        $this->assertCount(1891, $districtIds);

        $this->seed(OfficialTerritorySeeder::class);
        $this->assertSame($departmentIds, Department::orderBy('code')->pluck('id', 'code')->all());
        $this->assertSame($provinceIds, Province::orderBy('code')->pluck('id', 'code')->all());
        $this->assertSame($districtIds, District::orderBy('ubigeo')->pluck('id', 'ubigeo')->all());
    }

    public function test_seeder_does_not_create_territory_import_audit_row(): void
    {
        $this->seed(OfficialTerritorySeeder::class);
        $this->assertDatabaseCount('territory_imports', 0);
    }
}
