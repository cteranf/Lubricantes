<?php

namespace Tests\Feature;

use Tests\TestCase;

class NewsPublicFrontendContractTest extends TestCase
{
    public function test_home_limits_recent_public_news_and_adapts_the_card_layout_to_the_result_count(): void
    {
        $home = file_get_contents(resource_path('js/views/Home.vue'));
        $card = file_get_contents(resource_path('js/components/NewsCard.vue'));
        $utility = file_get_contents(resource_path('js/utils/newsPublic.mjs'));

        $this->assertStringContainsString("api.get('/news', { params: { per_page: NEWS_HOME_LIMIT } })", $home);
        $this->assertStringContainsString('normalizeNewsPage(response.data).items.slice(0, NEWS_HOME_LIMIT)', $home);
        $this->assertStringContainsString('Noticias y guías', $home);
        $this->assertStringContainsString('Consejos y novedades para cuidar tu vehículo.', $home);
        $this->assertStringContainsString("newsLayoutVariant(newsList.length) === 'featured'", $home);
        $this->assertStringContainsString("newsLayoutVariant(newsList.length) === 'two'", $home);
        $this->assertStringContainsString('Ver todas las guías', $home);
        $this->assertStringContainsString('to="/guias"', $home);
        $this->assertStringContainsString("if (count === 1) return 'featured';", $utility);
        $this->assertStringContainsString("if (count === 2) return 'two';", $utility);
        $this->assertStringContainsString("return 'grid';", $utility);
        $this->assertStringContainsString('NEWS_HOME_LIMIT = 3', $utility);
        $this->assertStringContainsString('heading-level="h3"', $home);
        $this->assertStringContainsString("name: 'GuideDetail', params: { slug: article.slug }", $card);
    }

    public function test_public_guide_routes_and_card_use_the_real_public_resource_contract(): void
    {
        $router = file_get_contents(resource_path('js/router.js'));
        $card = file_get_contents(resource_path('js/components/NewsCard.vue'));

        $this->assertStringContainsString("path: '/guias', name: 'GuideIndex'", $router);
        $this->assertStringContainsString("path: '/guias/:slug', name: 'GuideDetail'", $router);
        $this->assertStringNotContainsString("path: '/news", $router);
        $this->assertStringContainsString('article.image_url', $card);
        $this->assertStringNotContainsString('image_path', $card);
        $this->assertStringContainsString('width="640"', $card);
        $this->assertStringContainsString('height="360"', $card);
        $this->assertStringContainsString('loading="lazy"', $card);
        $this->assertStringContainsString('formatCalendarDate(article.published_at)', $card);
        $this->assertStringContainsString('Leer artículo', $card);
        $this->assertStringContainsString('pi-book', $card);
        $this->assertStringContainsString('focus-visible:outline', $card);
        $this->assertStringContainsString('motion-reduce:transition-none', $card);
        $this->assertSame(1, substr_count($card, '<RouterLink'));
        $this->assertStringNotContainsString('<a ', $card);
    }

    public function test_guide_listing_uses_server_pagination_debounced_search_and_stale_request_guards(): void
    {
        $listing = file_get_contents(resource_path('js/views/NewsIndex.vue'));
        $utility = file_get_contents(resource_path('js/utils/newsPublic.mjs'));

        $this->assertStringContainsString('Consejos y guías', $listing);
        $this->assertStringContainsString('Información útil sobre lubricación y mantenimiento automotriz', $listing);
        $this->assertStringContainsString("api.get('/news', { params, signal: controller.signal })", $listing);
        $this->assertStringContainsString('params = { page: query.page, per_page: NEWS_PAGE_SIZE }', $listing);
        $this->assertStringContainsString('params.q = query.q', $listing);
        $this->assertStringContainsString('setTimeout(applySearch, 350)', $listing);
        $this->assertStringContainsString("router.replace({ name: 'GuideIndex', query })", $listing);
        $this->assertStringContainsString("router.push({ name: 'GuideIndex'", $listing);
        $this->assertStringContainsString('requestController?.abort()', $listing);
        $this->assertStringContainsString('sequence !== requestSequence', $listing);
        $this->assertStringContainsString('newsResultSummary({', $listing);
        $this->assertStringContainsString('total: total.value', $listing);
        $this->assertStringContainsString('itemCount: items.value.length', $listing);
        $this->assertStringContainsString('Paginación de guías', $listing);
        $this->assertStringContainsString('No pudimos cargar las guías', $listing);
        $this->assertStringContainsString('Aún no hay guías publicadas', $listing);
        $this->assertStringContainsString('NEWS_PAGE_SIZE = 9', $utility);
        $this->assertStringContainsString('meta.total', $utility);
        $this->assertStringContainsString('const noun = total === 1 ?', $utility);
        $this->assertStringContainsString('if (total === 0)', $utility);
        $this->assertStringNotContainsString('category', $listing);
    }

