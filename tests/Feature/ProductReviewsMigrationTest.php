<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductReviewsMigrationTest extends TestCase
{
    private string $connectionName = 'product_reviews_migration_isolated';

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
        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('order_items', fn (Blueprint $table) => $table->id());
    }

    protected function tearDown(): void
    {
        DB::disconnect($this->connectionName);
        DB::purge($this->connectionName);
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_isolated_install_defaults_constraints_and_safe_rollback_reapplication(): void
    {
        $allow = $this->migration('2026_11_12_071200_add_allow_reviews_to_products.php');
        $settings = $this->migration('2026_11_12_071210_create_product_review_settings_table.php');
        $reviews = $this->migration('2026_11_12_071220_create_product_reviews_table.php');
        $allow->up();
        $settings->up();
        $reviews->up();

        $this->assertTrue(Schema::hasColumn('products', 'allow_reviews'));
        $productColumns = DB::select("PRAGMA table_info('products')");
        $allowReviewsColumn = collect($productColumns)->firstWhere('name', 'allow_reviews');
        $this->assertNotNull($allowReviewsColumn);
        $this->assertContains(strtolower($allowReviewsColumn->type), ['boolean', 'tinyint(1)']);
        $this->assertSame(0, DB::table('product_review_settings')->value('show_reviews_publicly'));
        $this->assertSame(0, DB::table('product_review_settings')->value('accept_new_reviews'));
        $this->assertSame(1, DB::table('product_review_settings')->value('require_moderation'));
        $columns = Schema::getColumnListing('product_reviews');
        $this->assertEqualsCanonicalizing(['id', 'product_id', 'user_id', 'verified_order_item_id', 'rating', 'title', 'comment', 'status', 'moderated_by', 'moderated_at', 'rejection_reason', 'verified_purchase_at', 'created_at', 'updated_at'], $columns);

        $foreignKeys = DB::select("PRAGMA foreign_key_list('product_reviews')");
        $actions = [];
        foreach ($foreignKeys as $foreignKey) {
            $actions[$foreignKey->from] = strtoupper($foreignKey->on_delete);
        }
        $this->assertSame('CASCADE', $actions['product_id']);
        $this->assertSame('SET NULL', $actions['user_id']);
        $this->assertSame('SET NULL', $actions['verified_order_item_id']);
        $this->assertSame('SET NULL', $actions['moderated_by']);
        $this->assertNotEmpty(DB::select("PRAGMA index_list('product_reviews')"));

        $reviews->down();
        $settings->down();
        $allow->down();
        $this->assertFalse(Schema::hasTable('product_reviews'));
        $this->assertFalse(Schema::hasTable('product_review_settings'));
        $this->assertFalse(Schema::hasColumn('products', 'allow_reviews'));
        $allow->up();
        $settings->up();
        $reviews->up();
        $this->assertSame(0, DB::table('product_reviews')->count());
        $this->assertSame(1, DB::table('product_review_settings')->count());
    }

    public function test_review_unique_user_product_and_product_cascade_are_enforced(): void
    {
        $this->migration('2026_11_12_071200_add_allow_reviews_to_products.php')->up();
        $this->migration('2026_11_12_071210_create_product_review_settings_table.php')->up();
        $this->migration('2026_11_12_071220_create_product_reviews_table.php')->up();
        DB::table('users')->insert(['id' => 1]);
        DB::table('products')->insert(['id' => 1, 'name' => 'Producto']);
        DB::table('order_items')->insert(['id' => 1]);
        $row = ['product_id' => 1, 'user_id' => 1, 'verified_order_item_id' => 1, 'rating' => 5, 'comment' => 'Comentario extenso de prueba de migración.', 'status' => 'pending', 'verified_purchase_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        DB::table('product_reviews')->insert($row);
        try {
            DB::table('product_reviews')->insert($row);
            $this->fail('The user/product unique index must reject duplicates.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(1, DB::table('product_reviews')->count());
        }
        DB::table('users')->where('id', 1)->delete();
        DB::table('order_items')->where('id', 1)->delete();
        $preserved = DB::table('product_reviews')->first();
        $this->assertNull($preserved->user_id);
        $this->assertNull($preserved->verified_order_item_id);
        $this->assertNotNull($preserved->verified_purchase_at);
        DB::table('products')->where('id', 1)->delete();
        $this->assertSame(0, DB::table('product_reviews')->count());
    }

    private function migration(string $file): object
    {
        return require database_path('migrations/'.$file);
    }
}
