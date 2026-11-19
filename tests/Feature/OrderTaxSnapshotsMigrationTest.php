<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderTaxSnapshotsMigrationTest extends TestCase
{
    private string $connectionName = 'order_tax_snapshots_migration_test';

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
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->decimal('price', 10, 2);
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->decimal('shipping_amount', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
        });
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->decimal('subtotal', 10, 2);
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect($this->connectionName);
        DB::purge($this->connectionName);
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_clean_install_upgrade_rollback_and_reapplication_preserve_legacy_orders_and_lines(): void
    {
        DB::table('products')->insert(['id' => 7, 'price' => '118.00']);
        DB::table('orders')->insert(['id' => 23, 'shipping_amount' => '0.00', 'total' => '236.00']);
        DB::table('order_items')->insert(['id' => 91, 'order_id' => 23, 'subtotal' => '236.00']);

        $configuration = $this->configurationMigration();
        $snapshots = $this->snapshotMigration();
        $configuration->up();
        $snapshots->up();

        $this->assertTrue(Schema::hasColumns('products', ['tax_affectation']));
        $this->assertTrue(Schema::hasColumns('orders', ['tax_calculation_status', 'tax_snapshot', 'tax_calculation_version']));
        $this->assertTrue(Schema::hasColumns('order_items', ['tax_snapshot']));
        $this->assertSame('pending_configuration', DB::table('orders')->where('id', 23)->value('tax_calculation_status'));
        $this->assertNull(DB::table('orders')->where('id', 23)->value('tax_snapshot'));
        $this->assertNull(DB::table('order_items')->where('id', 91)->value('tax_snapshot'));
        $this->assertSame('236.00', number_format((float) DB::table('orders')->where('id', 23)->value('total'), 2, '.', ''));
        $this->assertSame('236.00', number_format((float) DB::table('order_items')->where('id', 91)->value('subtotal'), 2, '.', ''));
        $this->assertSame(1, (int) DB::connection()->selectOne('PRAGMA foreign_keys')->foreign_keys);

        $snapshots->down();
        $configuration->down();
        $this->assertFalse(Schema::hasColumn('orders', 'tax_snapshot'));
        $this->assertFalse(Schema::hasColumn('order_items', 'tax_snapshot'));
        $this->assertFalse(Schema::hasColumn('products', 'tax_affectation'));
        $this->assertSame(1, DB::table('orders')->where('id', 23)->count());
        $this->assertSame(1, DB::table('order_items')->where('id', 91)->count());
        $this->assertSame('236.00', number_format((float) DB::table('orders')->where('id', 23)->value('total'), 2, '.', ''));

        $configuration = $this->configurationMigration();
        $snapshots = $this->snapshotMigration();
        $configuration->up();
        $snapshots->up();
        $payload = json_encode(['status' => 'complete', 'totals' => ['gross_minor' => '236.00']], JSON_THROW_ON_ERROR);
        DB::table('orders')->where('id', 23)->update(['tax_calculation_status' => 'complete', 'tax_snapshot' => $payload]);
        $this->assertSame('complete', DB::table('orders')->where('id', 23)->value('tax_calculation_status'));
        $this->assertSame('236.00', number_format((float) DB::table('orders')->where('id', 23)->value('total'), 2, '.', ''));
        $this->assertSame(1, DB::table('products')->count());
        $this->assertSame(1, DB::table('order_items')->count());
    }

    public function test_clean_schema_install_creates_nullable_tax_snapshots_without_attributing_products(): void
    {
        $this->configurationMigration()->up();
        $this->snapshotMigration()->up();

        $this->assertTrue(Schema::hasTable('business_tax_configurations'));
        $this->assertTrue(Schema::hasColumns('products', ['price', 'tax_affectation']));
        $this->assertTrue(Schema::hasColumns('orders', ['tax_calculation_status', 'tax_snapshot', 'tax_calculation_version']));
        $this->assertTrue(Schema::hasColumns('order_items', ['subtotal', 'tax_snapshot']));
        $this->assertSame(0, DB::table('products')->count());
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('order_items')->count());
        $this->assertSame(1, (int) DB::connection()->selectOne('PRAGMA foreign_keys')->foreign_keys);
    }

    private function configurationMigration(): object
    {
        return require database_path('migrations/2026_11_19_071100_create_business_tax_configurations_table.php');
    }

    private function snapshotMigration(): object
    {
        return require database_path('migrations/2026_11_19_071110_add_tax_snapshots_to_orders_and_order_items.php');
    }
}
