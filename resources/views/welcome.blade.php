<!DOCTYPE html>
@php
    if (! isset($seo) || ! isset($seoConfig)) {
        $seoPayload = app(\App\Services\PublicSeoMetadata::class)->forRequest(request());
        $seoConfig = $seoConfig ?? $seoPayload['seoConfig'];
        $seo = $seo ?? $seoPayload['seo'];
    }
@endphp
<html lang="{{ $seoConfig['language'] }}-{{ strtoupper($seoConfig['country']) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    <meta name="robots" content="{{ $seo['robots'] }}">
    @if ($seo['canonical'])
        <link id="seo-canonical" rel="canonical" href="{{ $seo['canonical'] }}">
    @endif
    <meta property="og:site_name" content="{{ $seoConfig['name'] }}">
    <meta property="og:type" content="{{ $seo['type'] }}">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    @if ($seoConfig['publicUrlValid'])
        <meta property="og:url" content="{{ $seo['canonical'] ?: $seoConfig['baseUrl'] }}">
    @endif
    @if ($seo['image'])
        <meta property="og:image" content="{{ $seo['image'] }}">
        <meta property="og:image:alt" content="{{ $seo['imageAlt'] }}">
    @endif
    <meta property="og:locale" content="{{ $seoConfig['locale'] }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seo['title'] }}">
    <meta name="twitter:description" content="{{ $seo['description'] }}">
    @if ($seo['image'])
        <meta name="twitter:image" content="{{ $seo['image'] }}">
    @endif
    @if ($seoConfig['twitterHandle'])
        <meta name="twitter:site" content="{{ $seoConfig['twitterHandle'] }}">
    @endif
    <meta name="theme-color" content="#1d4ed8">
    <link rel="icon" href="{{ $seoConfig['baseUrl'] }}/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="{{ $seoConfig['baseUrl'] }}/favicon.ico" sizes="any">
    <link rel="icon" href="{{ $seoConfig['baseUrl'] }}/favicon-32x32.png" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="{{ $seoConfig['baseUrl'] }}/apple-touch-icon.png" sizes="180x180">
    <link rel="manifest" href="{{ app(\App\Services\PublicSeoMetadata::class)->absolutePath('/site.webmanifest') }}">
    <script id="site-seo-config" type="application/json">@json($seoConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES)</script>
    <script id="site-seo-jsonld" type="application/ld+json">@json($seo['jsonLd'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES)</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased">
    <div id="app"></div>
</body>
</html>
