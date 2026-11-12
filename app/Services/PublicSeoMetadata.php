<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicSeoMetadata
{
    private const MAX_PATH_DECODE_STEPS = 4;

    public function clientConfig(): array
    {
        $name = (string) config('app.name', 'LubriStore');
        $twitterHandle = $this->twitterHandle();
        $baseUrl = $this->baseUrl();

        return [
            'name' => $name,
            'baseUrl' => $baseUrl,
            'publicUrlValid' => $baseUrl !== null,
            'description' => $this->plainText((string) config('seo.description')),
            'socialImage' => $this->socialImage($baseUrl),
            'language' => (string) config('seo.language', 'es'),
            'locale' => (string) config('seo.locale', 'es_PE'),
            'country' => (string) config('seo.country', 'PE'),
            'currency' => (string) config('payment.currency', 'PEN'),
            'twitterHandle' => $twitterHandle,
            'organization' => [
                'name' => (string) (config('seo.organization_name') ?: $name),
                'url' => $this->configuredOrganizationUrl($baseUrl),
                'logo' => $baseUrl ? $this->absolutePath('/android-chrome-512x512.png') : null,
                'sameAs' => $twitterHandle ? ['https://x.com/'.ltrim($twitterHandle, '@')] : [],
            ],
        ];
    }

    public function forRequest(Request $request): array
    {
        $config = $this->clientConfig();
        $meta = $this->baseMetadata($config);
        $path = '/'.trim((string) parse_url($request->getRequestUri(), PHP_URL_PATH), '/');

        if ($path === '/') {
            $meta = $this->page($config, $config['name'].' | Productos para tu vehículo', $config['description'], '/', 'website');

            return ['seoConfig' => $config, 'seo' => $meta];
        }

        if ($path === '/catalog') {
            $query = $request->query();
            $categorySlug = $query['category'] ?? null;
            $hasOnlyCategory = $query !== [] && array_keys($query) === ['category'];
            $category = is_string($categorySlug) && strlen($categorySlug) <= 191
                ? Category::query()->where('is_active', true)->where('slug', $categorySlug)->first(['id', 'name', 'slug'])
                : null;

            if ($hasOnlyCategory && $category) {
                $canonicalPath = '/catalog?'.http_build_query(['category' => $category->slug], '', '&', PHP_QUERY_RFC3986);
                $meta = $this->page(
                    $config,
                    $category->name.' | Catálogo | '.$config['name'],
                    'Explora productos de '.$category->name.' disponibles en '.$config['name'].'.',
                    $canonicalPath,
                    'website',
                    'index,follow',
                    [['name' => 'Inicio', 'path' => '/'], ['name' => 'Catálogo', 'path' => '/catalog'], ['name' => $category->name, 'path' => $canonicalPath]]
                );
            } else {
                $indexable = $query === [];
                $meta = $this->page(
                    $config,
                    'Catálogo de productos | '.$config['name'],
                    'Explora productos automotrices disponibles en '.$config['name'].'.',
                    $indexable ? '/catalog' : null,
                    'website',
                    $indexable ? 'index,follow' : 'noindex,follow',
                    [['name' => 'Inicio', 'path' => '/'], ['name' => 'Catálogo', 'path' => '/catalog']]
                );
            }

            return ['seoConfig' => $config, 'seo' => $meta];
        }

        if (preg_match('#\A/product/([^/]+)\z#u', $path, $matches) === 1) {
            $slug = rawurldecode($matches[1]);
            $product = strlen($slug) <= 191 && ! str_contains($slug, '/') && ! preg_match('/[\x00-\x1F\x7F]/', $slug)
                ? Product::query()
                    ->where('is_active', true)
                    ->where('slug', $slug)
                    ->select(['id', 'brand_id', 'name', 'slug', 'sku', 'description', 'image_path', 'updated_at'])
                    ->addSelect([
                        'seo_brand_name' => Brand::query()->select('name')->whereColumn('brands.id', 'products.brand_id')->limit(1),
                        'seo_image_path' => ProductImage::query()->select('image_path')->whereColumn('product_images.product_id', 'products.id')->where('is_primary', true)->where('processing_status', 'ready')->whereNotNull('image_path')->orderBy('sort_order')->limit(1),
                    ])
                    ->first()
                : null;

            if (! $product) {
                $meta['robots'] = 'noindex,nofollow';

                return ['seoConfig' => $config, 'seo' => $meta];
            }

            $canonicalPath = '/product/'.rawurlencode($product->slug);
            $description = $this->plainText((string) $product->description);
            if ($description === '') {
                $description = 'Consulta los detalles y la disponibilidad de '.$product->name.' en '.$config['name'].'.';
            }
            $image = $this->productImage($product->image_path ?: $product->seo_image_path);
            $breadcrumbs = [
                ['name' => 'Inicio', 'path' => '/'],
                ['name' => 'Catálogo', 'path' => '/catalog'],
                ['name' => $product->name, 'path' => $canonicalPath],
            ];
            $meta = $this->page($config, $product->name.' | '.$config['name'], $description, $canonicalPath, 'product', 'index,follow', $breadcrumbs, $image);
            if ($config['publicUrlValid']) {
                $productSchema = [
                    '@context' => 'https://schema.org',
                    '@type' => 'Product',
                    'name' => $this->plainText((string) $product->name),
                    'description' => $description,
                    'url' => $this->absolutePath($canonicalPath),
                ];
                if ($product->sku) {
                    $productSchema['sku'] = $this->plainText((string) $product->sku);
                }
                if ($product->seo_brand_name) {
                    $productSchema['brand'] = ['@type' => 'Brand', 'name' => $this->plainText((string) $product->seo_brand_name)];
                }
                if ($image) {
                    $productSchema['image'] = [$image];
                }
                $meta['jsonLd'][] = $productSchema;
            }

            return ['seoConfig' => $config, 'seo' => $meta];
        }

        $staticPages = [
            '/about' => ['Sobre LubriStore', 'Conoce LubriStore y nuestro trabajo para ofrecer productos automotrices.'],
            '/contact' => ['Contacto', 'Comunícate con el equipo de '.$config['name'].' mediante nuestro formulario de contacto.'],
        ];
        if (isset($staticPages[$path])) {
            [$title, $description] = $staticPages[$path];

            return ['seoConfig' => $config, 'seo' => $this->page($config, $title.' | '.$config['name'], $description, $path)];
        }

        $meta['robots'] = 'noindex,nofollow';

        return ['seoConfig' => $config, 'seo' => $meta];
    }

    public function absolutePath(string $path): string
    {
        $parts = parse_url($path);
        if (! is_array($parts)) {
            return '';
        }

        // Absolute URLs are validated and returned independently of APP_URL.
        // Their query and fragment are intentionally omitted from SEO URLs.
        if (isset($parts['scheme']) || isset($parts['host']) || str_starts_with($path, '//')) {
            if (preg_match('/[\x00-\x20\x7F]/', $path)
                || array_key_exists('user', $parts)
                || array_key_exists('pass', $parts)) {
                return '';
            }

            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = trim((string) ($parts['host'] ?? ''), '[]');
            $port = $parts['port'] ?? null;
            $rawPath = (string) ($parts['path'] ?? '/');
            if (! in_array($scheme, ['http', 'https'], true)
                || $host === ''
                || ($port !== null && ($port < 1 || $port > 65535))
                || $this->decodePathForValidation($rawPath) === null) {
                return '';
            }

            $authority = str_contains($host, ':') ? '['.$host.']' : $host;
            if ($port !== null) {
                $authority .= ':'.$port;
            }
            $validated = $this->normalizeHttpUrl($scheme.'://'.$authority.$rawPath, $this->isProduction());

            return $validated ?? '';
        }

        $baseUrl = $this->baseUrl();
        if ($baseUrl === null) {
            return '';
        }

        $rawPath = (string) ($parts['path'] ?? '/');
        if ($this->decodePathForValidation($rawPath) === null) {
            return '';
        }

        $baseParts = parse_url($baseUrl);
        $scheme = strtolower((string) ($baseParts['scheme'] ?? ''));
        $host = trim((string) ($baseParts['host'] ?? ''), '[]');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }

        $authority = str_contains($host, ':') ? '['.$host.']' : $host;
        if (isset($baseParts['port'])) {
            $authority .= ':'.$baseParts['port'];
        }
        $origin = $scheme.'://'.$authority;
        $basePath = rtrim((string) ($baseParts['path'] ?? ''), '/');
        $rootedPath = '/'.ltrim($rawPath, '/');
        $alreadyPrefixed = $basePath !== ''
            && ($rootedPath === $basePath || str_starts_with($rootedPath, $basePath.'/'));
        $resolvedPath = $alreadyPrefixed ? $rootedPath : $basePath.$rootedPath;
        $resolved = $origin.$resolvedPath;

        if (array_key_exists('query', $parts)) {
            $resolved .= '?'.$parts['query'];
        }

        return $resolved;
    }

    public function hasPublicBaseUrl(): bool
    {
        return $this->baseUrl() !== null;
    }

    public function sitemapEntries(): array
    {
        if (! $this->hasPublicBaseUrl()) {
            return [];
        }

        $entries = [
            ['loc' => $this->absolutePath('/'), 'lastmod' => null],
            ['loc' => $this->absolutePath('/catalog'), 'lastmod' => null],
            ['loc' => $this->absolutePath('/about'), 'lastmod' => null],
            ['loc' => $this->absolutePath('/contact'), 'lastmod' => null],
        ];

        Category::query()->where('is_active', true)->orderBy('id')->chunkById(200, function ($categories) use (&$entries) {
            foreach ($categories as $category) {
                $path = '/catalog?'.http_build_query(['category' => $category->slug], '', '&', PHP_QUERY_RFC3986);
                $entries[] = ['loc' => $this->absolutePath($path), 'lastmod' => $category->updated_at?->toAtomString()];
            }
        });

        Product::query()->where('is_active', true)->orderBy('id')->chunkById(200, function ($products) use (&$entries) {
            foreach ($products as $product) {
                $entries[] = [
                    'loc' => $this->absolutePath('/product/'.rawurlencode($product->slug)),
                    'lastmod' => $product->updated_at?->toAtomString(),
                ];
            }
        });

        return $entries;
    }

    public function robotsText(): string
    {
        if (! $this->hasPublicBaseUrl()) {
            return "User-agent: *\nDisallow: /\n";
        }

        return implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /api/',
            'Disallow: /cart',
            'Disallow: /checkout',
            'Disallow: /profile',
            'Disallow: /orders',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /treasury',
            'Disallow: /verificar-correo',
            'Disallow: /email/verification',
            'Disallow: /email/verify/',
            'Disallow: /mock-payment/',
            'Sitemap: '.$this->absolutePath('/sitemap.xml'),
            '',
        ]);
    }

    private function baseMetadata(array $config): array
    {
        return [
            'title' => $config['name'].' | Productos para tu vehículo',
            'description' => $config['description'],
            'robots' => 'noindex,nofollow',
            'canonical' => null,
            'type' => 'website',
            'image' => $config['socialImage'],
            'imageAlt' => $config['name'],
            'breadcrumbs' => [],
            'jsonLd' => $config['publicUrlValid'] ? [$this->organizationSchema($config), $this->webSiteSchema($config)] : [],
        ];
    }

    private function page(array $config, string $title, string $description, ?string $canonicalPath, string $type = 'website', string $robots = 'index,follow', array $breadcrumbs = [], ?string $image = null): array
    {
        $meta = $this->baseMetadata($config);
        $meta['title'] = $this->plainText($title);
        $meta['description'] = $this->plainText($description);
        $publicUrlValid = $config['publicUrlValid'];
        $meta['robots'] = $publicUrlValid ? $robots : 'noindex,nofollow';
        $meta['canonical'] = $publicUrlValid && $canonicalPath ? $this->absolutePath($canonicalPath) : null;
        $meta['type'] = $type;
        $meta['image'] = $publicUrlValid ? ($image ?: $config['socialImage']) : null;
        $meta['imageAlt'] = $this->plainText($title);
        $meta['breadcrumbs'] = $publicUrlValid ? $breadcrumbs : [];

        if ($publicUrlValid && $breadcrumbs !== []) {
            $meta['jsonLd'][] = $this->breadcrumbSchema($breadcrumbs);
        }

        return $meta;
    }

    private function organizationSchema(array $config): array
    {
        $organization = $config['organization'];
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $organization['name'],
            'url' => $organization['url'],
            'logo' => $organization['logo'],
        ];
        if ($organization['sameAs'] !== []) {
            $schema['sameAs'] = $organization['sameAs'];
        }

        return $schema;
    }

    private function webSiteSchema(array $config): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $config['name'],
            'url' => $config['baseUrl'],
            'inLanguage' => str_replace('_', '-', $config['locale']),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $this->absolutePath('/catalog?search={search_term_string}')],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    private function breadcrumbSchema(array $breadcrumbs): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $item, int $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $this->plainText((string) $item['name']),
                'item' => $this->absolutePath($item['path']),
            ], $breadcrumbs, array_keys($breadcrumbs)),
        ];
    }

    private function socialImage(?string $baseUrl): ?string
    {
        if ($baseUrl === null) {
            return null;
        }

        $configured = config('seo.social_image', '/images/lubristore-social.png');
        if (is_string($configured) && $this->isSafeLocalAssetPath($configured)) {
            return $this->absolutePath($configured);
        }

        if (is_string($configured)) {
            $absolute = $this->normalizeHttpUrl($configured, $this->isProduction());
            if ($absolute !== null) {
                return $absolute;
            }
        }

        return $this->absolutePath('/images/lubristore-social.png');
    }

    private function productImage(?string $path): ?string
    {
        $baseUrl = $this->baseUrl();
        if ($baseUrl === null || ! is_string($path) || trim($path) === '') {
            return null;
        }
        $path = trim($path);
        if ($this->decodePathForValidation($path) === null || str_starts_with($path, '//')) {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            $candidateUrl = $this->normalizeHttpUrl($path, $this->isProduction());
            $candidate = $candidateUrl ? parse_url($candidateUrl) : false;
            $site = parse_url($baseUrl);
            if (! is_array($candidate)
                || strtolower((string) ($candidate['host'] ?? '')) !== strtolower((string) ($site['host'] ?? ''))) {
                return null;
            }

            return $candidateUrl;
        }

        $path = preg_replace('#^/?storage/app/public/#', '', $path);
        $path = preg_replace('#^/?storage/#', '', $path);
        $path = ltrim($path, '/');
        if ($path === '') {
            return null;
        }

        return $this->absolutePath('/storage/'.implode('/', array_map('rawurlencode', explode('/', $path))));
    }

    private function plainText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace(
            '~<\s*(script|style|template|noscript)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>(.*?)(?:<\s*/\s*\1\s*>|\z)~isu',
            ' ',
            $value
        ) ?? '';
        $value = strip_tags($value);
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return Str::limit($value, 300, '');
    }

    private function baseUrl(): ?string
    {
        $configured = config('app.url');
        if (is_string($configured)) {
            $normalized = $this->normalizeHttpUrl($configured, $this->isProduction());
            if ($normalized !== null) {
                return rtrim($normalized, '/');
            }
        }

        return $this->isProduction() ? null : 'http://localhost';
    }

    private function configuredOrganizationUrl(?string $baseUrl): ?string
    {
        if ($baseUrl === null) {
            return null;
        }

        $configured = (string) config('seo.organization_url', '');
        if ($configured === '') {
            return $baseUrl;
        }

        return $this->normalizeHttpUrl($configured, $this->isProduction()) ?: $baseUrl;
    }

    private function normalizeHttpUrl(string $url, bool $requirePublicHost): ?string
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! isset($parts['host'])
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)) {
            return null;
        }

        $host = trim((string) $parts['host'], '[]');
        $normalizedHost = strtolower(rtrim($host, '.'));
        if (! $this->isValidHost($normalizedHost, $requirePublicHost)) {
            return null;
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($this->decodePathForValidation($path) === null) {
            return null;
        }

        $authority = str_contains($normalizedHost, ':') ? '['.$normalizedHost.']' : $normalizedHost;
        if ($port !== null) {
            $authority .= ':'.$port;
        }

        return strtolower($parts['scheme']).'://'.$authority.$path;
    }

    private function isValidHost(string $host, bool $requirePublicHost): bool
    {
        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ! $requirePublicHost || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return false;
        }

        if (! $requirePublicHost) {
            return true;
        }

        if (! str_contains($host, '.')) {
            return false;
        }

        foreach (['localhost', 'local', 'localdomain', 'test', 'invalid', 'example', 'internal', 'lan', 'corp'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return false;
            }
        }

        return ! str_ends_with($host, '.home.arpa');
    }

    private function isSafeLocalAssetPath(string $path): bool
    {
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')
            || preg_match('/[\x00-\x20\x7F?#]/', $path)
            || str_contains($path, '\\')) {
            return false;
        }

        return $this->decodePathForValidation($path) !== null;
    }

    private function decodePathForValidation(string $path): ?string
    {
        $current = $path;

        for ($step = 0; $step <= self::MAX_PATH_DECODE_STEPS; $step++) {
            if (preg_match('/%(?![a-f0-9]{2})/i', $current)
                || preg_match('/[\x00-\x1F\x7F\\\\]/', $current)
                || str_starts_with($current, '//')
                || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $current)) {
                return null;
            }

            if (! preg_match('/%[a-f0-9]{2}/i', $current)) {
                return $current;
            }

            if ($step === self::MAX_PATH_DECODE_STEPS) {
                return null;
            }

            $decoded = rawurldecode($current);
            if (! mb_check_encoding($decoded, 'UTF-8')) {
                return null;
            }
            $current = $decoded;
        }

        return null;
    }

    private function isProduction(): bool
    {
        return strtolower((string) config('app.env', 'local')) === 'production';
    }

    private function twitterHandle(): ?string
    {
        $handle = trim((string) config('seo.twitter_handle', ''));

        return preg_match('/\A@?[A-Za-z0-9_]{1,15}\z/', $handle) === 1 ? $handle : null;
    }
}
