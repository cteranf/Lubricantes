<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReceiptBillingMigrationTest extends TestCase
{
    private string $connectionName = 'receipt_billing_migration_test';

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

    public function test_clean_schema_receipt_migration_installs_defaults_and_nullable_historical_snapshots(): void
    {
        Schema::create('business_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key', 20)->default('primary')->unique();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumns('business_profiles', ['boleta_enabled', 'factura_enabled', 'default_receipt_type']));
        $this->assertTrue(Schema::hasColumns('orders', ['requested_receipt_type', 'requested_receipt_email', 'requested_billing_address']));
        $orderColumns = collect(DB::select("PRAGMA table_info('orders')"))->keyBy('name');
        $this->assertSame(0, (int) $orderColumns['requested_receipt_type']->notnull);
        $this->assertSame(0, (int) $orderColumns['requested_identity_number']->notnull);
        $this->assertSame(1, (int) DB::table('business_profiles')->insertGetId(['singleton_key' => 'primary', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertSame('boleta', DB::table('business_profiles')->value('default_receipt_type'));
        $this->assertSame(1, (int) DB::table('business_profiles')->value('boleta_enabled'));
        $this->assertSame(0, (int) DB::table('business_profiles')->value('factura_enabled'));
        DB::table('orders')->insert(['created_at' => now(), 'updated_at' => now()]);
        $this->assertNull(DB::table('orders')->value('requested_receipt_type'));
        $this->assertNull(DB::table('orders')->value('requested_identity_number'));
    }

    public function test_upgrade_rollback_and_reapplication_preserve_profiles_and_historical_orders(): void
    {
        Schema::create('business_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key', 20)->default('primary')->unique();
            $table->string('commercial_name', 150)->nullable();
            $table->string('legal_name', 255)->nullable();
            $table->string('tax_id_type', 20)->nullable();
            $table->string('tax_id_number', 32)->nullable();
            $table->char('country_code', 2)->default('PE');
            $table->string('fiscal_address', 255)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('logo_path', 1000)->nullable();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status')->default('pending');
            $table->decimal('total', 12, 2)->default(0);
            $table->text('shipping_info');
            $table->timestamps();
        });
        DB::table('business_profiles')->insert(['singleton_key' => 'primary', 'commercial_name' => 'LubriStore QA', 'country_code' => 'PE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('orders')->insert(['id' => 83, 'status' => 'pending', 'total' => '600.00', 'shipping_info' => '{"address":"Histórica"}', 'created_at' => now(), 'updated_at' => now()]);

        $migration = $this->migration();
        $migration->up();
        $this->assertTrue(Schema::hasColumns('business_profiles', ['boleta_enabled', 'factura_enabled', 'default_receipt_type']));
        $this->assertTrue(Schema::hasColumns('orders', ['requested_receipt_type', 'requested_identity_type', 'requested_identity_number', 'requested_identity_required', 'requested_recipient_name', 'requested_billing_address', 'requested_receipt_email']));
        $this->assertSame('boleta', DB::table('business_profiles')->where('singleton_key', 'primary')->value('default_receipt_type'));
        $this->assertSame(1, (int) DB::table('business_profiles')->where('singleton_key', 'primary')->value('boleta_enabled'));
        $this->assertSame(0, (int) DB::table('business_profiles')->where('singleton_key', 'primary')->value('factura_enabled'));
        $this->assertNull(DB::table('orders')->where('id', 83)->value('requested_receipt_type'));
        $this->assertSame(600.0, (float) DB::table('orders')->where('id', 83)->value('total'));
        $indexes = DB::select("PRAGMA index_list('orders')");
        $this->assertContains('orders_requested_receipt_type_index', array_map(fn ($index) => $index->name, $indexes));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('orders', 'requested_receipt_type'));
        $this->assertFalse(Schema::hasColumn('business_profiles', 'boleta_enabled'));
        $this->assertSame('LubriStore QA', DB::table('business_profiles')->where('singleton_key', 'primary')->value('commercial_name'));
        $this->assertSame(600.0, (float) DB::table('orders')->where('id', 83)->value('total'));
        $this->assertSame('{"address":"Histórica"}', DB::table('orders')->where('id', 83)->value('shipping_info'));

        $migration->up();
        DB::table('orders')->where('id', 83)->update(['requested_receipt_type' => 'boleta', 'requested_receipt_email' => 'buyer@example.test']);
        $this->assertSame('boleta', DB::table('orders')->where('id', 83)->value('requested_receipt_type'));
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(1, DB::table('business_profiles')->count());
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_19_070000_add_receipt_settings_and_order_request_snapshots.php');
    }
}
