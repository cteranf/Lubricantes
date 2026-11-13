<?php

namespace App\Models;

use App\Services\CategoryImageService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Category extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'image', 'parent_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected array $imagesToCleanAfterDelete = [];

    protected static function booted(): void
    {
        static::deleting(function (Category $category): void {
            $ids = [$category->getKey()];
            $frontier = $ids;
            while ($frontier !== []) {
                $children = $category->newQuery()->whereIn('parent_id', $frontier)->pluck('id')->all();
                $frontier = array_values(array_diff($children, $ids));
                $ids = array_merge($ids, $frontier);
            }

            $category->imagesToCleanAfterDelete = $category->newQuery()->whereIn('id', $ids)->whereNotNull('image')->pluck('image')->all();
        });

        static::deleted(function (Category $category): void {
            if ($category->imagesToCleanAfterDelete === []) {
                return;
            }

            $paths = array_values(array_unique($category->imagesToCleanAfterDelete));
            $cleanup = function () use ($category, $paths): void {
                $service = app(CategoryImageService::class);
                foreach ($paths as $path) {
                    $service->deleteIfUnreferenced($path, $category);
                }
            };

            $connection = DB::connection($category->getConnectionName());
            if ($connection->transactionLevel() > 0) {
                $connection->afterCommit($cleanup);
            } else {
                $cleanup();
            }
        });
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }
}
