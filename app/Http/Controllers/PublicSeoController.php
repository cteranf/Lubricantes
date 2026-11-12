<?php

namespace App\Http\Controllers;

use App\Services\PublicSeoMetadata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Response;

class PublicSeoController extends Controller
{
    public function shell(Request $request, PublicSeoMetadata $seo)
    {
        return view('welcome', $seo->forRequest($request));
    }

    public function robots(PublicSeoMetadata $seo)
    {
        return Response::make($seo->robotsText(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function sitemap(PublicSeoMetadata $seo)
    {
        if (! $seo->hasPublicBaseUrl()) {
            return response('', 503, [
                'Cache-Control' => 'no-store',
                'X-Robots-Tag' => 'noindex, nofollow',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $cacheKey = 'seo:sitemap:'.sha1(config('app.url', 'http://localhost'));
        $entries = Cache::remember($cacheKey, now()->addHour(), fn () => $seo->sitemapEntries());

        return response()->view('seo.sitemap', ['entries' => $entries], 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
