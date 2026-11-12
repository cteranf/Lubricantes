<?php

namespace Tests\Feature;

use App\Exceptions\InvalidProductImage;
use App\Exceptions\ProductImageProcessingFailed;
use App\Jobs\ProcessProductImage;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\ProductImageProcessingService;
use App\Services\ProductImageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductImageProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_legacy_product_images_are_backfilled_without_replacing_existing_gallery_rows(): void
    {
        $legacyPath = 'storage/products/optimized/legacy/card.webp';
        Storage::disk('public')->put('products/optimized/legacy/card.webp', 'legacy image fixture');
        $legacyProduct = $this->product();
        $legacyProduct->forceFill(['image_path' => '/'.$legacyPath])->save();

        $galleryProduct = $this->product();
        $existingPath = 'products/optimized/'.$galleryProduct->id.'/existing/card.webp';
        Storage::disk('public')->put($existingPath, 'existing gallery fixture');
        $existingImage = ProductImage::query()->create([
            'product_id' => $galleryProduct->id,
            'image_path' => $existingPath,
            'sort_order' => 0,
            'is_primary' => true,
            'processing_status' => 'ready',
        ]);

        $migration = require database_path('migrations/2026_11_12_071000_backfill_legacy_product_images.php');
        $migration->up();

        $legacyImage = ProductImage::query()->where('product_id', $legacyProduct->id)->firstOrFail();
        $this->assertSame('/'.$legacyPath, $legacyProduct->fresh()->image_path);
        $this->assertSame('/'.$legacyPath, $legacyImage->image_path);
        $this->assertSame(0, $legacyImage->sort_order);
        $this->assertTrue($legacyImage->is_primary);
        $this->assertSame('ready', $legacyImage->processing_status);
        $this->assertNull($legacyImage->original_path);
        $this->assertNull($legacyImage->pending_original_path);
        $this->assertFalse($legacyImage->canRegenerate());

        $payload = (new \App\Http\Resources\AdminProductListResource($legacyProduct->fresh()->load('images')))->resolve(request());
        $this->assertCount(1, $payload['images']);
        $this->assertSame($payload['image_url'], $payload['images'][0]['url']);
        $this->assertSame(Storage::disk('public')->url('products/optimized/legacy/card.webp'), $payload['image_url']);

        $this->assertSame(1, ProductImage::query()->where('product_id', $galleryProduct->id)->count());
        $this->assertSame($existingImage->id, ProductImage::query()->where('product_id', $galleryProduct->id)->value('id'));

        $migration->up();
        $this->assertSame(1, ProductImage::query()->where('product_id', $legacyProduct->id)->count());
        $this->assertSame(1, ProductImage::query()->where('product_id', $galleryProduct->id)->count());
    }

    public function test_only_real_static_jpeg_png_and_webp_uploads_are_accepted(): void
    {
        $processor = app(ProductImageProcessor::class);

        foreach (['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'] as $extension => $mime) {
            $file = UploadedFile::fake()->createWithContent('product.'.$extension, $this->imageBytes(800, 800, $extension));
            $metadata = $processor->validateUpload($file);
            $this->assertSame($mime, $metadata['mime']);
            $this->assertSame(800, $metadata['width']);
            $this->assertSame(800, $metadata['height']);
        }

        foreach ([
            UploadedFile::fake()->createWithContent('fake.jpg', $this->imageBytes(800, 800, 'png')),
            UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ] as $file) {
            try {
                $processor->validateUpload($file);
                $this->fail('A fake MIME or SVG upload was accepted.');
            } catch (InvalidProductImage) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_public_product_serialization_uses_one_images_query_and_matches_primary_aliases(): void
    {
        $product = $this->productWithPrivateMaster(91, 800, 800);
        $image = $product->images()->firstOrFail();
        $image->forceFill(['is_primary' => false])->save();

        $warehouse = Warehouse::query()->where('is_default', true)->where('is_active', true)->first();
        if (! $warehouse) {
            $branch = Branch::query()->create([
                'code' => 'IMG-PUBLIC-QUERY',
                'name' => 'Sucursal de prueba',
                'address' => 'Dirección de prueba',
                'is_active' => true,
            ]);
            Warehouse::query()->create([
                'branch_id' => $branch->id,
                'code' => 'IMG-PUBLIC-QUERY',
                'name' => 'Almacén de prueba',
                'is_default' => true,
                'is_active' => true,
            ]);
        }

        $imageQueries = [];
        DB::listen(static function ($query) use (&$imageQueries): void {
            if (preg_match('/\bfrom\s+[`"]?product_images\b/i', $query->sql) === 1) {
                $imageQueries[] = strtolower(preg_replace('/\s+/', ' ', trim($query->sql)));
            }
        });

        $response = $this->getJson('/api/v1/products/'.$product->slug)->assertOk();
        $payload = $response->json();
        $publishedImage = $payload['images'][0];

        $this->assertArrayNotHasKey('data', $payload);
        $this->assertCount(1, $imageQueries, 'Public product serialization must not issue a separate primaryImage query.');
        $this->assertSame($payload['image_url'], $publishedImage['url']);
        $this->assertSame($payload['image_variants'], $publishedImage['variants']);
        $this->assertSame($payload['image_srcset'], $publishedImage['srcset']);
        $this->assertSame($payload['image_dimensions']['width'], $publishedImage['width']);
        $this->assertSame($payload['image_dimensions']['height'], $publishedImage['height']);
        $this->assertNotContains('processing_status', array_keys($publishedImage));
        $this->assertNotContains('can_regenerate_image', array_keys($publishedImage));
        foreach (['original_path', 'pending_original_path', 'generation_id'] as $privateKey) {
            $this->assertArrayNotHasKey($privateKey, $publishedImage);
        }
    }

    public function test_public_product_http_resources_resolve_loaded_relations_gallery_stock_and_safe_pagination(): void
    {
        $category = Category::query()->create(['name' => 'Categoría HTTP', 'slug' => 'categoria-http', 'is_active' => true]);
        $brand = Brand::query()->create(['name' => 'Marca HTTP', 'slug' => 'marca-http', 'is_active' => true]);
        $product = $this->productWithPrivateMaster(92, 800, 800);
        $product->forceFill(['category_id' => $category->id, 'brand_id' => $brand->id])->save();

        $secondPath = 'products/optimized/'.$product->id.'/generation-gallery/medium.webp';
        Storage::disk('public')->put($secondPath, $this->imageBytes(640, 640, 'webp'));
        ProductImage::query()->create([
            'product_id' => $product->id,
            'image_path' => '/storage/'.$secondPath,
            'sort_order' => 1,
            'is_primary' => false,
            'processing_status' => 'ready',
            'variants' => ['medium' => ['path' => $secondPath, 'width' => 640, 'height' => 640]],
        ]);

        $warehouse = Warehouse::query()->where('is_default', true)->where('is_active', true)
            ->whereHas('branch', fn ($query) => $query->where('is_active', true))->first();
        if (! $warehouse) {
            $branch = Branch::query()->create([
                'code' => 'PRODUCT-RESOURCE-HTTP',
                'name' => 'Sucursal de prueba',
                'address' => 'Dirección de prueba',
                'is_active' => true,
            ]);
            $warehouse = Warehouse::query()->create([
                'branch_id' => $branch->id,
                'code' => 'PRODUCT-RESOURCE-HTTP',
                'name' => 'Almacén de prueba',
                'is_default' => true,
                'is_active' => true,
            ]);
        }
        WarehouseInventory::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 12,
            'reserved_quantity' => 5,
        ]);

        $detail = $this->getJson('/api/v1/products/'.$product->slug)->assertOk();
        $payload = $detail->json();
        $this->assertSame(['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug], $payload['category']);
        $this->assertSame(['id' => $brand->id, 'name' => $brand->name], $payload['brand']);
        $this->assertSame(7, $payload['stock']);
        $this->assertCount(2, $payload['images']);
        $this->assertSame($payload['images'][0]['url'], $payload['image_url']);
        $this->assertSame($payload['images'][0]['variants'], $payload['image_variants']);
        $this->assertSame($payload['images'][0]['srcset'], $payload['image_srcset']);
        $this->assertSame(['width' => $payload['images'][0]['width'], 'height' => $payload['images'][0]['height']], $payload['image_dimensions']);
        foreach (['original_path', 'pending_original_path', 'generation_id', 'processing_status', 'can_regenerate_image'] as $privateKey) {
            $this->assertArrayNotHasKey($privateKey, $payload);
            foreach ($payload['images'] as $image) {
                $this->assertArrayNotHasKey($privateKey, $image);
            }
        }

        $nullable = $this->product();
        $nullablePayload = $this->getJson('/api/v1/products/'.$nullable->slug)->assertOk()->json();
        $this->assertNull($nullablePayload['category']);
        $this->assertNull($nullablePayload['brand']);
        $this->assertSame([], $nullablePayload['images']);
        $this->assertSame([], $nullablePayload['image_variants']);
        $this->assertNull($nullablePayload['image_url']);

        $listing = $this->getJson('/api/v1/products?sort=newest&page=1')->assertOk();
        $listingPayload = $listing->json();
        $this->assertArrayHasKey('data', $listingPayload);
        $this->assertGreaterThanOrEqual(2, count($listingPayload['data']));
        $listedProduct = collect($listingPayload['data'])->firstWhere('slug', $product->slug);
        $this->assertIsArray($listedProduct);
        $this->assertSame(7, $listedProduct['stock']);
        $this->assertSame($listedProduct['images'][0]['url'], $listedProduct['image_url']);
        foreach (['original_path', 'pending_original_path', 'generation_id', 'processing_status', 'can_regenerate_image'] as $privateKey) {
            $this->assertArrayNotHasKey($privateKey, $listedProduct);
            foreach ($listedProduct['images'] as $image) {
                $this->assertArrayNotHasKey($privateKey, $image);
            }
        }
    }

    public function test_upload_limits_are_checked_before_image_processing(): void
    {
        $processor = app(ProductImageProcessor::class);
        $cases = [
            'minimum dimension' => fn () => config(['product_images.min_width' => 801]),
            'maximum dimension' => fn () => config(['product_images.max_width' => 799]),
            'maximum megapixels' => fn () => config(['product_images.max_megapixels' => 0]),
            'maximum bytes' => fn () => config(['product_images.max_bytes' => 16]),
        ];

        foreach ($cases as $case => $configure) {
            config([
                'product_images.min_width' => 800,
                'product_images.min_height' => 800,
                'product_images.max_width' => 5000,
                'product_images.max_height' => 5000,
                'product_images.max_megapixels' => 20,
                'product_images.max_bytes' => 10 * 1024 * 1024,
            ]);
            $configure();
            try {
                $processor->validateUpload(UploadedFile::fake()->createWithContent('product.png', $this->imageBytes(800, 800, 'png')));
                $this->fail('Invalid upload limit case passed: '.$case);
            } catch (InvalidProductImage) {
                $this->assertTrue(true, 'Rejected configured limit: '.$case);
            }
        }
    }

    public function test_png_and_webp_animation_are_rejected(): void
    {
        $png = $this->imageBytes(800, 800, 'png');
        $chunkData = pack('N2', 1, 0);
        $animationChunk = pack('N', strlen($chunkData)).'acTL'.$chunkData.pack('N', crc32('acTL'.$chunkData));
        $firstChunkLength = unpack('N', substr($png, 8, 4))[1];
        $pngOffset = 8 + 12 + $firstChunkLength;
        $animatedPng = substr($png, 0, $pngOffset).$animationChunk.substr($png, $pngOffset);

        $webp = $this->imageBytes(800, 800, 'webp');
        $animationFrame = 'ANIM'.pack('V', 0);
        $animatedWebp = substr($webp, 0, 4).pack('V', strlen($webp) - 8 + strlen($animationFrame)).substr($webp, 8).$animationFrame;

        foreach ([
            UploadedFile::fake()->createWithContent('animated.png', $animatedPng),
            UploadedFile::fake()->createWithContent('animated.webp', $animatedWebp),
        ] as $file) {
            try {
                app(ProductImageProcessor::class)->validateUpload($file);
                $this->fail('An animated image was accepted.');
            } catch (InvalidProductImage) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_orientation_is_applied_and_exif_metadata_is_removed_from_private_master(): void
    {
        $jpeg = $this->imageBytes(800, 1200, 'jpg');
        $tiff = 'II'.pack('v', 42).pack('V', 8).pack('v', 1)
            .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', 6)."\0\0".pack('V', 0);
        $payload = "Exif\0\0".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
        $withOrientation = substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
        $file = UploadedFile::fake()->createWithContent('orientation.jpg', $withOrientation);
        $original = app(ProductImageProcessor::class)->storePrivateOriginal($file, 8, 'generation-orientation');
        $master = app(ProductImageProcessor::class)->sanitizePrivateUpload($original['path'], 8, 'generation-orientation');
        $masterPath = Storage::disk('local')->path($master['path']);
        $dimensions = getimagesize($masterPath);

        $this->assertSame(1200, $dimensions[0]);
        $this->assertSame(800, $dimensions[1]);
        $this->assertStringNotContainsString('Exif'."\0\0", file_get_contents($masterPath));
        $this->assertTrue(Storage::disk('local')->exists($master['path']));
        $this->assertFalse(Storage::disk('public')->exists($master['path']));
    }

    public function test_transparent_png_remains_transparent_after_webp_sanitization(): void
    {
        $file = UploadedFile::fake()->createWithContent('transparent.png', $this->imageBytes(800, 800, 'png', true));
        $original = app(ProductImageProcessor::class)->storePrivateOriginal($file, 9, 'generation-alpha');
        $master = app(ProductImageProcessor::class)->sanitizePrivateUpload($original['path'], 9, 'generation-alpha');
        $image = imagecreatefromwebp(Storage::disk('local')->path($master['path']));
        $pixel = imagecolorsforindex($image, imagecolorat($image, 1, 1));
        imagedestroy($image);

        $this->assertGreaterThan(0, $pixel['alpha']);
    }

    public function test_original_disk_may_not_be_configured_as_public_storage(): void
    {
        config(['product_images.original_disk' => 'public']);

        $this->expectException(ProductImageProcessingFailed::class);
        app(ProductImageProcessor::class)->storePrivateOriginal(
            UploadedFile::fake()->createWithContent('product.png', $this->imageBytes(800, 800, 'png')),
            10,
            'generation-public-disk',
        );
    }

    public function test_processing_failure_keeps_a_safe_public_message_and_internal_previous_exception(): void
    {
        $previous = new \RuntimeException('technical cause at private/original/path');
        $failure = new ProductImageProcessingFailed(
            previous: $previous,
            operation: 'upload',
            phase: 'publish-variant',
        );

        $this->assertSame('No fue posible procesar la imagen del producto.', $failure->getMessage());
        $this->assertSame($previous, $failure->getPrevious());
        $this->assertSame('upload', $failure->operation);
        $this->assertSame('publish-variant', $failure->phase);
        $this->assertStringNotContainsString('technical cause', $failure->getMessage());
        $this->assertStringNotContainsString('private/original/path', $failure->getMessage());
    }

    public function test_variants_use_compatible_real_dimensions_without_upscaling_or_duplicates(): void
    {
        $path = $this->putPrivateWebp(900, 900, 21, 'generation-nine-hundred');
        $variants = app(ProductImageProcessor::class)->generateVariants($path, 21, 'generation-nine-hundred');
        $widths = [];

        foreach ($variants as $key => $variant) {
            $image = getimagesize(Storage::disk('local')->path($variant['stage_path']));
            $this->assertSame('image/webp', $image['mime']);
            $this->assertSame($variant['width'], $image[0]);
            $this->assertSame($variant['height'], $image[1]);
            $this->assertLessThanOrEqual(900, $image[0]);
            $widths[] = $image[0];
        }

        $this->assertSame([160, 360, 720, 900], $widths);
        $this->assertSame($widths, array_values(array_unique($widths)));
        $this->assertArrayHasKey('detail_source', $variants);
        $this->assertArrayNotHasKey('zoom', $variants);
    }

    public function test_watermark_is_limited_to_detail_and_zoom_variants(): void
    {
        config(['product_images.watermark.text' => 'LubriStore']);
        $path = $this->putPrivateWebp(1600, 1600, 22, 'generation-watermark');
        $variants = app(ProductImageProcessor::class)->generateVariants($path, 22, 'generation-watermark');

        $this->assertSame(['thumbnail', 'card', 'medium', 'detail', 'zoom'], array_keys($variants));
        $this->assertFalse($this->hasBrightWatermark($variants['thumbnail']['stage_path']));
        $this->assertFalse($this->hasBrightWatermark($variants['card']['stage_path']));
        $this->assertFalse($this->hasBrightWatermark($variants['medium']['stage_path']));
        $this->assertTrue($this->hasBrightWatermark($variants['detail']['stage_path']));
        $this->assertTrue($this->hasBrightWatermark($variants['zoom']['stage_path']));
    }

    public function test_admin_upload_queues_an_encrypted_job_for_each_logical_product_image(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->post('/api/v1/admin/products', [
            'name' => 'Imagen privada', 'sku' => 'IMG-PRIVATE-1', 'price' => '24.90', 'cantidad_inicial' => 0,
            'images' => [UploadedFile::fake()->createWithContent('client-name.png', $this->imageBytes(800, 800, 'png'))],
        ]);

        $response->assertCreated()->assertJsonMissingPath('original_path')->assertJsonMissingPath('pending_original_path');
        $product = Product::findOrFail($response->json('id'));
        $image = ProductImage::where('product_id', $product->id)->firstOrFail();
        $this->assertStringStartsWith('products/originals/incoming/', $image->pending_original_path);
        $this->assertTrue(Storage::disk('local')->exists($image->pending_original_path));
        $this->assertFalse(Storage::disk('public')->exists($image->pending_original_path));
        Queue::assertPushedOn('image-processing', ProcessProductImage::class, function (ProcessProductImage $job) use ($image) {
            $serialized = serialize($job);

            return $job->productImageId === $image->id
                && $job->generationId === $image->generation_id
                && $job->operation === 'upload'
                && array_keys(unserialize($serialized)->__serialize()) === ['productImageId', 'generationId', 'operation']
                && ! str_contains($serialized, 'client-name.png')
                && $job instanceof \Illuminate\Contracts\Queue\ShouldBeEncrypted;
        });
    }

    public function test_queue_payload_is_encrypted_and_contains_only_image_generation_and_operation(): void
    {
        config(['queue.connections.image_processing.after_commit' => false]);
        $job = (new ProcessProductImage(77, 'generation-encrypted-payload', 'regenerate'))->onConnection('image_processing')->onQueue('image-processing');
        Queue::connection('image_processing')->push($job);
        $payload = json_decode((string) DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('generation-encrypted-payload', json_encode($payload, JSON_THROW_ON_ERROR));
        $command = $payload['data']['command'] ?? null;
        $this->assertIsString($command);
        $restored = unserialize(app('encrypter')->decrypt($command));
        $this->assertInstanceOf(ProcessProductImage::class, $restored);
        $this->assertSame(['productImageId', 'generationId', 'operation'], array_keys($restored->__serialize()));
        $this->assertSame(77, $restored->productImageId);
        $this->assertSame('generation-encrypted-payload', $restored->generationId);
        $this->assertSame('regenerate', $restored->operation);
        $this->assertSame(3, $payload['maxTries']);
        $this->assertSame(120, $payload['timeout']);
        $this->assertSame('30,120,300', $payload['backoff']);
    }

    public function test_regeneration_is_authorized_idempotent_and_does_not_change_product_data(): void
    {
        Queue::fake();
        $product = $this->productWithPrivateMaster(23, 1200, 1200);
        $image = $product->images()->firstOrFail();
        $snapshot = $product->only(['name', 'slug', 'sku', 'price', 'stock', 'image_path']);
        Sanctum::actingAs(User::factory()->create(['role' => 'customer']));
        $this->postJson('/api/v1/admin/products/'.$product->id.'/images/'.$image->id.'/regenerate')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/v1/admin/products/'.$product->id.'/images/'.$image->id.'/regenerate')->assertAccepted()->assertJsonPath('status', 'pending');
        $this->postJson('/api/v1/admin/products/'.$product->id.'/images/'.$image->id.'/regenerate')->assertAccepted()->assertJsonPath('status', 'pending');
        Queue::assertPushed(ProcessProductImage::class, 1);
        $this->assertSame($snapshot, $product->refresh()->only(array_keys($snapshot)));
        $this->assertArrayNotHasKey('original_path', $this->getJson('/api/v1/admin/products/'.$product->id.'/images/'.$image->id.'/status')->json());
    }

    public function test_missing_private_original_returns_safe_422_without_a_job(): void
    {
        Queue::fake();
        $product = $this->product();
        $image = ProductImage::create(['product_id' => $product->id, 'sort_order' => 0, 'is_primary' => true, 'processing_status' => 'ready']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/v1/admin/products/'.$product->id.'/images/'.$image->id.'/regenerate')
            ->assertUnprocessable()->assertJsonPath('status', 'failed')->assertJsonMissingPath('original_path');
        Queue::assertNothingPushed();
    }

    public function test_production_rejects_sync_processing_connection(): void
    {
        Queue::fake();
        config(['app.env' => 'production', 'queue.connections.image_processing.driver' => 'sync']);
        $product = $this->productWithPrivateMaster(28, 800, 800);
        $image = $product->images()->firstOrFail();

        $result = app(ProductImageProcessingService::class)->requestRegeneration($product, $image);

        $this->assertFalse($result['queued']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('failed', $image->refresh()->processing_status);
        Queue::assertNothingPushed();
    }

    public function test_admin_edit_with_new_image_keeps_current_public_image_until_job_succeeds(): void
    {
        Queue::fake();
        $product = $this->productWithPrivateMaster(29, 900, 900);
        $oldImage = $product->images()->firstOrFail();
        $oldPath = substr($oldImage->image_path, strlen('/storage/'));
        $oldUrl = $oldImage->publicUrl();
        $oldVariants = $oldImage->publicVariants();
        $oldSrcset = $oldImage->publicSrcset();
        $oldDimensions = $oldImage->publicDimensions();
        $imageId = (int) $oldImage->id;
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->post('/api/v1/admin/products/'.$product->id, [
            '_method' => 'PUT', 'name' => 'Imagen reemplazada',
            'image' => UploadedFile::fake()->createWithContent('replacement.png', $this->imageBytes(800, 800, 'png')),
        ]);

        $response->assertOk();
        $payload = $response->json();
        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('data', $payload, 'Product updates intentionally return an unwrapped administrative Resource.');
        $this->assertArrayNotHasKey('image_processing_status', $payload);
        $this->assertSame([
            'id', 'name', 'sku', 'description', 'price', 'is_active', 'category', 'brand',
            'physical_stock', 'image_url', 'image_variants', 'image_srcset', 'image_dimensions', 'images',
        ], array_keys($payload));
        $affectedImage = collect($payload['images'])->firstWhere('id', $imageId);
        $this->assertIsArray($affectedImage);
        $this->assertSame([
            'id', 'is_primary', 'sort_order', 'url', 'variants', 'srcset', 'width', 'height',
            'processing_status', 'can_regenerate_image',
        ], array_keys($affectedImage));
        $this->assertSame('pending', $affectedImage['processing_status']);
        $this->assertSame($oldUrl, $affectedImage['url']);
        $this->assertSame($oldVariants, $affectedImage['variants']);
        $this->assertSame($oldSrcset, $affectedImage['srcset']);
        $this->assertSame($oldDimensions['width'], $affectedImage['width']);
        $this->assertSame($oldDimensions['height'], $affectedImage['height']);
        $this->assertSame($oldUrl, $payload['image_url']);
        $this->assertSame($oldVariants, $payload['image_variants']);
        $this->assertSame($oldSrcset, $payload['image_srcset']);
        $this->assertSame($oldDimensions, $payload['image_dimensions']);
        foreach (['original_path', 'pending_original_path', 'generation_id'] as $privateKey) {
            $this->assertArrayNotHasKey($privateKey, $payload);
            $this->assertArrayNotHasKey($privateKey, $affectedImage);
        }
        Queue::assertPushed(ProcessProductImage::class, 1);
        $this->assertSame('/storage/'.$oldPath, $oldImage->refresh()->image_path);
        $this->assertTrue(Storage::disk('public')->exists($oldPath));
        $this->assertSame('Imagen reemplazada', $product->refresh()->name);
        $pending = $oldImage->refresh();
        $generationId = $pending->generation_id;
        $pendingOriginal = $pending->pending_original_path;
        $this->assertNotNull($generationId);
        $this->assertTrue(Storage::disk('local')->exists($pendingOriginal));

        app(ProductImageProcessingService::class)->process($imageId, $generationId, 'replace');
        $ready = ProductImage::query()->findOrFail($imageId);
        $this->assertSame('ready', $ready->processing_status);
        $this->assertSame($imageId, (int) $ready->id);
        $this->assertNotSame($oldUrl, $ready->publicUrl());
        $this->assertNotSame($oldVariants, $ready->publicVariants());
        $this->assertSame(['width' => 800, 'height' => 800], $ready->publicDimensions());
        $this->assertFalse(Storage::disk('public')->exists($oldPath));
        $this->assertFalse(Storage::disk('local')->exists($pendingOriginal));
        $this->assertSame('ready', ProductImage::query()->findOrFail($imageId)->processing_status);
    }

    public function test_successful_regeneration_publishes_atomically_and_removes_old_variants(): void
    {
        $product = $this->productWithPrivateMaster(24, 900, 900);
        $image = $product->images()->firstOrFail();
        $oldPath = substr($image->image_path, strlen('/storage/'));
        $generation = 'generation-success-24';
        $image->forceFill(['generation_id' => $generation, 'processing_status' => 'pending'])->save();

        app(ProductImageProcessingService::class)->process($image->id, $generation, 'regenerate');
        $image->refresh();
        $this->assertSame('ready', $image->processing_status);
        $this->assertNotSame('/storage/'.$oldPath, $image->image_path);
        $this->assertFalse(Storage::disk('public')->exists($oldPath));
        foreach ($image->variants as $variant) {
            $this->assertTrue(Storage::disk('public')->exists($variant['path']));
        }
        $this->assertTrue(Storage::disk('local')->exists($image->original_path));
    }

    public function test_replacement_upload_keeps_published_files_until_new_variants_are_ready(): void
    {
        $product = $this->productWithPrivateMaster(27, 900, 900);
        $image = $product->images()->firstOrFail();
        $oldPath = substr($image->image_path, strlen('/storage/'));
        $replacement = UploadedFile::fake()->createWithContent('replacement.png', $this->imageBytes(800, 1000, 'png'));

        $this->assertTrue(Storage::disk('public')->exists($oldPath));
        $result = app(ProductImageProcessingService::class)->replace($product, $image, $replacement);
        $image->refresh();
        $this->assertTrue($result['queued']);
        $this->assertSame('/storage/'.$oldPath, $image->image_path);
        $this->assertTrue(Storage::disk('public')->exists($oldPath));
        $this->assertTrue(Storage::disk('local')->exists($image->pending_original_path));
        app(ProductImageProcessingService::class)->process($image->id, $image->generation_id, 'replace');
        $image->refresh();
        $this->assertSame('ready', $image->processing_status);
        $this->assertFalse(Storage::disk('public')->exists($oldPath));
        $this->assertNull($image->pending_original_path);
    }

    public function test_obsolete_job_cannot_replace_newer_generation(): void
    {
        $product = $this->product();
        $image = ProductImage::create(['product_id' => $product->id, 'is_primary' => true, 'processing_status' => 'pending', 'generation_id' => 'generation-current']);
        app(ProductImageProcessingService::class)->process($image->id, 'generation-obsolete', 'upload');
        $this->assertSame('generation-current', $image->refresh()->generation_id);
    }

    public function test_failed_processing_keeps_the_current_public_image(): void
    {
        $product = $this->productWithPrivateMaster(25, 800, 800);
        $image = $product->images()->firstOrFail();
        $oldPath = substr($image->image_path, strlen('/storage/'));
        $badMaster = 'products/originals/'.$product->id.'/invalid/master.webp';
        Storage::disk('local')->put($badMaster, 'not-an-image');
        $image->forceFill(['original_path' => $badMaster, 'generation_id' => 'generation-failed', 'processing_status' => 'pending'])->save();

        try {
            app(ProductImageProcessingService::class)->process($image->id, 'generation-failed', 'regenerate');
            $this->fail('Corrupt private master should fail processing.');
        } catch (ProductImageProcessingFailed) {
            app(ProductImageProcessingService::class)->markFailed($image->id, 'generation-failed', 'regenerate');
        }
        $this->assertTrue(Storage::disk('public')->exists($oldPath));
        $this->assertSame('/storage/'.$oldPath, $image->refresh()->image_path);
        $this->assertSame('failed', $image->processing_status);
        $this->assertSame([], Storage::disk('local')->allFiles('products/image-processing/'.$product->id.'/generation-failed'));
    }

    public function test_deleting_product_cleans_only_its_image_files(): void
    {
        $product = $this->productWithPrivateMaster(26, 800, 800);
        $image = $product->images()->firstOrFail();
        $publicPath = substr($image->image_path, strlen('/storage/'));
        $original = $image->original_path;
        $other = $this->productWithPrivateMaster(27, 800, 800)->images()->firstOrFail();
        $otherPath = $other->original_path;

        $product->delete();

        $this->assertFalse(Storage::disk('public')->exists($publicPath));
        $this->assertFalse(Storage::disk('local')->exists($original));
        $this->assertTrue(Storage::disk('local')->exists($otherPath));
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'Imagen de prueba', 'slug' => 'imagen-prueba-'.uniqid(),
            'sku' => 'IMG-'.strtoupper(bin2hex(random_bytes(4))), 'price' => 12.50, 'stock' => 0,
            'is_active' => true,
        ]);
    }

    private function productWithPrivateMaster(int $id, int $width, int $height): Product
    {
        $product = $this->product();
        $generation = 'generation-master-'.$id;
        $path = 'products/originals/'.$product->id.'/'.$generation.'.webp';
        Storage::disk('local')->put($path, $this->imageBytes($width, $height, 'webp'));

        $staged = app(ProductImageProcessor::class)->generateVariants($path, (int) $product->id, $generation);
        $published = [];
        foreach ($staged as $key => $variant) {
            $publicPath = 'products/optimized/'.$product->id.'/'.$generation.'/'.$key.'.webp';
            Storage::disk('public')->put($publicPath, Storage::disk('local')->get($variant['stage_path']));
            $published[$key] = [
                'path' => $publicPath,
                'width' => $variant['width'],
                'height' => $variant['height'],
            ];
        }
        app(ProductImageProcessor::class)->deleteStaged($staged, 'products/image-processing/'.$product->id.'/'.$generation);

        $best = end($published);
        ProductImage::query()->create([
            'product_id' => $product->id,
            'image_path' => '/storage/'.$best['path'],
            'sort_order' => 0,
            'is_primary' => true,
            'original_path' => $path,
            'variants' => $published,
            'source_width' => $width,
            'source_height' => $height,
            'processing_status' => 'ready',
            'processed_at' => now(),
        ]);

        return $product->refresh();
    }

    private function putPrivateWebp(int $width, int $height, int $productId, string $generation): string
    {
        $path = 'products/originals/'.$productId.'/'.$generation.'.webp';
        Storage::disk('local')->put($path, $this->imageBytes($width, $height, 'webp'));

        return $path;
    }

    private function imageBytes(int $width, int $height, string $format, bool $transparent = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $background = imagecolorallocatealpha($image, 28, 42, 56, $transparent ? 127 : 0);
        imagefill($image, 0, 0, $background);
        ob_start();
        match ($format) {
            'jpg' => imagejpeg($image, null, 88),
            'png' => imagepng($image),
            'webp' => imagewebp($image, null, 88),
        };
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function hasBrightWatermark(string $stagePath): bool
    {
        $image = imagecreatefromwebp(Storage::disk('local')->path($stagePath));
        $width = imagesx($image);
        $height = imagesy($image);
        $brightPixels = 0;
        for ($y = (int) ($height * 0.84); $y < $height; $y += 2) {
            for ($x = (int) ($width * 0.84); $x < $width; $x += 2) {
                $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if ($color['red'] > 120 && $color['green'] > 120 && $color['blue'] > 120) {
                    $brightPixels++;
                }
            }
        }
        imagedestroy($image);

        return $brightPixels > 2;
    }
}
