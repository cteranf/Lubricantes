<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicSeoFrontendContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_views_import_only_seo_helpers_exported_by_the_composable(): void
    {
        $modulePath = resource_path('js/composables/useSiteSeo.js');
        $module = file_get_contents($modulePath);

        $this->assertNotFalse($module, 'SEO composable source must be readable.');
        preg_match_all('/\bexport\s+(?:async\s+)?(?:function|const|let|var|class)\s+([A-Za-z_$][A-Za-z0-9_$]*)/', $module, $exportMatches);
        $exports = $exportMatches[1];

        $views = [
            'js/App.vue',
            'js/views/Home.vue',
            'js/views/Catalog.vue',
            'js/views/ProductDetail.vue',
            'js/views/About.vue',
            'js/views/Contact.vue',
        ];

        foreach ($views as $relativePath) {
            $source = file_get_contents(resource_path($relativePath));
            $this->assertNotFalse($source, 'SEO view source must be readable.');
            preg_match_all('/\bimport\s*\{([^}]+)\}\s*from\s*[\'\"]@\/composables\/useSiteSeo[\'\"]\s*;?/s', $source, $importMatches);

            $this->assertNotEmpty($importMatches[1], 'Expected an explicit SEO composable import in '.$relativePath.'.');
            foreach ($importMatches[1] as $importList) {
                foreach (explode(',', $importList) as $specifier) {
                    $parts = preg_split('/\s+as\s+/', trim($specifier));
                    $importedName = trim($parts[0] ?? '');
                    $this->assertContains($importedName, $exports, 'SEO import '.$importedName.' must be exported for '.$relativePath.'.');
                }
            }
        }
    }

    public function test_favicon_png_ico_svg_and_manifest_assets_are_real_and_consistent(): void
    {
        $svgPath = public_path('favicon.svg');
        $svg = file_get_contents($svgPath);
        $this->assertNotFalse($svg);

        $document = new \DOMDocument;
        $this->assertTrue($document->loadXML($svg, LIBXML_NONET));
        $xpath = new \DOMXPath($document);
        $this->assertSame(0, $xpath->query('//*[local-name()="script"]')->length);
        $this->assertSame(0, $xpath->query('//*[@href or @src]')->length);

        foreach ([
            'favicon-32x32.png' => [32, 32],
            'apple-touch-icon.png' => [180, 180],
            'android-chrome-192x192.png' => [192, 192],
            'android-chrome-512x512.png' => [512, 512],
            'images/lubristore-social.png' => [1200, 630],
        ] as $filename => [$width, $height]) {
            $path = public_path($filename);
            $this->assertFileExists($path, 'Referenced raster asset must exist: '.$filename);
            $image = getimagesize($path);
            $this->assertIsArray($image, 'Asset must be a decodable image: '.$filename);
            $this->assertSame(IMAGETYPE_PNG, $image[2], 'Asset must be PNG: '.$filename);
            $this->assertSame([$width, $height], [$image[0], $image[1]], 'Unexpected dimensions for '.$filename);
        }

        $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('LubriStore', $manifest['name']);
        $this->assertSame('#1d4ed8', $manifest['theme_color']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
            $image = getimagesize(public_path(ltrim($icon['src'], '/')));
            $this->assertSame($icon['sizes'], $image[0].'x'.$image[1]);
            $this->assertSame('image/png', $icon['type']);
        }

        $ico = file_get_contents(public_path('favicon.ico'));
        $this->assertNotFalse($ico);
        $header = unpack('vreserved/vtype/vcount', substr($ico, 0, 6));
        $this->assertSame(['reserved' => 0, 'type' => 1, 'count' => 1], $header);
        $entry = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbits/Vbytes/Voffset', substr($ico, 6, 16));
        $this->assertSame(32, $entry['width']);
        $this->assertSame(32, $entry['height']);
        $this->assertSame(32, $entry['bits']);
        $this->assertSame(22, $entry['offset']);
        $embeddedPng = substr($ico, $entry['offset'], $entry['bytes']);
        $embeddedImage = getimagesizefromstring($embeddedPng);
        $this->assertIsArray($embeddedImage);
        $this->assertSame([32, 32, IMAGETYPE_PNG], [$embeddedImage[0], $embeddedImage[1], $embeddedImage[2]]);
    }

    public function test_public_shell_references_existing_assets_and_emits_parseable_jsonld(): void
    {
        $response = $this->get('/');
        $response->assertOk();
        $html = $response->getContent();
        $document = new \DOMDocument;
        $this->assertTrue($document->loadHTML($html, LIBXML_NONET));
        $xpath = new \DOMXPath($document);

        $this->assertSame('#1d4ed8', $xpath->query('//meta[@name="theme-color"]')->item(0)?->getAttribute('content'));
        $this->assertSame('index,follow', $xpath->query('//meta[@name="robots"]')->item(0)?->getAttribute('content'));
        $this->assertSame('http://localhost/', $xpath->query('//link[@rel="canonical"]')->item(0)?->getAttribute('href'));

        $links = $xpath->query('//link[@rel="icon" or @rel="apple-touch-icon" or @rel="manifest"]');
        $this->assertGreaterThanOrEqual(4, $links->length);
        foreach ($links as $link) {
            $url = parse_url($link->getAttribute('href'));
            $this->assertSame('localhost', $url['host'] ?? null);
            $this->assertFileExists(public_path(ltrim($url['path'] ?? '', '/')));
        }

        $jsonLdText = $xpath->query('//script[@id="site-seo-jsonld"]')->item(0)?->textContent;
        $this->assertIsString($jsonLdText);
        $schemas = json_decode($jsonLdText, true, 512, JSON_THROW_ON_ERROR);
        $types = array_column($schemas, '@type');
        $this->assertContains('Organization', $types);
        $this->assertContains('WebSite', $types);
    }

    public function test_manifest_shell_reference_stays_under_app_url_base_path(): void
    {
        config(['app.env' => 'testing', 'app.url' => 'https://example.test/tienda']);

        $response = $this->get('/');
        $response->assertOk();
        $document = new \DOMDocument;
        $this->assertTrue($document->loadHTML($response->getContent(), LIBXML_NONET));
        $xpath = new \DOMXPath($document);

        $manifestHref = $xpath->query('//link[@rel="manifest"]')->item(0)?->getAttribute('href');
        $this->assertSame('https://example.test/tienda/site.webmanifest', $manifestHref);
        $this->assertStringNotContainsString('/tienda/tienda/', $manifestHref);
        $this->assertSame('https://example.test/tienda/favicon.svg', $xpath->query('//link[@rel="icon" and @type="image/svg+xml"]')->item(0)?->getAttribute('href'));
        $this->assertSame('https://example.test/tienda/apple-touch-icon.png', $xpath->query('//link[@rel="apple-touch-icon"]')->item(0)?->getAttribute('href'));
        $this->assertSame('https://example.test/tienda/images/lubristore-social.png', $xpath->query('//meta[@property="og:image"]')->item(0)?->getAttribute('content'));
    }

    public function test_robots_response_is_plain_text_and_points_to_the_real_sitemap(): void
    {
        $response = $this->get('/robots.txt');
        $response->assertOk();
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('User-agent: *', $response->getContent());
        $this->assertStringContainsString('Disallow: /email/verify/', $response->getContent());
        $this->assertStringContainsString('Sitemap: http://localhost/sitemap.xml', $response->getContent());
        $this->assertFileExists(public_path('site.webmanifest'));
    }

    public function test_sitemap_response_is_xml_escaped_and_contains_only_active_catalog_entries(): void
    {
        config(['app.url' => 'https://lubristore.example.test/a&b']);
        Cache::forget('seo:sitemap:'.sha1(config('app.url')));

        $activeCategory = Category::query()->create(['name' => 'Motor & filtros <premium>', 'slug' => 'motor-filtros', 'is_active' => true]);
        Category::query()->create(['name' => 'Privada', 'slug' => 'privada', 'is_active' => false]);
        $activeProduct = Product::query()->create([
            'category_id' => $activeCategory->id,
            'name' => 'Filtro activo',
            'slug' => 'filtro-activo',
            'sku' => 'SEO-ACTIVE',
            'price' => '12.50',
            'is_active' => true,
        ]);
        Product::query()->create([
            'name' => 'Producto privado',
            'slug' => 'producto-privado',
            'sku' => 'SEO-INACTIVE',
            'price' => '10.00',
            'is_active' => false,
        ]);

        $response = $this->get('/sitemap.xml');
        $response->assertOk();
        $this->assertStringStartsWith('application/xml', $response->headers->get('Content-Type'));

        $xml = new \DOMDocument;
        $this->assertTrue($xml->loadXML($response->getContent(), LIBXML_NONET));
        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $locations = array_map(static fn ($node) => $node->textContent, iterator_to_array($xpath->query('//sm:url/sm:loc')));

        $this->assertContains('https://lubristore.example.test/a&b/', $locations);
        $this->assertContains('https://lubristore.example.test/a&b/catalog?category=motor-filtros', $locations);
        $this->assertContains('https://lubristore.example.test/a&b/product/'.$activeProduct->slug, $locations);
        $this->assertNotContains('https://lubristore.example.test/a&b/catalog?category=privada', $locations);
        $this->assertNotContains('https://lubristore.example.test/a&b/product/product-privado', $locations);
        $this->assertStringContainsString('&amp;', $response->getContent());
    }

    public function test_product_jsonld_is_escaped_and_private_routes_are_noindex_without_query_leaks(): void
    {
        $category = Category::query()->create(['name' => 'Cuidado', 'slug' => 'cuidado', 'is_active' => true]);
        Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Filtro <script>alert(1)</script> & <Sport>',
            'slug' => 'filtro-sport',
            'sku' => 'SEO-FILTER',
            'description' => "Aceite & <ScRiPt data-kind=\"x\">\nalert(1)\n</sCrIpT> <STYLE media=\"all\">\ndisplay:none\n</STYLE> <template data-x=\"1\">\nsecret template\n</template> <noscript data-x>\nsecret fallback\n</noscript> seguro",
            'price' => '29.90',
            'is_active' => true,
        ]);

        $productResponse = $this->get('/product/filtro-sport?email=private%40example.test&signature=synthetic-signature&token=synthetic-token');
        $productResponse->assertOk();
        $html = $productResponse->getContent();
        $document = new \DOMDocument;
        $this->assertTrue($document->loadHTML($html, LIBXML_NONET));
        $xpath = new \DOMXPath($document);
        $this->assertSame('index,follow', $xpath->query('//meta[@name="robots"]')->item(0)?->getAttribute('content'));
        $this->assertSame('http://localhost/product/filtro-sport', $xpath->query('//link[@rel="canonical"]')->item(0)?->getAttribute('href'));
        $this->assertSame('Filtro & | LubriStore', $xpath->query('//title')->item(0)?->textContent);
        $this->assertSame('Aceite & seguro', $xpath->query('//meta[@name="description"]')->item(0)?->getAttribute('content'));
        $schemaText = $xpath->query('//script[@id="site-seo-jsonld"]')->item(0)?->textContent;
        $schemas = json_decode($schemaText, true, 512, JSON_THROW_ON_ERROR);
        $productSchema = collect($schemas)->firstWhere('@type', 'Product');
        $this->assertSame('Filtro &', $productSchema['name']);
        $this->assertSame('Aceite & seguro', $productSchema['description']);
        $this->assertStringNotContainsString('</script>', $schemaText);
        foreach (['alert(1)', 'display:none', 'secret template', 'secret fallback', '<ScRiPt', '</sCrIpT>'] as $dangerousContent) {
            $this->assertStringNotContainsString($dangerousContent, $html);
            $this->assertStringNotContainsString($dangerousContent, $schemaText);
        }
        $this->assertStringNotContainsString('private@example.test', $html);
        $this->assertStringNotContainsString('synthetic-signature', $html);
        $this->assertStringNotContainsString('synthetic-token', $html);

        foreach (['/login', '/register', '/cart', '/checkout', '/profile', '/orders', '/admin/dashboard', '/email/verification?result=invalid', '/verificar-correo?signature=synthetic-signature&token=synthetic-token'] as $path) {
            $private = $this->get($path);
            $private->assertOk();
            $privateHtml = $private->getContent();
            $privateDocument = new \DOMDocument;
            $this->assertTrue($privateDocument->loadHTML($privateHtml, LIBXML_NONET));
            $privateXpath = new \DOMXPath($privateDocument);
            $this->assertSame('noindex,nofollow', $privateXpath->query('//meta[@name="robots"]')->item(0)?->getAttribute('content'), 'Private SPA route must not be indexed: '.$path);
            $this->assertSame(0, $privateXpath->query('//link[@rel="canonical"]')->length, 'Private SPA route must not declare a canonical: '.$path);
            $this->assertStringNotContainsString('synthetic-signature', $privateHtml);
            $this->assertStringNotContainsString('synthetic-token', $privateHtml);
        }
    }

    public function test_product_detail_seo_stays_private_until_a_valid_active_product_is_loaded(): void
    {
        $view = file_get_contents(resource_path('js/views/ProductDetail.vue'));
        $composable = file_get_contents(resource_path('js/composables/useSiteSeo.js'));
        $this->assertIsString($view);
        $this->assertIsString($composable);

        preg_match('/useSiteSeo\(\(\) => \{(.*?)\n\}\);/s', $view, $seoMatch);
        $this->assertNotEmpty($seoMatch[1] ?? null, 'Product SEO callback must be extractable for its contract checks.');
        $seoCallback = $seoMatch[1];
        $this->assertStringContainsString('loading.value || notFound.value || error.value || !isPublicProduct(product.value)', $seoCallback);
        $this->assertStringContainsString("robots: 'noindex,nofollow'", $seoCallback);
        $this->assertStringContainsString('canonicalPath: null', $seoCallback);
        $this->assertStringContainsString('image: null', $seoCallback);
        $this->assertStringNotContainsString('product:', substr($seoCallback, 0, strpos($seoCallback, 'const canonicalPath')));

        $this->assertStringContainsString('(value.is_active === true || value.is_active === 1 || value.is_active === \'1\')', $view);
        $payloadGuard = strpos($view, 'if (!isPublicProduct(response.data) || response.data.slug !== slug)');
        $productAssignment = strpos($view, 'product.value = response.data;');
        $this->assertNotFalse($payloadGuard);
        $this->assertNotFalse($productAssignment);
        $this->assertLessThan($productAssignment, $payloadGuard, 'Invalid or inactive API payloads must be rejected before becoming SEO state.');
        $this->assertStringContainsString('if (exception.response?.status === 404) notFound.value = true;', $view);
        $this->assertStringContainsString("else error.value = 'Ocurrió un problema al consultar el producto. Inténtalo nuevamente.';", $view);
        $this->assertStringContainsString('product.value = null; error.value = \'\'; notFound.value = false; loading.value = true;', $view);

        preg_match('/function fallbackForRoute\(route, config\) \{(.*?)\n\}/s', $composable, $fallbackMatch);
        $this->assertNotEmpty($fallbackMatch[1] ?? null);
        $this->assertStringNotContainsString('/product/', $fallbackMatch[1]);
        $this->assertStringContainsString("config.publicUrlValid && allowed && !protectedRoute ? 'index,follow' : 'noindex,nofollow'", $fallbackMatch[1]);

        $invalid = Product::query()->create([
            'name' => 'No disponible', 'slug' => 'producto-inactivo', 'sku' => 'SEO-INACTIVE-DETAIL',
            'price' => '10.00', 'is_active' => false,
        ]);
        $this->assertFalse($invalid->is_active);
        $missingApi = $this->getJson('/api/v1/products/slug-inexistente');
        $missingApi->assertNotFound();
        $inactiveApi = $this->getJson('/api/v1/products/'.$invalid->slug);
        $inactiveApi->assertNotFound();

        foreach (['/product/slug-inexistente', '/product/producto-inactivo'] as $path) {
            $response = $this->get($path);
            $response->assertOk();
            $document = new \DOMDocument;
            $this->assertTrue($document->loadHTML($response->getContent(), LIBXML_NONET));
            $xpath = new \DOMXPath($document);
            $this->assertSame('noindex,nofollow', $xpath->query('//meta[@name="robots"]')->item(0)?->getAttribute('content'));
            $this->assertSame(0, $xpath->query('//link[@rel="canonical"]')->length);
            $schemaText = $xpath->query('//script[@id="site-seo-jsonld"]')->item(0)?->textContent;
            $schemas = json_decode($schemaText, true, 512, JSON_THROW_ON_ERROR);
            $this->assertNotContains('Product', array_column($schemas, '@type'));
            $this->assertStringNotContainsString('Offer', $schemaText);
        }
    }

    /** @dataProvider invalidProductionAppUrlCases */
    public function test_production_without_a_public_app_url_fails_closed_for_seo(?string $appUrl): void
    {
        config(['app.env' => 'production', 'app.url' => $appUrl, 'app.asset_url' => null]);
        $seo = app(\App\Services\PublicSeoMetadata::class);

        $this->assertFalse($seo->hasPublicBaseUrl());
        $payload = $seo->forRequest(Request::create('/'));
        $this->assertSame('noindex,nofollow', $payload['seo']['robots']);
        $this->assertNull($payload['seo']['canonical']);
        $this->assertNull($payload['seo']['image']);
        $this->assertSame([], $payload['seo']['jsonLd']);
        $this->assertNull($payload['seoConfig']['baseUrl']);
        $this->assertFalse($payload['seoConfig']['publicUrlValid']);

        $robots = $this->get('/robots.txt');
        $robots->assertOk();
        $this->assertSame("User-agent: *\nDisallow: /\n", $robots->getContent());
        $this->assertStringStartsWith('text/plain', $robots->headers->get('Content-Type'));
        $this->assertStringNotContainsString('Sitemap:', $robots->getContent());

        $sitemap = $this->get('/sitemap.xml');
        $sitemap->assertStatus(503);
        $this->assertSame('', $sitemap->getContent());
        $this->assertSame('noindex, nofollow', $sitemap->headers->get('X-Robots-Tag'));
        $this->assertStringNotContainsString('localhost', $sitemap->getContent());

        $this->withServerVariables(['HTTP_HOST' => 'seo-fixture.example.test', 'HTTPS' => 'on']);
        $shell = $this->get('/');
        $shell->assertOk();
        $document = new \DOMDocument;
        $this->assertTrue($document->loadHTML($shell->getContent(), LIBXML_NONET));
        $xpath = new \DOMXPath($document);
        $this->assertSame('noindex,nofollow', $xpath->query('//meta[@name="robots"]')->item(0)?->getAttribute('content'));
        $this->assertSame(0, $xpath->query('//link[@rel="canonical"]')->length);
        $this->assertSame(0, $xpath->query('//meta[@property="og:url"]')->length);
        $this->assertSame(0, $xpath->query('//meta[@property="og:image"]')->length);
        $this->assertSame(0, $xpath->query('//meta[@name="twitter:image"]')->length);
        $jsonLd = $xpath->query('//script[@id="site-seo-jsonld"]')->item(0)?->textContent;
        $this->assertSame([], json_decode($jsonLd, true, 512, JSON_THROW_ON_ERROR));
        $seoConfigJson = $xpath->query('//script[@id="site-seo-config"]')->item(0)?->textContent;
        $seoSurfaceValues = implode("\n", array_filter([
            $xpath->query('//meta[@name="robots"]')->item(0)?->getAttribute('content'),
            $xpath->query('//link[@rel="canonical"]')->item(0)?->getAttribute('href'),
            $xpath->query('//meta[@property="og:url"]')->item(0)?->getAttribute('content'),
            $xpath->query('//meta[@property="og:image"]')->item(0)?->getAttribute('content'),
            $xpath->query('//meta[@name="twitter:image"]')->item(0)?->getAttribute('content'),
            $jsonLd,
            $seoConfigJson,
        ], static fn ($value) => is_string($value)));
        $this->assertStringNotContainsString('localhost', $seoSurfaceValues);

        $viteAssetNodes = $xpath->query(
            '//script[@type="module" and (contains(@src, "/build/assets/") or contains(@src, "/resources/js/app.js") or contains(@src, "/@vite/client"))]'
            .' | //link[@rel="stylesheet" and (contains(@href, "/build/assets/") or contains(@href, "/resources/css/app.css"))]'
        );
        $this->assertGreaterThan(0, $viteAssetNodes->length, 'Ordinary Vite asset references remain separate from SEO metadata.');
        $this->assertStringNotContainsString('/build/assets/', $seoSurfaceValues);
    }

    public static function invalidProductionAppUrlCases(): array
    {
        return [
            'APP_URL absent' => [null],
            'APP_URL malformed' => ['not a URL'],
            'localhost' => ['http://localhost'],
            'localhost subdomain' => ['https://shop.localhost'],
            'IPv4 loopback' => ['http://127.0.0.1'],
            'IPv4 loopback range' => ['http://127.200.1.9'],
            'IPv6 loopback' => ['http://[::1]'],
            'userinfo' => ['https://user:password@store.example.org'],
            'query' => ['https://store.example.org?token=synthetic-secret'],
            'fragment' => ['https://store.example.org#private-fragment'],
            'local suffix' => ['https://store.internal'],
            'control character' => ["https://store.example.org/\n"],
        ];
    }

    /** @dataProvider invalidConfiguredPublicUrlCases */
    public function test_invalid_organization_and_social_urls_are_not_published(string $url): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'https://store.example.org',
            'seo.organization_url' => $url,
            'seo.social_image' => $url,
        ]);

        $seo = app(\App\Services\PublicSeoMetadata::class);
        $config = $seo->clientConfig();
        $this->assertSame('https://store.example.org', $config['organization']['url']);
        $this->assertSame('https://store.example.org/images/lubristore-social.png', $config['socialImage']);
        $serializedConfig = json_encode($config, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($url, $serializedConfig);
        $this->assertStringNotContainsString('synthetic-secret', $serializedConfig);

        $response = $this->get('/');
        $response->assertOk();
        $this->assertStringNotContainsString($url, $response->getContent());
        $this->assertStringNotContainsString('synthetic-secret', $response->getContent());
    }

    public static function invalidConfiguredPublicUrlCases(): array
    {
        return [
            'userinfo' => ['https://usuario:clave@dominio.example.org/'],
            'query token' => ['https://dominio.example.org/?token=synthetic-secret'],
            'fragment' => ['https://dominio.example.org/#fragment'],
            'javascript scheme' => ['javascript:alert(1)'],
            'protocol relative' => ['//dominio.example.org/image.png'],
            'backslash' => ['https://dominio.example.org\\image.png'],
            'path traversal' => ['/images/../private.png'],
            'encoded path traversal' => ['https://dominio.example.org/images/%2e%2e/private.png'],
            'double encoded traversal' => ['https://dominio.example.org/images/%252e%252e/private.png'],
            'triple encoded traversal' => ['https://dominio.example.org/images/%25252e%25252e/private.png'],
            'encoded slash traversal' => ['https://dominio.example.org/images/%2e%2e%2fprivate.png'],
            'double encoded slash traversal' => ['https://dominio.example.org/images/%252e%252e%252fprivate.png'],
            'encoded backslash' => ['/images/%5cprivate.png'],
            'double encoded backslash' => ['/images/%255cprivate.png'],
            'encoded nul' => ['/images/%00private.png'],
            'double encoded nul' => ['/images/%2500private.png'],
            'encoded CRLF' => ['/images/%0d%0aprivate.png'],
            'double encoded CRLF' => ['/images/%250d%250aprivate.png'],
            'malformed percent escape' => ['/images/%2-private.png'],
            'unsafe encoded absolute pathname' => ['https://dominio.example.org/images/%252e%252e%252fprivate.png'],
            'control character' => ["https://dominio.example.org/image.png\n"],
        ];
    }

    public function test_valid_production_https_url_and_safe_image_configuration_are_published(): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'https://store.example.org/shop',
            'seo.organization_url' => 'https://brand.example.org/about',
            'seo.social_image' => 'https://cdn.example.org/lubristore.png',
        ]);

        $seo = app(\App\Services\PublicSeoMetadata::class);
        $this->assertTrue($seo->hasPublicBaseUrl());
        $config = $seo->clientConfig();
        $this->assertSame('https://brand.example.org/about', $config['organization']['url']);
        $this->assertSame('https://cdn.example.org/lubristore.png', $config['socialImage']);
        $metadata = $seo->forRequest(Request::create('/'))['seo'];
        $this->assertSame('https://store.example.org/shop/', $metadata['canonical']);
        $this->assertSame('index,follow', $metadata['robots']);

        config(['seo.social_image' => '/images/local-social.png']);
        $this->assertSame('https://store.example.org/shop/images/local-social.png', $seo->clientConfig()['socialImage']);
    }

    public function test_absolute_path_preserves_valid_absolute_origin_and_strips_query_and_fragment(): void
    {
        config(['app.env' => 'testing', 'app.url' => 'https://sitio.test/tienda']);
        $seo = app(\App\Services\PublicSeoMetadata::class);

        $this->assertSame('https://cdn.example.test/ruta/imagen.png', $seo->absolutePath('https://cdn.example.test/ruta/imagen.png'));
        $this->assertSame(
            'https://otro.test/producto/x',
            $seo->absolutePath('https://otro.test/producto/x?token=synthetic#fragment')
        );
        $this->assertSame('https://sitio.test/tienda/producto/x', $seo->absolutePath('/producto/x'));

        foreach ([
            'absolute URL with userinfo' => 'https://user:secret@cdn.example.test/image.png',
            'absolute URL with encoded traversal' => 'https://cdn.example.test/%252e%252e/private.png',
            'absolute URL with malformed escape' => 'https://cdn.example.test/image%.png',
            'absolute URL with unsafe scheme' => 'javascript:alert(1)',
            'protocol-relative URL' => '//cdn.example.test/image.png',
        ] as $case => $value) {
            $this->assertSame('', $seo->absolutePath($value), $case.' must be rejected without becoming a local path.');
        }
    }

    public function test_social_image_paths_use_the_shared_base_path_composition(): void
    {
        config(['app.env' => 'testing', 'app.url' => 'https://sitio.test/tienda']);
        $seo = app(\App\Services\PublicSeoMetadata::class);

        foreach (['/images/social.png', '/tienda/images/social.png'] as $configuredPath) {
            config(['seo.social_image' => $configuredPath]);
            $this->assertSame('https://sitio.test/tienda/images/social.png', $seo->clientConfig()['socialImage']);
        }

        config(['seo.social_image' => 'https://cdn.example.test/images/social.png']);
        $this->assertSame('https://cdn.example.test/images/social.png', $seo->clientConfig()['socialImage']);

        foreach ([
            '/tienda/images/../private.png',
            '/tienda/images/%252e%252e%252fprivate.png',
            '/tienda/images/social.png?token=synthetic',
            '/tienda/images/social.png#fragment',
        ] as $invalidPath) {
            config(['seo.social_image' => $invalidPath]);
            $this->assertSame(
                'https://sitio.test/tienda/images/lubristore-social.png',
                $seo->clientConfig()['socialImage'],
                'Invalid configured image must not be published.'
            );
        }
    }

    public function test_localhost_remains_available_for_local_and_testing_environments(): void
    {
        config(['app.env' => 'testing', 'app.url' => 'http://localhost']);
        $seo = app(\App\Services\PublicSeoMetadata::class);

        $this->assertTrue($seo->hasPublicBaseUrl());
        $this->assertSame('http://localhost/', $seo->forRequest(Request::create('/'))['seo']['canonical']);
        $this->assertStringContainsString('Sitemap: http://localhost/sitemap.xml', $seo->robotsText());
    }

    public function test_public_metadata_preserves_app_url_subpath_without_duplicate_prefixes(): void
    {
        config(['app.env' => 'production', 'app.url' => 'https://example.org/tienda/']);
        $seo = app(\App\Services\PublicSeoMetadata::class);

        $this->assertSame('https://example.org/tienda/catalogo', $seo->absolutePath('/catalogo'));
        $this->assertSame('https://example.org/tienda/catalogo', $seo->absolutePath('/tienda/catalogo'));
        $this->assertSame('https://example.org/tienda/product/slug', $seo->absolutePath('/product/slug'));
        $this->assertSame('https://example.org/tienda/catalogo?category=aceite', $seo->absolutePath('/catalogo?category=aceite#seccion'));
    }
}
