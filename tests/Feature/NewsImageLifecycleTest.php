<?php

namespace Tests\Feature;

use App\Models\News;
use App\Services\NewsImageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NewsImageLifecycleTest extends TestCase
{
    private string $connectionName;

    private string $databasePath;

    private string $storagePath;

    private string $previousDefaultConnection;

    private string $previousNewsImagesDisk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDefaultConnection = (string) config('database.default');
        $this->previousNewsImagesDisk = (string) config('news_images.disk', 'public');
        $this->connectionName = 'news_lifecycle_'.bin2hex(random_bytes(6));
        $databasePath = tempnam(sys_get_temp_dir(), 'news-lifecycle-');
        if (! is_string($databasePath) || ! is_file($databasePath)) {
            $this->fail('Could not create the isolated SQLite database for News image tests.');
        }
        $this->databasePath = $databasePath;

        $this->storagePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'news-image-storage-'.bin2hex(random_bytes(8));
        if (! mkdir($this->storagePath, 0700, true) && ! is_dir($this->storagePath)) {
            $this->fail('Could not create the isolated News image storage directory.');
        }

        config([
            'database.connections.'.$this->connectionName => array_merge(
                config('database.connections.sqlite'),
                ['database' => $this->databasePath, 'prefix' => '', 'foreign_key_constraints' => true],
            ),
            'database.default' => $this->connectionName,
            'filesystems.disks.news_lifecycle' => [
                'driver' => 'local',
                'root' => $this->storagePath,
                'throw' => false,
            ],
            'news_images.disk' => 'news_lifecycle',
        ]);
        DB::purge($this->connectionName);
        DB::setDefaultConnection($this->connectionName);
        $connection = DB::connection($this->connectionName);
        $connection->statement('PRAGMA foreign_keys = ON');
        $this->assertSame(1, (int) $connection->selectOne('PRAGMA foreign_keys')->foreign_keys);

        Schema::connection($this->connectionName)->create('news', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('summary')->nullable();
            $table->longText('content')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect($this->connectionName);
        DB::purge($this->connectionName);
        DB::setDefaultConnection($this->previousDefaultConnection);
        config(['database.default' => $this->previousDefaultConnection]);

        $connections = config('database.connections');
        unset($connections[$this->connectionName]);
        config(['database.connections' => $connections]);

        Storage::forgetDisk('news_lifecycle');
        $disks = config('filesystems.disks');
        unset($disks['news_lifecycle']);
        config(['filesystems.disks' => $disks]);
        config(['news_images.disk' => $this->previousNewsImagesDisk]);

        $temporaryRoot = realpath(sys_get_temp_dir());
        if (is_string($temporaryRoot)
            && realpath(dirname($this->databasePath)) === $temporaryRoot
            && preg_match('/\Anews-lifecycle-[A-Za-z0-9]+\z/', basename($this->databasePath)) === 1
            && is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
        if (is_string($temporaryRoot)
            && realpath(dirname($this->storagePath)) === $temporaryRoot
            && preg_match('/\Anews-image-storage-[a-f0-9]{16}\z/', basename($this->storagePath)) === 1
            && is_dir($this->storagePath)) {
            File::deleteDirectory($this->storagePath);
        }

        parent::tearDown();
    }

    public function test_replacing_and_removing_images_cleans_only_unreferenced_news_files_after_commit(): void
    {
        $images = app(NewsImageService::class);
        $oldPath = 'news/legacy.webp';
        Storage::disk('news_lifecycle')->put($oldPath, 'legacy image bytes');
        $news = $this->article($oldPath);
        $connection = DB::connection($this->connectionName);
        $connection->beginTransaction();

        $images->update($news, ['title' => 'Título nuevo'], UploadedFile::fake()->image('new.png', 800, 600), false);
        $replacement = News::query()->findOrFail($news->id)->image_path;
        $this->assertNotSame($oldPath, $replacement);
        Storage::disk('news_lifecycle')->assertExists($replacement);
        Storage::disk('news_lifecycle')->assertExists($oldPath);

        $connection->commit();
        Storage::disk('news_lifecycle')->assertMissing($oldPath);

        $connection->beginTransaction();
        $images->update($news, [], null, true);
        $this->assertNull(News::query()->findOrFail($news->id)->image_path);
        Storage::disk('news_lifecycle')->assertExists($replacement);
        $connection->commit();
        Storage::disk('news_lifecycle')->assertMissing($replacement);
    }

    public function test_rollback_keeps_the_previous_image_and_its_database_reference(): void
    {
        $images = app(NewsImageService::class);
        $oldPath = 'news/rollback.webp';
        Storage::disk('news_lifecycle')->put($oldPath, 'legacy image bytes');
        $news = $this->article($oldPath);
        $connection = DB::connection($this->connectionName);
        $connection->beginTransaction();

        $images->update($news, [], UploadedFile::fake()->image('replacement.png', 800, 600), false);
        Storage::disk('news_lifecycle')->assertExists($oldPath);
        $connection->rollBack();

        $this->assertSame($oldPath, News::query()->findOrFail($news->id)->image_path);
        Storage::disk('news_lifecycle')->assertExists($oldPath);
    }

    public function test_article_deletion_preserves_a_shared_file_and_rejects_paths_outside_news_directory(): void
    {
        $images = app(NewsImageService::class);
        $sharedPath = 'news/shared.webp';
        Storage::disk('news_lifecycle')->put($sharedPath, 'legacy image bytes');
        $first = $this->article($sharedPath);
        $this->article($sharedPath);
        $images->delete($first);
        Storage::disk('news_lifecycle')->assertExists($sharedPath);

        $outside = 'outside/keep.txt';
        Storage::disk('news_lifecycle')->put($outside, 'keep');
        $outsideReference = $this->article('../outside/keep.txt');
        $images->delete($outsideReference);
        Storage::disk('news_lifecycle')->assertExists($outside);
    }

    private function article(string $imagePath): News
    {
        static $index = 0;
        $index++;

        return News::query()->create([
            'title' => 'Noticia '.$index,
            'slug' => 'noticia-'.$index,
            'summary' => 'Resumen',
            'content' => null,
            'image_path' => $imagePath,
            'is_active' => false,
            'published_at' => null,
        ]);
    }
}
