<?php

namespace Tests\Feature;

use App\Models\News;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NewsEditorialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Storage::fake('public');
    }

    public function test_public_listing_only_returns_current_publications_with_allowlisted_fields_and_pagination(): void
    {
        $current = $this->news(['title' => 'Guía de filtros', 'slug' => 'guia-filtros', 'summary' => 'Consejos', 'published_at' => now()->subDay()]);
        $newer = $this->news(['title' => 'Guía de aceites', 'slug' => 'guia-aceites', 'published_at' => now()]);
        $this->news(['title' => 'Borrador', 'slug' => 'borrador', 'is_active' => false, 'published_at' => now()]);
        $this->news(['title' => 'Activa sin fecha', 'slug' => 'sin-fecha', 'published_at' => null]);
        $this->news(['title' => 'Futura', 'slug' => 'futura', 'published_at' => now()->addDay()]);

        $response = $this->getJson('/api/v1/news?per_page=1&page=1&q=guía')->assertOk();
        $response->assertJsonPath('data.0.slug', $newer->slug);
        $response->assertJsonPath('meta.per_page', 1);
        $this->assertSame(
            ['slug', 'title', 'summary', 'image_url', 'published_at'],
            array_keys($response->json('data.0'))
        );
        $this->assertNotSame($current->slug, $response->json('data.0.slug'));
        $this->assertArrayNotHasKey('image_path', $response->json('data.0'));
        $this->assertArrayNotHasKey('is_active', $response->json('data.0'));

        $secondPage = $this->getJson('/api/v1/news?per_page=1&page=2&q=guía')->assertOk();
        $secondPage->assertJsonPath('data.0.slug', $current->slug);
    }

    public function test_public_detail_escapes_raw_html_blocks_unsafe_links_and_inline_images(): void
    {
        $news = $this->news([
            'slug' => 'contenido-seguro',
            'content' => "# Encabezado\n\n**Texto seguro** y [enlace](https://example.test).\n\n<script>alert(1)</script>\n\n[enlace peligroso](javascript:alert(1))\n\n![imagen](https://example.test/image.png)",
        ]);

        $response = $this->getJson('/api/v1/news/'.$news->slug)->assertOk();
        $response->assertJsonPath('data.slug', $news->slug);
        $html = $response->json('data.content_html');
        $this->assertStringContainsString('<h1>Encabezado</h1>', $html);
        $this->assertStringContainsString('<strong>Texto seguro</strong>', $html);
        $this->assertStringContainsString('href="https://example.test"', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertArrayNotHasKey('content', $response->json('data'));
        $this->assertArrayNotHasKey('image_path', $response->json('data'));
        $this->assertArrayNotHasKey('is_active', $response->json('data'));
    }

    public function test_drafts_future_articles_and_articles_without_publication_date_are_not_public(): void
    {
        $draft = $this->news(['slug' => 'draft', 'is_active' => false, 'published_at' => now()]);
        $withoutDate = $this->news(['slug' => 'without-date', 'published_at' => null]);
        $future = $this->news(['slug' => 'future', 'published_at' => now()->addDay()]);

        foreach ([$draft, $withoutDate, $future] as $article) {
            $this->getJson('/api/v1/news/'.$article->slug)->assertNotFound();
        }
        $this->assertSame([], $this->getJson('/api/v1/news')->assertOk()->json('data'));
    }

    public function test_admin_creates_drafts_resolves_slug_collisions_and_keeps_slug_stable_on_title_edit(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $payload = ['title' => 'Guía técnica', 'summary' => 'Resumen', 'content' => 'Texto', 'image' => $this->validImage()];

        $first = $this->post('/api/v1/admin/news', $payload)->assertCreated();
        $firstNews = News::findOrFail($first->json('id'));
        $this->assertSame('guia-tecnica', $firstNews->slug);
        $this->assertFalse($firstNews->is_active);
        $this->assertNull($firstNews->published_at);

        $second = $this->post('/api/v1/admin/news', $payload)->assertCreated();
        $secondNews = News::findOrFail($second->json('id'));
        $this->assertSame('guia-tecnica-2', $secondNews->slug);

        $this->putJson('/api/v1/admin/news/'.$firstNews->id, [
            'title' => 'Título actualizado',
            'summary' => 'Resumen actualizado',
            'is_active' => true,
        ])->assertOk();
        $firstNews->refresh();
        $this->assertSame('guia-tecnica', $firstNews->slug);
        $this->assertTrue($firstNews->is_active);
        $this->assertNotNull($firstNews->published_at);

        $publishedAt = $firstNews->published_at->toAtomString();
        $this->putJson('/api/v1/admin/news/'.$firstNews->id, [
            'title' => 'Otro título',
            'summary' => 'Otro resumen',
            'is_active' => false,
        ])->assertOk();
        $this->assertSame($publishedAt, $firstNews->fresh()->published_at->toAtomString());
    }

    public function test_admin_rejects_unauthorized_users_and_invalid_image_payloads(): void
    {
        $payload = ['title' => 'Noticia', 'summary' => 'Resumen', 'image' => $this->validImage()];
        $this->postJson('/api/v1/admin/news', $payload)->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(['role' => 'customer', 'is_active' => true]));
        $this->postJson('/api/v1/admin/news', $payload)->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $this->post('/api/v1/admin/news', [
            'title' => 'Archivo falso',
            'summary' => 'Resumen',
            'image' => UploadedFile::fake()->create('fake.png', 2, 'image/png'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_existing_active_articles_with_a_publication_date_remain_visible(): void
    {
        $legacy = $this->news(['slug' => 'legacy', 'title' => 'Noticia antigua', 'is_active' => true, 'published_at' => now()->subYear()]);

        $this->getJson('/api/v1/news')->assertOk()->assertJsonPath('data.0.slug', $legacy->slug);
        $this->getJson('/api/v1/news/'.$legacy->slug)->assertOk()->assertJsonPath('data.slug', $legacy->slug);
    }

    private function news(array $overrides = []): News
    {
        static $index = 0;
        $index++;

        return News::query()->create(array_merge([
            'title' => 'Noticia '.$index,
            'slug' => 'noticia-'.$index,
            'summary' => 'Resumen '.$index,
            'content' => 'Contenido '.$index,
            'image_path' => null,
            'is_active' => true,
            'published_at' => now()->subMinute(),
        ], $overrides));
    }

    private function validImage(): UploadedFile
    {
        return UploadedFile::fake()->image('news.png', 800, 600);
    }
}
