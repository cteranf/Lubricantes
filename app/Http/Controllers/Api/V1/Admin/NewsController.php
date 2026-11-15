<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreNewsRequest;
use App\Http\Requests\Admin\UpdateNewsRequest;
use App\Models\News;
use App\Services\NewsImageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class NewsController extends Controller
{
    public function index()
    {
        return News::query()->orderByDesc('created_at')->orderByDesc('id')->paginate(10);
    }

    public function store(StoreNewsRequest $request, NewsImageService $images)
    {
        $data = $request->validated();
        $image = $data['image'];
        unset($data['image']);
        $isActive = (bool) ($data['is_active'] ?? false);
        if (! array_key_exists('published_at', $data) && $isActive) {
            $data['published_at'] = now();
        }
        $data['is_active'] = $isActive;
        $data['slug'] = $this->uniqueSlug($data['title']);
        $path = $images->store($image);
        $data['image_path'] = $path;

        try {
            $news = DB::connection((new News)->getConnectionName())->transaction(fn () => News::query()->create($data));
        } catch (Throwable $exception) {
            $images->deleteIfUnreferenced($path);
            throw $exception;
        }

        return response()->json($news, 201);
    }

    public function update(UpdateNewsRequest $request, News $news, NewsImageService $images)
    {
        $data = $request->validated();
        $image = $data['image'] ?? null;
        $removeImage = (bool) ($data['remove_image'] ?? false);
        unset($data['image'], $data['remove_image']);

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
            if ($data['is_active'] && ! array_key_exists('published_at', $data) && $news->published_at === null) {
                $data['published_at'] = now();
            }
        }

        $news = $images->update($news, $data, $image, $removeImage);

        return response()->json($news->fresh());
    }

    public function destroy(News $news, NewsImageService $images)
    {
        $images->delete($news);

        return response()->json(['message' => 'Noticia eliminada.']);
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'noticia';
        $candidate = $base;
        $suffix = 2;
        while (News::query()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }
}
