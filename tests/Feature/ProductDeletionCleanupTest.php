<?php

namespace Tests\Feature;

use App\Jobs\ProcessProductImage;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductDeletionCleanupTest extends TestCase
{
    private string $connectionName;

    private string $databasePath;

    private string $previousDefaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->previousDefaultConnection = (string) config('database.default');
        $this->connectionName = 'product_delete_'.bin2hex(random_bytes(6));
        $this->databasePath = tempnam(sys_get_temp_dir(), 'product-delete-');
        if (! is_string($this->databasePath) || ! is_file($this->databasePath)) {
            $this->fail('Could not create the isolated SQLite file for product deletion tests.');
        }

        config([
            'database.connections.'.$this->connectionName => array_merge(
                config('database.connections.sqlite'),
                ['database' => $this->databasePath, 'prefix' => '', 'foreign_key_constraints' => true],
            ),
            'database.default' => $this->connectionName,
        ]);
        DB::purge($this->connectionName);
        $connection = DB::connection($this->connectionName);
        $connection->statement('PRAGMA foreign_keys = ON');
        $this->assertSame(1, (int) $connection->selectOne('PRAGMA foreign_keys')->foreign_keys);

        Schema::connection($this->connectionName)->create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamps();
        });
        Schema::connection($this->connectionName)->create('product_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('image_path')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->text('original_path')->nullable();
            $table->json('variants')->nullable();
            $table->unsignedInteger('source_width')->nullable();
            $table->unsignedInteger('source_height')->nullable();
            $table->string('processing_status', 16)->default('pending');
            $table->text('pending_original_path')->nullable();
            $table->string('generation_id', 64)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect($this->connectionName);
        DB::purge($this->connectionName);
        config(['database.default' => $this->previousDefaultConnection]);
        DB::setDefaultConnection($this->previousDefaultConnection);
        $connections = config('database.connections');
        unset($connections[$this->connectionName]);
        config(['database.connections' => $connections]);

        $temporaryRoot = realpath(sys_get_temp_dir());
        $databaseDirectory = is_string($this->databasePath) ? realpath(dirname($this->databasePath)) : false;
        if (is_string($temporaryRoot) && $databaseDirectory === $temporaryRoot
            && preg_match('/\Aproduct-delete-[A-Za-z0-9]+\z/', basename($this->databasePath)) === 1
            && is_file($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();
    }

    public function test_product_delete_without_transaction_removes_rows_and_owned_files(): void
    {
        [$product, $files] = $this->productWithImageFiles();

        $product->delete();

        $this->assertDatabaseMissing('products', ['id' => $product->id], $this->connectionName);
        $this->assertDatabaseMissing('product_images', ['product_id' => $product->id], $this->connectionName);
        $this->assertAllFilesExist($files, false);
    }

    public function test_product_delete_inside_committed_transaction_cleans_only_after_commit(): void
    {
        [$product, $files] = $this->productWithImageFiles(pending: true);
        $connection = DB::connection($this->connectionName);
        $connection->beginTransaction();

        $product->delete();
        $this->assertAllFilesExist($files, true);
        $this->assertSame(0, ProductImage::query()->where('product_id', $product->id)->count());

        $connection->commit();

        $this->assertAllFilesExist($files, false);
    }

    public function test_rollback_preserves_product_images_and_every_associated_file(): void
    {
        [$product, $files] = $this->productWithImageFiles();
        $connection = DB::connection($this->connectionName);
        $connection->beginTransaction();

        $product->delete();
        $this->assertAllFilesExist($files, true);
        $connection->rollBack();

        $this->assertDatabaseHas('products', ['id' => $product->id], $this->connectionName);
        $this->assertDatabaseHas('product_images', ['product_id' => $product->id], $this->connectionName);
        $this->assertAllFilesExist($files, true);
    }

    public function test_file_paths_are_captured_before_foreign_key_cascade_removes_image_rows(): void
    {
        [$product, $files] = $this->productWithImageFiles();
        $imageId = (int) $product->images()->value('id');

        $product->delete();

        $this->assertDatabaseMissing('product_images', ['id' => $imageId], $this->connectionName);
        $this->assertAllFilesExist($files, false);
    }

    public function test_delete_refreshes_a_previously_loaded_image_relation_before_capturing_paths(): void
    {
        [$product, $files] = $this->productWithImageFiles();
        $product->load('images');
        $lateVariant = 'products/optimized/'.$product->id.'/late-generation/card.webp';
        $lateOriginal = 'products/originals/'.$product->id.'/late-original.webp';
        Storage::disk('public')->put($lateVariant, 'variant');
        Storage::disk('local')->put($lateOriginal, 'original');
        ProductImage::query()->create([
            'product_id' => $product->id,
            'image_path' => '/storage/'.$lateVariant,
            'sort_order' => 1,
            'original_path' => $lateOriginal,
            'variants' => ['card' => ['path' => $lateVariant, 'width' => 360, 'height' => 360]],
        ]);

        $product->delete();

        $this->assertAllFilesExist($files, false);
        $this->assertFalse(Storage::disk('public')->exists($lateVariant));
        $this->assertFalse(Storage::disk('local')->exists($lateOriginal));
    }

    public function test_duplicate_paths_are_deduplicated_and_files_still_clean_up_once(): void
    {
        [$product, $files] = $this->productWithImageFiles();
        $image = $product->images()->firstOrFail();
        $variants = $image->variants;
        $variants['thumbnail']['path'] = $image->image_path;
        $image->forceFill(['variants' => $variants])->save();

        $product->delete();

        $this->assertAllFilesExist($files, false);
    }

    public function test_unsafe_paths_and_files_referenced_by_another_record_are_not_deleted(): void
    {
        [$product, $files] = $this->productWithImageFiles();
        $image = $product->images()->firstOrFail();
        $outsidePublic = 'outside/keep.webp';
        $outsidePrivate = 'outside/keep-original.webp';
        Storage::disk('public')->put($outsidePublic, 'keep');
        Storage::disk('local')->put($outsidePrivate, 'keep');
        $variants = $image->variants;
        foreach ($variants as &$variant) {
            $variant['path'] = '../'.$outsidePublic;
        }
        unset($variant);
        $image->forceFill([
            'image_path' => '/storage/products/../'.$outsidePublic,
            'variants' => $variants,
            'original_path' => '../'.$outsidePrivate,
            'pending_original_path' => '../'.$outsidePrivate,
        ])->save();

        $sharedProduct = Product::query()->create(['sku' => 'SHARED-'.bin2hex(random_bytes(3))]);
        $sharedPath = 'products/optimized/shared-generation/card.webp';
        Storage::disk('public')->put($sharedPath, 'shared');
        ProductImage::query()->create([
            'product_id' => $sharedProduct->id,
            'image_path' => '/storage/'.$sharedPath,
            'variants' => ['card' => ['path' => $sharedPath, 'width' => 360, 'height' => 360]],
        ]);

        $product->delete();

        $this->assertTrue(Storage::disk('public')->exists($outsidePublic));
        $this->assertTrue(Storage::disk('local')->exists($outsidePrivate));
        $this->assertTrue(Storage::disk('public')->exists($sharedPath));
        $this->assertFalse(Storage::disk('public')->exists($files[0]['path']));
        $this->assertTrue(Storage::disk('public')->exists($files[1]['path']), 'The replaced unreferenced file is retained as a safe orphan.');
        $this->assertTrue(Storage::disk('local')->exists($files[2]['path']), 'An original path removed from the row is retained as a safe orphan.');
        $this->assertFalse(Storage::disk('local')->directoryExists($files[3]['path']));
    }

    public function test_cleanup_failure_after_commit_does_not_undo_product_deletion(): void
    {
        [$product] = $this->productWithImageFiles();
        $publicDisk = Storage::disk('public');
        $localDisk = Storage::disk('local');
        $failingPublicDisk = \Mockery::mock($publicDisk)->makePartial();
        $failingPublicDisk->shouldReceive('exists')->andReturn(true);
        $failingPublicDisk->shouldReceive('delete')->andThrow(new \RuntimeException('private path and provider details'));

        Storage::shouldReceive('disk')->with('public')->andReturn($failingPublicDisk);
        Storage::shouldReceive('disk')->with('local')->andReturn($localDisk);

        $product->delete();

        $this->assertDatabaseMissing('products', ['id' => $product->id], $this->connectionName);
        $this->assertDatabaseMissing('product_images', ['product_id' => $product->id], $this->connectionName);
    }

    public function test_late_job_for_deleted_image_cannot_publish_files_again(): void
    {
        [$product, $files, $image] = $this->productWithImageFiles(pending: true);
        $imageId = (int) $image->id;
        $generationId = (string) $image->generation_id;

        $product->delete();
        (new ProcessProductImage($imageId, $generationId, 'upload'))->handle(app(\App\Services\ProductImageProcessingService::class));

        $this->assertDatabaseMissing('product_images', ['id' => $imageId], $this->connectionName);
        $this->assertAllFilesExist($files, false);
        $this->assertSame([], Storage::disk('public')->allFiles('products/optimized/'.$product->id));
    }

    private function productWithImageFiles(bool $pending = false): array
    {
        $legacyPath = 'products/legacy/'.bin2hex(random_bytes(4)).'.jpg';
        $product = Product::query()->create([
            'sku' => 'DELETE-'.strtoupper(bin2hex(random_bytes(4))),
            'image_path' => $legacyPath,
        ]);
        $product->refresh();
        $variantPath = 'products/optimized/'.$product->id.'/generation/card.webp';
        $originalPath = 'products/originals/'.$product->id.'/master.webp';
        $pendingPath = 'products/originals/'.$product->id.'/pending.webp';
        $temporaryDirectory = 'products/image-processing/'.$product->id;
        $temporaryPath = $temporaryDirectory.'/generation/master.webp';

        Storage::disk('public')->put($legacyPath, 'legacy');
        Storage::disk('public')->put($variantPath, 'variant');
        Storage::disk('local')->put($originalPath, 'original');
        if ($pending) {
            Storage::disk('local')->put($pendingPath, 'pending');
        }
        Storage::disk('local')->put($temporaryPath, 'temporary');

        $image = ProductImage::query()->create([
            'product_id' => $product->id,
            'image_path' => '/storage/'.$variantPath,
            'sort_order' => 0,
            'is_primary' => true,
            'original_path' => $originalPath,
            'pending_original_path' => $pending ? $pendingPath : null,
            'generation_id' => $pending ? 'generation-pending' : null,
            'processing_status' => $pending ? 'pending' : 'ready',
            'variants' => ['card' => ['path' => $variantPath, 'width' => 360, 'height' => 360]],
        ]);

        $files = [
            ['disk' => 'public', 'path' => $legacyPath],
            ['disk' => 'public', 'path' => $variantPath],
            ['disk' => 'local', 'path' => $originalPath],
            ['disk' => 'local', 'path' => $temporaryDirectory, 'directory' => true],
        ];
        if ($pending) {
            $files[] = ['disk' => 'local', 'path' => $pendingPath];
        }

        return [$product, $files, $image];
    }

    private function assertAllFilesExist(array $files, bool $exists): void
    {
        foreach ($files as $index => $file) {
            $disk = Storage::disk($file['disk']);
            $actual = ($file['directory'] ?? false) ? $disk->directoryExists($file['path']) : $disk->exists($file['path']);
            $this->assertSame($exists, $actual, 'Unexpected cleanup state for owned product image asset #'.($index + 1).'.');
        }
    }
}
