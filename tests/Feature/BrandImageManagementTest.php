<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrandImageManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_admin_uploads_a_processed_logo_and_public_and_admin_apis_expose_only_its_public_url(): void
    {
        $response = $this->post('/api/v1/admin/brands', [
            'name' => 'LubriStore Test',
            'image' => UploadedFile::fake()->image('wide-logo.png', 1200, 400),
        ], ['Accept' => 'application/json'])->assertCreated();

        $brand = Brand::findOrFail($response->json('id'));
        $this->assertStringStartsWith('brands/', $brand->logo);
        $this->assertMatchesRegularExpression('/^brands\/[0-9a-f-]+\.(?:webp|png)$/i', $brand->logo);
        Storage::disk('public')->assertExists($brand->logo);
        $response->assertJsonPath('image_url', Storage::disk('public')->url($brand->logo))
            ->assertJsonMissingPath('logo')
            ->assertJsonMissingPath('disk')
            ->assertJsonMissingPath('path');

        $adminList = $this->getJson('/api/v1/admin/brands')->assertOk();
        $this->assertSame(Storage::disk('public')->url($brand->logo), $adminList->json('data.0.image_url'));
        $this->assertArrayNotHasKey('logo', $adminList->json('data.0'));

        $publicList = $this->getJson('/api/v1/brands')->assertOk();
        $this->assertSame(Storage::disk('public')->url($brand->logo), $publicList->json('0.image_url'));
        $this->assertArrayNotHasKey('logo', $publicList->json('0'));
        $this->getJson('/api/v1/brands/'.$brand->slug)
            ->assertOk()
            ->assertJsonPath('image_url', Storage::disk('public')->url($brand->logo))
            ->assertJsonMissingPath('logo');
    }

    public function test_update_without_a_file_preserves_logo_and_replace_and_explicit_remove_clean_only_unreferenced_files(): void
    {
        Storage::disk('public')->put('brands/shared-legacy.webp', 'legacy bytes');
        $brand = Brand::create(['name' => 'Marca con logo', 'slug' => 'marca-con-logo', 'logo' => 'brands/shared-legacy.webp']);
        $otherBrand = Brand::create(['name' => 'Otra marca', 'slug' => 'otra-marca', 'logo' => 'brands/shared-legacy.webp']);

        $this->putJson('/api/v1/admin/brands/'.$brand->id, ['name' => 'Marca renombrada'])
            ->assertOk()
            ->assertJsonPath('image_url', Storage::disk('public')->url('brands/shared-legacy.webp'));
        Storage::disk('public')->assertExists('brands/shared-legacy.webp');

        $replacement = $this->post('/api/v1/admin/brands/'.$brand->id, [
            '_method' => 'PUT',
            'name' => 'Marca renombrada',
            'image' => UploadedFile::fake()->image('replacement.png', 640, 320),
        ], ['Accept' => 'application/json'])->assertOk();
        $brand->refresh();
        $this->assertNotSame('brands/shared-legacy.webp', $brand->logo);
        $replacement->assertJsonPath('image_url', Storage::disk('public')->url($brand->logo));
        Storage::disk('public')->assertExists($brand->logo);
        Storage::disk('public')->assertExists('brands/shared-legacy.webp');

        $this->deleteJson('/api/v1/admin/brands/'.$brand->id.'/image')
            ->assertOk()
            ->assertJsonPath('image_url', null);
        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'logo' => null, 'is_active' => true]);
        Storage::disk('public')->assertExists('brands/shared-legacy.webp');
        Storage::disk('public')->assertMissing($brand->logo);

        $this->deleteJson('/api/v1/admin/brands/'.$otherBrand->id.'/image')->assertOk();
        Storage::disk('public')->assertMissing('brands/shared-legacy.webp');
        $this->assertDatabaseHas('brands', ['id' => $otherBrand->id, 'logo' => null]);
    }

    public function test_brand_resources_return_null_for_missing_or_unmanaged_logo_paths(): void
    {
        Storage::disk('public')->put('products/private/original.png', 'private bytes');
        Brand::create(['name' => 'Sin logo', 'slug' => 'sin-logo']);
        $unsafe = Brand::create(['name' => 'Ruta ajena', 'slug' => 'ruta-ajena', 'logo' => 'products/private/original.png']);

        $response = $this->getJson('/api/v1/brands')->assertOk();
        $this->assertNull($response->json('0.image_url'));
        $this->assertNull($response->json('1.image_url'));
        $this->assertArrayNotHasKey('logo', $response->json('1'));
        $this->getJson('/api/v1/admin/brands/'.$unsafe->id)
            ->assertOk()
            ->assertJsonPath('image_url', null)
            ->assertJsonMissingPath('logo');
        Storage::disk('public')->assertExists('products/private/original.png');
    }

    public function test_brand_image_validation_rejects_unsupported_and_oversized_uploads(): void
    {
        $this->postJson('/api/v1/admin/brands', [
            'name' => 'SVG no permitido',
            'image' => UploadedFile::fake()->createWithContent('logo.svg', '<svg></svg>'),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');

        $this->postJson('/api/v1/admin/brands', [
            'name' => 'Archivo grande',
            'image' => UploadedFile::fake()->create('large.png', 2049, 'image/png'),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');

        $this->postJson('/api/v1/admin/brands', [
            'name' => 'MIME falso',
            'image' => UploadedFile::fake()->createWithContent('fake.png', 'not a real PNG image'),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');

        $this->assertDatabaseMissing('brands', ['slug' => 'svg-no-permitido']);
        $this->assertDatabaseMissing('brands', ['slug' => 'archivo-grande']);
        $this->assertDatabaseMissing('brands', ['slug' => 'mime-falso']);
    }

    public function test_logo_processing_preserves_transparency_and_the_original_aspect_ratio(): void
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'brand-logo-');
        $source = imagecreatetruecolor(320, 80);
        $output = null;

        try {
            imagealphablending($source, false);
            imagesavealpha($source, true);
            imagefill($source, 0, 0, imagecolorallocatealpha($source, 0, 0, 0, 127));
            imagefilledrectangle($source, 0, 0, 79, 79, imagecolorallocatealpha($source, 20, 40, 60, 0));
            imagepng($source, $temporaryPath);
            imagedestroy($source);
            $source = null;

            $this->post('/api/v1/admin/brands', [
                'name' => 'Logotipo transparente',
                'image' => new UploadedFile($temporaryPath, 'transparent-logo.png', 'image/png', null, true),
            ], ['Accept' => 'application/json'])->assertCreated();

            $brand = Brand::where('slug', 'logotipo-transparente')->firstOrFail();
            $absolutePath = Storage::disk('public')->path($brand->logo);
            $dimensions = getimagesize($absolutePath);
            $this->assertIsArray($dimensions);
            $this->assertEqualsWithDelta(4, $dimensions[0] / $dimensions[1], 0.02);

            $output = str_ends_with($brand->logo, '.webp')
                ? imagecreatefromwebp($absolutePath)
                : imagecreatefrompng($absolutePath);
            $transparentPixel = imagecolorsforindex($output, imagecolorat($output, 250, 40));
            $this->assertGreaterThanOrEqual(120, $transparentPixel['alpha']);
            imagedestroy($output);
            $output = null;
        } finally {
            if ($source instanceof \GdImage) {
                imagedestroy($source);
            }
            if ($output instanceof \GdImage) {
                imagedestroy($output);
            }
            @unlink($temporaryPath);
        }
    }
}
