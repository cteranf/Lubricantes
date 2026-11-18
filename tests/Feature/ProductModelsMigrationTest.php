<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductModelsMigrationTest extends TestCase
{
    private string $connectionName = 'product_models_migration_test';

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = config('database.default');
        config(['database.connections.'.$this->connectionName => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
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

    public function test_migration_preserves_existing_products_and_enforces_brand_model_composite_foreign_key(): void
    {
        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku')->nullable()->unique();
            $table->string('name');
            $table->timestamps();
            $table->index('name', 'products_name_test_index');
        });
        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('image_path');
        });
        Schema::create('product_barcodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('code');
        });
        DB::table('brands')->insert([['id' => 1, 'name' => 'Marca A'], ['id' => 2, 'name' => 'Marca B']]);
        DB::table('products')->insert([
            ['id' => 10, 'brand_id' => 1, 'sku' => '0010', 'name' => 'Legacy', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
        ]);
        DB::table('product_images')->insert(['id' => 21, 'product_id' => 10, 'image_path' => 'products/legacy.webp']);
        DB::table('product_barcodes')->insert(['id' => 31, 'product_id' => 10, 'code' => '0012345']);

        $migration = $this->migration();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('products', 'product_model_id'));
        $this->assertSame('0010', DB::table('products')->where('id', 10)->value('sku'));
        $this->assertSame('Legacy', DB::table('products')->where('id', 10)->value('name'));
        $this->assertSame('2026-01-01 00:00:00', DB::table('products')->where('id', 10)->value('created_at'));
        $this->assertSame(1, (int) DB::connection()->selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->assertSame(1, DB::table('product_images')->where('id', 21)->where('product_id', 10)->count());
        $this->assertSame(1, DB::table('product_barcodes')->where('id', 31)->where('product_id', 10)->count());
        $productIndexes = DB::connection()->select("PRAGMA index_list('products')");
        $this->assertContains('products_name_test_index', array_column($productIndexes, 'name'));
        $this->assertSame([], DB::connection()->select('PRAGMA foreign_key_check'));

        $a = DB::table('product_models')->insertGetId([
            'brand_id' => 1, 'name' => 'Top Tec 4200', 'normalized_name' => 'TOP TEC 4200', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(1, DB::table('product_models')->where('id', $a)->count());
        DB::table('products')->where('id', 10)->update(['product_model_id' => $a]);

        $b = DB::table('product_models')->insertGetId([
            'brand_id' => 2, 'name' => 'Otro modelo', 'normalized_name' => 'OTRO MODELO', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            DB::table('products')->where('id', 10)->update(['product_model_id' => $b]);
            $this->fail('A product cannot reference a model belonging to a different brand.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame($a, (int) DB::table('products')->where('id', 10)->value('product_model_id'));
        }

        try {
            DB::table('products')->where('id', 10)->update(['brand_id' => null]);
            $this->fail('A product with a model must have a brand.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(1, (int) DB::table('products')->where('id', 10)->value('brand_id'));
        }

        $foreignKeys = DB::connection()->select("PRAGMA foreign_key_list('products')");
        $composite = array_values(array_filter($foreignKeys, fn ($foreignKey) => $foreignKey->table === 'product_models'));
        $this->assertCount(2, $composite);
        $this->assertEqualsCanonicalizing(['brand_id', 'product_model_id'], array_column($composite, 'from'));
        $indexes = DB::connection()->select("PRAGMA index_list('products')");
        $this->assertContains('products_product_model_id_index', array_column($indexes, 'name'));
        $modelIndexes = DB::connection()->select("PRAGMA index_list('product_models')");
        $this->assertContains('product_models_brand_normalized_unique', array_column($modelIndexes, 'name'));
        $this->assertContains('product_models_brand_id_unique', array_column($modelIndexes, 'name'));
        $normalizedIndexColumns = DB::connection()->select("PRAGMA index_info('product_models_brand_normalized_unique')");
        $this->assertSame(['brand_id', 'normalized_name'], array_column($normalizedIndexColumns, 'name'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('products', 'product_model_id'));
        $this->assertFalse(Schema::hasTable('product_models'));
        $this->assertSame('0010', DB::table('products')->where('id', 10)->value('sku'));
        $this->assertSame('Legacy', DB::table('products')->where('id', 10)->value('name'));
        $this->assertSame(1, DB::table('product_images')->where('id', 21)->count());
        $this->assertSame(1, DB::table('product_barcodes')->where('id', 31)->count());

        $this->migration()->up();
        $this->assertTrue(Schema::hasColumn('products', 'product_model_id'));
        $this->assertSame(1, DB::table('products')->where('id', 10)->count());
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_17_070000_create_product_models_table.php');
    }
}
