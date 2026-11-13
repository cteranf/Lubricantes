<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderTrackingProductNameMigrationTest extends TestCase
{
    private string $connectionName = 'order_product_name_migration_isolated';

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
            $table->string('name');
        });
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('price', 10, 2);
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect($this->connectionName);
        DB::purge($this->connectionName);
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_upgrade_backfills_best_effort_and_preserves_orphans_and_order_data(): void
    {
        DB::table('products')->insert([
            ['id' => 10, 'name' => '  Nombre vigente  '],
            ['id' => 11, 'name' => '   '],
        ]);
        DB::table('order_items')->insert([
            ['id' => 1, 'order_id' => 100, 'product_id' => 10, 'warehouse_id' => 7, 'quantity' => 2, 'price' => '12.50', 'subtotal' => '25.00', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'order_id' => 101, 'product_id' => 999, 'warehouse_id' => 8, 'quantity' => 1, 'price' => '9.00', 'subtotal' => '9.00', 'created_at' => '2026-01-02 00:00:00', 'updated_at' => '2026-01-02 00:00:00'],
            ['id' => 3, 'order_id' => 102, 'product_id' => null, 'warehouse_id' => 9, 'quantity' => 3, 'price' => '4.00', 'subtotal' => '12.00', 'created_at' => '2026-01-03 00:00:00', 'updated_at' => '2026-01-03 00:00:00'],
            ['id' => 4, 'order_id' => 103, 'product_id' => 11, 'warehouse_id' => 10, 'quantity' => 1, 'price' => '8.00', 'subtotal' => '8.00', 'created_at' => '2026-01-04 00:00:00', 'updated_at' => '2026-01-04 00:00:00'],
        ]);
        $before = DB::table('order_items')->get()->map(fn ($row) => (array) $row)->all();

        $migration = $this->migration();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('order_items', 'product_name'));
        $this->assertSame('Nombre vigente', DB::table('order_items')->where('id', 1)->value('product_name'));
        $this->assertNull(DB::table('order_items')->where('id', 2)->value('product_name'));
        $this->assertNull(DB::table('order_items')->where('id', 3)->value('product_name'));
        $this->assertNull(DB::table('order_items')->where('id', 4)->value('product_name'));
        $after = DB::table('order_items')->get();
        $this->assertCount(4, $after);
        foreach ($before as $index => $row) {
            $afterRow = (array) $after[$index];
            unset($afterRow['product_name']);
            $this->assertSame($row, $afterRow);
        }
    }

    public function test_empty_upgrade_rollback_and_reapplication_are_safe(): void
    {
        $migration = $this->migration();
        $migration->up();
        $this->assertTrue(Schema::hasColumn('order_items', 'product_name'));
        $migration->down();
        $this->assertFalse(Schema::hasColumn('order_items', 'product_name'));
        $this->assertTrue(Schema::hasTable('order_items'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('order_items', 'product_name'));
        $this->assertSame(0, DB::table('order_items')->count());
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_13_000100_add_product_name_to_order_items_table.php');
    }
}
