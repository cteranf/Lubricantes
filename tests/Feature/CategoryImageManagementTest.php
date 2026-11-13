<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryImageManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_admin_upload_reencodes_an_image_and_public_api_returns_only_a_public_projection(): void
    {
        $response = $this->post('/api/v1/admin/categories', [
            'name' => 'Aceites de motor',
            'image' => UploadedFile::fake()->image('promo.jpg', 800, 500),
        ])->assertCreated();

        $category = Category::findOrFail($response->json('id'));
        $this->assertStringStartsWith('categories/', $category->image);
        $this->assertStringEndsWith('.webp', $category->image);
        Storage::disk('public')->assertExists($category->image);
        $response->assertJsonPath('image_url', Storage::disk('public')->url($category->image));
        $response->assertJsonMissingPath('image');

        $public = $this->getJson('/api/v1/categories')->assertOk();
        $public->assertJsonPath('0.name', 'Aceites de motor')
            ->assertJsonPath('0.image_url', Storage::disk('public')->url($category->image))
            ->assertJsonMissingPath('0.image')
            ->assertJsonMissingPath('0.disk')
            ->assertJsonMissingPath('0.original_path');
    }

    public function test_category_upload_rejects_unsupported_mime_and_dimensions(): void
    {
        $this->post('/api/v1/admin/categories', [
            'name' => 'Archivo inválido',
            'image' => UploadedFile::fake()->create('payload.svg', 4, 'image/svg+xml'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('image');

        $this->post('/api/v1/admin/categories', [
            'name' => 'Imagen pequeña',
            'image' => UploadedFile::fake()->image('small.jpg', 320, 200),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_update_without_new_image_preserves_current_public_image(): void
    {
        Storage::disk('public')->put('categories/legacy.webp', 'existing image bytes');
        $category = $this->category(['image' => 'categories/legacy.webp']);

        $this->putJson('/api/v1/admin/categories/'.$category->id, ['name' => 'Nombre actualizado'])
            ->assertOk()->assertJsonPath('image_url', Storage::disk('public')->url('categories/legacy.webp'));

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'image' => 'categories/legacy.webp']);
        Storage::disk('public')->assertExists('categories/legacy.webp');
    }

    public function test_image_can_be_removed_without_deleting_or_deactivating_its_category(): void
    {
        Storage::disk('public')->put('categories/remove-me.webp', 'existing image bytes');
        $category = $this->category(['image' => 'categories/remove-me.webp']);

        $this->deleteJson('/api/v1/admin/categories/'.$category->id.'/image')
            ->assertOk()->assertJsonPath('image_url', null);

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'image' => null, 'is_active' => true]);
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => $category->name]);
    }

    public function test_public_categories_are_active_ordered_bounded_queries_and_hide_storage_paths(): void
    {
        $first = $this->category(['name' => 'Aditivos', 'slug' => 'aditivos', 'image' => '/storage/categories/legacy.jpg']);
        $this->category(['name' => 'Inactiva', 'slug' => 'inactiva', 'is_active' => false]);
        $child = $this->category(['name' => 'Automáticos', 'slug' => 'automaticos', 'parent_id' => $first->id]);
        foreach (range(1, 12) as $index) {
            $this->category(['name' => 'Categoría '.$index, 'slug' => 'categoria-'.$index]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/categories')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $categoryQueries = collect($queries)->filter(fn ($query) => str_contains(strtolower($query['query']), 'categories'));
        $this->assertLessThanOrEqual(3, $categoryQueries->count());
        $this->assertSame('Aditivos', $response->json('0.name'));
        $this->assertSame($child->id, $response->json('0.children.0.id'));
        $this->assertNotContains('Inactiva', array_column($response->json(), 'name'));
        $this->assertArrayNotHasKey('image', $response->json('0'));
        $this->assertNull($response->json('0.image_url'));
        $this->assertArrayHasKey('product_count', $response->json('0'));
    }

    private function category(array $attributes = []): Category
    {
        static $sequence = 0;
        $sequence++;

        return Category::create(array_merge([
            'name' => 'Categoría de prueba '.$sequence,
            'slug' => 'categoria-prueba-'.$sequence,
            'is_active' => true,
        ], $attributes));
    }
}
