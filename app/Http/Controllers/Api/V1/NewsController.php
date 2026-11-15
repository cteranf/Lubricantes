<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NewsDetailResource;
use App\Http\Resources\NewsPublicResource;
use App\Models\News;
use Illuminate\Http\Request;

class NewsController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $query = News::query()->published();
        $search = trim((string) ($validated['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('title', 'like', '%'.$search.'%')
                    ->orWhere('content', 'like', '%'.$search.'%');
            });
        }

        $news = $query->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 10));

        return NewsPublicResource::collection($news);
    }

    public function show(string $slug): NewsDetailResource
    {
        $news = News::query()->published()->where('slug', $slug)->firstOrFail();

        return new NewsDetailResource($news);
    }
}
