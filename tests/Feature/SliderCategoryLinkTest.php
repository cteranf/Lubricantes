<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Slider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SliderCategoryLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_edit_a_slider_with_an_optional_active_category(): void
    {
        Sanctum::actingAs($this->admin());
        Storage::fake('public');
        $category = $this->category();

        $created = $this->post('/api/v1/admin/sliders', [
            'title' => 'Promoción categoría',
            'description' => 'Contenido destacado.',
            'image' => UploadedFile::fake()->image('banner.jpg'),
            'category_id' => $category->id,
            'sort_order' => 2,
            'is_active' => 1,
        ])->assertCreated()->assertJsonPath('category_id', $category->id)
            ->assertJsonPath('category.id', $category->id);
        $sliderId = $created->json('id');

        $this->getJson('/api/v1/admin/sliders')->assertOk()
            ->assertJsonPath('0.category_id', $category->id)
            ->assertJsonPath('0.category.name', $category->name);

        $this->putJson('/api/v1/admin/sliders/'.$sliderId, ['category_id' => null])
            ->assertOk()->assertJsonPath('category_id', null)->assertJsonPath('category', null);
        $this->assertDatabaseHas('sliders', ['id' => $sliderId, 'category_id' => null]);
    }

    public function test_slider_category_is_optional_and_rejects_nonexistent_or_inactive_categories(): void
    {
        Sanctum::actingAs($this->admin());
        Storage::fake('public');
        $image = UploadedFile::fake()->image('banner.png');

        $this->post('/api/v1/admin/sliders', [
            'title' => 'Sin destino', 'image' => $image, 'is_active' => 1,
        ])->assertCreated()->assertJsonPath('category_id', null);

        $inactive = $this->category(['is_active' => false]);
        foreach ([999999, $inactive->id] as $categoryId) {
            $this->postJson('/api/v1/admin/sliders', [
                'title' => 'Destino no permitido',
                'image' => UploadedFile::fake()->image('invalid.png'),
                'category_id' => $categoryId,
            ])->assertUnprocessable()->assertJsonValidationErrors('category_id');
        }
    }

    public function test_public_endpoint_projects_only_active_sliders_and_public_category_destination_without_n_plus_one(): void
    {
        $category = $this->category();
        $inactiveCategory = $this->category(['is_active' => false]);
        $linked = $this->slider(['category_id' => $category->id, 'sort_order' => 1]);
        $unlinked = $this->slider(['category_id' => null, 'sort_order' => 2]);
        $inactiveDestination = $this->slider(['category_id' => $inactiveCategory->id, 'sort_order' => 3]);
        $this->slider(['is_active' => false, 'sort_order' => 0]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/sliders')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(1, collect($queries)->filter(fn ($query) => str_contains(strtolower($query['query']), 'categories'))->count());
        $this->assertSame([$linked->id, $unlinked->id, $inactiveDestination->id], array_column($response->json(), 'id'));
        $this->assertSame([
            'id', 'title', 'description', 'image_path', 'sort_order', 'category',
        ], array_keys($response->json('0')));
        $this->assertSame([
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
        ], $response->json('0.category'));
        $this->assertNull($response->json('1.category'));
        $this->assertNull($response->json('2.category'));
        $this->assertArrayNotHasKey('category_id', $response->json('0'));
        $this->assertArrayNotHasKey('created_at', $response->json('0'));
    }

    public function test_deleting_a_category_clears_slider_destination_without_deleting_the_slider(): void
    {
        $category = $this->category();
        $slider = $this->slider(['category_id' => $category->id]);

        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $foreignKey = collect(DB::select("PRAGMA foreign_key_list('sliders')"))
            ->first(fn ($key) => $key->from === 'category_id');
        $this->assertNotNull($foreignKey, 'SQLite must have the sliders.category_id foreign key.');
        $this->assertSame('SET NULL', strtoupper($foreignKey->on_delete));

        $category->delete();

        $this->assertDatabaseHas('sliders', ['id' => $slider->id, 'category_id' => null]);
        $this->getJson('/api/v1/sliders')->assertOk()->assertJsonPath('0.category', null);
    }

    public function test_admin_deactivation_preserves_category_association_but_hides_public_destination(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = $this->category();
        $slider = $this->slider(['category_id' => $category->id]);

        $this->actingAs($admin)->deleteJson('/api/v1/admin/categories/'.$category->id)->assertOk();

        $this->assertDatabaseHas('sliders', ['id' => $slider->id, 'category_id' => $category->id]);
        $this->getJson('/api/v1/sliders')->assertOk()->assertJsonPath('0.id', $slider->id)
            ->assertJsonPath('0.category', null);

        $this->patchJson('/api/v1/admin/categories/'.$category->id.'/status', ['is_active' => true])->assertOk();
        $this->getJson('/api/v1/sliders')->assertOk()->assertJsonPath('0.category.id', $category->id)
            ->assertJsonPath('0.category.slug', $category->slug);
    }

    public function test_sqlite_slider_table_rebuild_preserves_legacy_schema_rows_defaults_indexes_and_sequence(): void
    {
        $slider = $this->slider([
            'title' => 'Registro anterior conservado',
            'description' => null,
            'image_path' => '/storage/sliders/legacy.jpg',
            'sort_order' => 37,
        ]);
        $legacyRow = collect((array) DB::table('sliders')->where('id', $slider->id)->first())->except('category_id')->all();
        $migration = require database_path('migrations/2026_11_12_070800_add_category_id_to_sliders_table.php');

        $migration->down();

        $this->assertSame(['id', 'title', 'description', 'image_path', 'is_active', 'sort_order', 'created_at', 'updated_at'], Schema::getColumnListing('sliders'));
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->assertSame([], DB::select("PRAGMA foreign_key_list('sliders')"));
        $this->assertSame([], DB::select("PRAGMA index_list('sliders')"));
        $this->assertEquals($legacyRow, (array) DB::table('sliders')->where('id', $slider->id)->first());

        $migration->up();

        $this->assertSame(['id', 'title', 'description', 'image_path', 'category_id', 'is_active', 'sort_order', 'created_at', 'updated_at'], Schema::getColumnListing('sliders'));
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->assertEquals($legacyRow, collect((array) DB::table('sliders')->where('id', $slider->id)->first())->except('category_id')->all());
        $this->assertDatabaseHas('sliders', ['id' => $slider->id, 'category_id' => null]);

        $foreignKey = collect(DB::select("PRAGMA foreign_key_list('sliders')"))->first(fn ($key) => $key->from === 'category_id');
        $this->assertNotNull($foreignKey);
        $this->assertSame('SET NULL', strtoupper($foreignKey->on_delete));

        DB::table('sliders')->insert(['title' => 'Valores predeterminados', 'image_path' => '/storage/sliders/default.jpg']);
        $defaults = DB::table('sliders')->where('title', 'Valores predeterminados')->first();
        $this->assertSame(1, (int) $defaults->is_active);
        $this->assertSame(0, (int) $defaults->sort_order);
        $this->assertNull($defaults->category_id);

        $nextSlider = $this->slider(['title' => 'Secuencia posterior a reconstrucción']);
        $this->assertGreaterThan($slider->id, $nextSlider->id);
    }

    public function test_sqlite_migration_error_does_not_leave_foreign_keys_disabled(): void
    {
        Schema::create('sliders_with_category', function ($table) {
            $table->id();
        });
        $migration = require database_path('migrations/2026_11_12_070800_add_category_id_to_sliders_table.php');

        $failed = false;
        try {
            $migration->up();
        } catch (\Throwable) {
            $failed = true;
        } finally {
            Schema::dropIfExists('sliders_with_category');
        }

        $this->assertTrue($failed, 'The conflicting temporary table should make the migration fail.');
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
    }

    private function category(array $overrides = []): Category
    {
        static $sequence = 0;
        $sequence++;

        return Category::create(array_merge([
            'name' => 'Categoría slider '.$sequence,
            'slug' => 'categoria-slider-'.$sequence,
            'is_active' => true,
        ], $overrides));
    }

    private function slider(array $overrides = []): Slider
    {
        static $sequence = 0;
        $sequence++;

        return Slider::create(array_merge([
            'title' => 'Banner de categoría '.$sequence,
            'description' => 'Descripción del banner.',
            'image_path' => '/storage/sliders/banner-'.$sequence.'.jpg',
            'is_active' => true,
            'sort_order' => $sequence,
        ], $overrides));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }
}
