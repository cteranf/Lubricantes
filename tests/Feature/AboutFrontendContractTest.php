<?php

namespace Tests\Feature;

use Tests\TestCase;

class AboutFrontendContractTest extends TestCase
{
    public function test_about_page_keeps_public_route_layout_and_central_seo_integration(): void
    {
        $view = $this->aboutSource();
        $router = file_get_contents(resource_path('js/router.js'));
        $seo = file_get_contents(resource_path('js/composables/useSiteSeo.js'));

        $this->assertStringContainsString("{ path: '/about', name: 'About', component: About }", $router);
        $this->assertStringContainsString("import AppLayout from '@/layouts/AppLayout.vue';", $view);
        $this->assertStringContainsString('<AppLayout>', $view);
        $this->assertStringContainsString("import { getSiteSeoConfig, useSiteSeo } from '@/composables/useSiteSeo';", $view);
        $this->assertStringContainsString("canonicalPath: '/about'", $view);
        $this->assertStringContainsString('useSiteSeo(() => ({', $view);
        $this->assertStringContainsString("'/about' => ['Sobre LubriStore'", file_get_contents(app_path('Services/PublicSeoMetadata.php')));
        $this->assertNotFalse($seo);
    }

    public function test_page_has_one_compact_hero_and_semantic_story_with_an_in_page_fallback_illustration(): void
    {
        $view = $this->aboutSource();
        $h1Count = preg_match_all('/<h1\b/i', $view);

        $this->assertSame(1, $h1Count);
        $this->assertStringContainsString('id="about-title"', $view);
        $this->assertStringContainsString('Sobre Nosotros', $view);
        $this->assertStringContainsString('aria-labelledby="about-title"', $view);
        $this->assertStringContainsString('aria-labelledby="about-story-title"', $view);
        $this->assertStringContainsString('Nuestra historia', $view);
        $this->assertStringContainsString('viewBox="0 0 640 460" width="640" height="460"', $view);
        $this->assertStringContainsString('Ilustración conceptual; no representa una instalación real.', $view);
        $this->assertSame(0, preg_match('/<img\b[^>]*\bsrc=["\']https?:\/\//i', $view));
        $this->assertSame(0, preg_match('/(?:src|href)=["\'](?:https?:)?\/\//i', $view));
        $this->assertStringNotContainsString('v-html', $view);
        $this->assertStringContainsString('leading-7 text-slate-600', $view);
    }

    public function test_only_the_three_existing_value_themes_are_rendered_without_unverified_indicators_or_promises(): void
    {
        $view = $this->aboutSource();
        $values = $this->between($view, 'const values = [', '];');

        $this->assertSame(3, substr_count($values, 'title:'));
        $this->assertStringContainsString('title: \'Calidad de producto\'', $values);
        $this->assertStringContainsString('title: \'Orientación\'', $values);
        $this->assertStringContainsString('title: \'Cuidado preventivo\'', $values);
        $this->assertStringContainsString('v-for="value in values"', $view);
        $this->assertStringNotContainsString('10k+', $view);
        $this->assertStringNotContainsString('5+', $view);
        $this->assertStringNotContainsString('Años de experiencia', $view);
        $this->assertStringNotContainsString('Clientes satisfechos', $view);
        $this->assertStringNotContainsString('Garantizamos', $view);
        $this->assertStringNotContainsString('a todo el país', $view);
        $this->assertStringNotContainsString('líderes', $view);
        $this->assertStringNotContainsString('Nuestra misión', $view);
        $this->assertStringNotContainsString('Nuestra visión', $view);
    }

    public function test_real_catalog_and_contact_calls_to_action_are_accessible_and_responsive(): void
    {
        $view = $this->aboutSource();
        $router = file_get_contents(resource_path('js/router.js'));

        $this->assertStringContainsString("{ path: '/catalog', name: 'Catalog'", $router);
        $this->assertStringContainsString("{ path: '/contact', component: Contact }", $router);
        $this->assertSame(2, substr_count($view, ':to="{ name: \'Catalog\' }"'));
        $this->assertStringContainsString('to="/contact"', $view);
        $this->assertStringContainsString('Explorar catálogo', $view);
        $this->assertStringContainsString('Contáctanos', $view);
        $this->assertStringContainsString('focus-visible:outline', $view);
        $this->assertStringContainsString('grid-cols-1', $view);
        $this->assertStringContainsString('sm:grid-cols-2', $view);
        $this->assertStringContainsString('lg:grid-cols-3', $view);
        $this->assertStringContainsString('max-width: 360px', $view);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $view);
    }

    public function test_hero_and_cta_have_explicit_dark_blue_surfaces_and_section_spacing_is_compact(): void
    {
        $view = $this->aboutSource();
        $styles = $this->between($view, '<style scoped>', '</style>');
        $heroStyles = $this->between($styles, '.about-hero {', '}');
        $ctaStyles = $this->between($styles, '.about-cta {', '}');

        $this->assertStringContainsString('about-hero relative isolate overflow-hidden', $view);
        $this->assertStringContainsString('text-white', $view);
        $this->assertStringContainsString('.about-hero {', $styles);
        $this->assertStringContainsString('background-color: #172554;', $styles);
        $this->assertStringContainsString('background-image: linear-gradient(120deg, #172554 0%, #1e3a8a 56%, #1d4ed8 100%);', $styles);
        $this->assertStringContainsString('.about-cta {', $styles);
        $this->assertStringContainsString('background-color: #172554;', substr($styles, strpos($styles, '.about-cta {')));
        $this->assertStringContainsString('max-width: 82rem;', $styles);
        $this->assertSame('about-container', $this->containsClass($view, 'about-container'));
        $this->assertStringContainsString('grid items-center gap-9 lg:grid-cols-2 lg:gap-14', $view);
        $this->assertStringContainsString('py-8 sm:py-9 lg:py-10', $view);
        $this->assertStringNotContainsString('py-12 sm:py-16 lg:py-20', $view);
        $this->assertStringNotContainsString('min-h-screen', $view);
        $this->assertStringNotContainsString('min-height:', $heroStyles);
        $this->assertStringNotContainsString('min-height:', $ctaStyles);
        $this->assertStringContainsString('bg-white px-4 py-2 text-center text-xs text-slate-600', $view);
    }

    private function aboutSource(): string
    {
        return file_get_contents(resource_path('js/views/About.vue'));
    }

    private function between(string $contents, string $start, string $end): string
    {
        $offset = strpos($contents, $start);
        $this->assertNotFalse($offset, "No se encontró el inicio: $start");
        $endOffset = strpos($contents, $end, $offset + strlen($start));
        $this->assertNotFalse($endOffset, "No se encontró el final: $end");

        return substr($contents, $offset, $endOffset - $offset);
    }

    private function containsClass(string $contents, string $class): string
    {
        $this->assertStringContainsString('class="site-container '.$class, $contents);

        return $class;
    }
}
