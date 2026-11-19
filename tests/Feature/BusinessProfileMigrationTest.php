<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BusinessProfileMigrationTest extends TestCase
{
    private string $connectionName = 'business_profile_migration_test';

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
        $this->assertSame(1, (int) DB::connection()->selectOne('PRAGMA foreign_keys')->foreign_keys);
    }

    protected function tearDown(): void
    {
        DB::disconnect($this->connectionName);
        DB::purge($this->connectionName);
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_isolated_install_rollback_and_reapplication_preserve_existing_catalog_tables(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->unique();
            $table->unsignedInteger('stock')->default(0);
        });
        DB::table('products')->insert(['id' => 23, 'sku' => 'LEGACY-00023', 'stock' => 7]);

        $migration = $this->migration();
        $migration->up();
        $this->assertTrue(Schema::hasTable('business_profiles'));
        $columns = Schema::getColumnListing('business_profiles');
        $this->assertEqualsCanonicalizing([
            'id', 'singleton_key', 'commercial_name', 'legal_name', 'tax_id_type', 'tax_id_number',
            'country_code', 'fiscal_address', 'contact_email', 'contact_phone', 'logo_path', 'created_at', 'updated_at',
        ], $columns);
        $indexRows = DB::select("PRAGMA index_list('business_profiles')");
        $uniqueNames = array_map(fn ($index) => $index->name, array_filter($indexRows, fn ($index) => (int) $index->unique === 1));
        $this->assertContains('business_profiles_singleton_key_unique', $uniqueNames);
        $this->assertSame('LEGACY-00023', DB::table('products')->where('id', 23)->value('sku'));
        $this->assertSame(7, (int) DB::table('products')->where('id', 23)->value('stock'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('business_profiles'));
        $this->assertSame(1, DB::table('products')->count());
        $this->assertSame('LEGACY-00023', DB::table('products')->where('id', 23)->value('sku'));

        $migration->up();
        DB::table('business_profiles')->insert(['singleton_key' => 'primary', 'country_code' => 'PE', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, DB::table('business_profiles')->count());
        $this->assertSame(1, DB::table('products')->count());
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_18_071000_create_business_profiles_table.php');
    }
}
