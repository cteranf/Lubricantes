<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\News;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NewsController extends Controller
{
    public function index()
    {
        return News::orderBy('created_at', 'desc')->paginate(10);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'summary' => 'required|string',
            'content' => 'nullable|string',
            'image' => 'required|image|max:2048', // Image required for news
        ]);

        $path = null;
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('news', 'public');
        }

        $news = News::create([
            'title' => $request->title,
            'slug' => Str::slug($request->title) . '-' . Str::random(5),
            'summary' => $request->summary,
            'content' => $request->content,
            'image_path' => $path ? '/storage/' . $path : null,
            'is_active' => $request->boolean('is_active', true),
            'published_at' => now(),
        ]);

        return response()->json($news, 201);
    }

    public function update(Request $request, News $news)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'summary' => 'required|string',
            'content' => 'nullable|string',
            'image' => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('news', 'public');
            $news->image_path = '/storage/' . $path;
        }

        $news->title = $request->title;
        // Optionally update slug $news->slug = Str::slug($request->title);
        $news->summary = $request->summary;
        $news->content = $request->content;
        $news->is_active = $request->boolean('is_active', $news->is_active);

        $news->save();

        return response()->json($news);
    }

    public function destroy(News $news)
    {
        $news->delete();
        return response()->json(['message' => 'News deleted']);
    }
}