    public function test_detail_renders_only_server_safe_content_handles_stale_slugs_and_sets_error_seo(): void
    {
        $detail = file_get_contents(resource_path('js/views/NewsDetail.vue'));

        $this->assertStringContainsString('api.get(`/news/${encodeURIComponent(articleSlug)}`', $detail);
        $this->assertStringContainsString('const result = response.data?.data', $detail);
        $invalidContentGuard = <<<'REGEX'
/if\s*\(!result\s*\|\|\s*typeof result !== 'object'\s*\|\|\s*typeof result\.title !== 'string'\s*\|\|\s*typeof result\.slug !== 'string'\s*\|\|\s*typeof result\.content_html !== 'string'\)\s*\{\s*loadError\.value = true;\s*return;\s*\}/s
REGEX;
        $this->assertMatchesRegularExpression($invalidContentGuard, $detail);
        $this->assertSame(1, preg_match_all('/v-html\s*=\s*"([^"]+)"/', $detail, $htmlBindings));
        $this->assertSame(['article.content_html'], $htmlBindings[1]);
        $this->assertStringContainsString('NewsContentRenderer', $detail);
        $this->assertStringNotContainsString('article.content ', $detail);
        $this->assertStringContainsString('error?.response?.status === 404', $detail);
        $this->assertStringContainsString('String(route.params.slug || \'\') !== articleSlug', $detail);
        $this->assertStringContainsString('robots: \'noindex,nofollow\'', $detail);
        $this->assertStringContainsString('robots: \'index,follow\'', $detail);
        $this->assertStringContainsString('`/guias/${encodeURIComponent(article.value.slug)}`', $detail);
        $this->assertStringContainsString('content_html proviene exclusivamente', $detail);
        $this->assertStringContainsString('Volver a las guías', $detail);
        $this->assertStringContainsString('width="1200"', $detail);
        $this->assertStringContainsString('height="675"', $detail);
        $this->assertStringContainsString('news-article-content :deep(blockquote)', $detail);
    }

    public function test_list_and_detail_keep_responsive_focus_and_reduced_motion_support(): void
    {
        $home = file_get_contents(resource_path('js/views/Home.vue'));
        $listing = file_get_contents(resource_path('js/views/NewsIndex.vue'));
        $detail = file_get_contents(resource_path('js/views/NewsDetail.vue'));
        $card = file_get_contents(resource_path('js/components/NewsCard.vue'));

        $this->assertStringContainsString('md:grid-cols-2 xl:grid-cols-3', $home);
        $this->assertStringContainsString('max-w-4xl', $home);
        $this->assertStringContainsString('md:grid-cols-2 xl:grid-cols-3', $listing);
        $this->assertStringContainsString('focus-visible:outline', $listing);
        $this->assertStringContainsString('focus-visible:outline', $detail);
        $this->assertStringContainsString('focus-visible:outline', $card);
        $this->assertStringContainsString('motion-safe:transition-transform', $card);
        $this->assertStringContainsString('motion-reduce:transition-none', $card);
    }
}
