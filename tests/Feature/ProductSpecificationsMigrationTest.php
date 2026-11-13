<?php

namespace Tests\Feature;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductSpecificationsMigrationTest extends TestCase
{
    private string $connectionName = 'product_specifications_migration_test';

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = config('database.default');
        config([
            'database.connections.'.$this->connectionName => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
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

    public function test_clean_install_creates_the_specifications_table_without_a_legacy_column(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('product_specifications'));
        $this->assertFalse(Schema::hasColumn('products', 'specifications'));
        $this->assertEqualsCanonicalizing(
            ['id', 'product_id', 'name', 'value', 'sort_order', 'created_at', 'updated_at'],
            Schema::getColumnListing('product_specifications'),
        );
        $this->assertCascadeForeignKey();
    }

    public function test_upgrade_preserves_legacy_text_and_publicly_projects_it_once(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->text('specifications')->nullable();
        });

        $short = "  Viscosidad: 20W-50\nAPI SN/CF  ";
        $long = '  '.str_repeat('T', 490).'  ';
        DB::table('products')->insert([
            ['id' => 10, 'specifications' => $short],
            ['id' => 11, 'specifications' => null],
            ['id' => 12, 'specifications' => ''],
            ['id' => 13, 'specifications' => " \t  "],
            ['id' => 14, 'specifications' => $long],
        ]);

        $migration = $this->migration();
        $migration->up();

        $this->assertSame(2, DB::table('product_specifications')->count());
        $this->assertDatabaseHas('product_specifications', [
            'product_id' => 10,
            'name' => 'Información técnica',
            'value' => trim($short),
            'sort_order' => 0,
        ], $this->connectionName);
        $this->assertDatabaseHas('product_specifications', [
            'product_id' => 14,
            'name' => 'Información técnica',
            'value' => trim($long),
            'sort_order' => 0,
        ], $this->connectionName);
        $this->assertSame(490, mb_strlen(DB::table('product_specifications')->where('product_id', 14)->value('value')));
        $this->assertSame($short, DB::table('products')->where('id', 10)->value('specifications'));
        $this->assertSame($long, DB::table('products')->where('id', 14)->value('specifications'));
        $this->assertNotNull(DB::table('product_specifications')->where('product_id', 10)->value('created_at'));
        $this->assertSame(
            DB::table('product_specifications')->where('product_id', 10)->value('created_at'),
            DB::table('product_specifications')->where('product_id', 10)->value('updated_at'),
        );
        $this->assertCascadeForeignKey();
        $this->assertSame(1, DB::table('product_specifications')->where('product_id', 10)->count());

        $product = Product::query()->findOrFail(10);
        $product->setRelation('images', collect());
        $product->setRelation('specifications', $product->specifications()->get());
        $public = (new ProductResource($product))->resolve(request());
        $this->assertSame([
            ['name' => 'Información técnica', 'value' => trim($short)],
        ], $public['specifications']);
        $this->assertSame(['name', 'value'], array_keys($public['specifications'][0]));

        $migration->down();
        $this->assertFalse(Schema::hasTable('product_specifications'));
        $this->assertSame($short, DB::table('products')->where('id', 10)->value('specifications'));

        $this->migration()->up();
        $this->assertSame(2, DB::table('product_specifications')->count());
        $this->assertSame(1, DB::table('product_specifications')->where('product_id', 10)->count());
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_12_071100_create_product_specifications_table.php');
    }

    private function assertCascadeForeignKey(): void
    {
        $foreignKeys = DB::connection()->select("PRAGMA foreign_key_list('product_specifications')");
        $this->assertCount(1, $foreignKeys);
        $this->assertSame('products', $foreignKeys[0]->table);
        $this->assertSame('CASCADE', strtoupper($foreignKeys[0]->on_delete));

        $indexes = DB::connection()->select("PRAGMA index_list('product_specifications')");
        $this->assertNotEmpty(array_filter($indexes, fn ($index) => $index->name === 'product_specifications_product_id_sort_order_index'));
    }
}
