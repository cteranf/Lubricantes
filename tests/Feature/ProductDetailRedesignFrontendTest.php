<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductDetailRedesignFrontendTest extends TestCase
{
    public function test_product_detail_gallery_uses_public_variants_and_accessible_primevue_lightbox(): void
    {
        $view = file_get_contents(resource_path('js/views/ProductDetail.vue'));
        $helpers = file_get_contents(resource_path('js/utils/productPresentation.js'));

        $mainImage = $this->sourceBetween($view, '<img v-if="selectedImage', '</button>');
        $thumbnail = $this->sourceBetween($view, '<button v-for="(image, index) in images"', '</button>');
        $lightbox = $this->sourceBetween($view, '<Dialog ', '</Dialog>');
        $selection = $this->sourceBetween($view, 'const detailImage = value => {', "\nconst isPublicProduct");

        $this->assertStringContainsString('gallery.find(item => item.is_primary) || gallery[0]', $selection);
        $this->assertStringContainsString(':src="selectedImage.url"', $mainImage);
        $this->assertStringContainsString("productImageSrcset(selectedImage, ['medium', 'detail', 'detail_source', 'zoom'])", $mainImage);
        $this->assertStringContainsString(':width="selectedImageDimensions?.width"', $mainImage);
        $this->assertStringContainsString(':height="selectedImageDimensions?.height"', $mainImage);
        $this->assertStringContainsString('sizes="(max-width: 1024px) 100vw, 50vw"', $view);
        $this->assertStringNotContainsString('loading="lazy"', $mainImage);
        $this->assertStringContainsString('loading="lazy"', $thumbnail);
        $this->assertStringContainsString('aria-pressed="selectedImage?.id === image.id"', $thumbnail);
        $this->assertStringContainsString('selectAdjacentImage(-1)', $view);
        $this->assertStringContainsString('selectAdjacentImage(1)', $view);
        $this->assertStringContainsString('{{ selectedImageIndex + 1 }} / {{ images.length }}', $view);

        $this->assertStringContainsString("import Dialog from 'primevue/dialog'", $view);
        $this->assertStringContainsString('modal', $view);
        $this->assertStringContainsString(':dismissable-mask="true"', $view);
        $this->assertStringContainsString(':close-on-escape="true"', $view);
        $this->assertStringContainsString("'aria-label': 'Cerrar imagen ampliada'", $view);
        $this->assertStringContainsString('productImageSrcset(selectedImage, [\'medium\', \'detail\', \'detail_source\', \'zoom\'])', $lightbox);
        $this->assertStringContainsString('openLightbox', $view);
        $this->assertStringContainsString('lightboxVisible.value = false', $this->sourceBetween($view, 'const loadProduct = async () => {', 'async function loadRelatedProducts'));
        $this->assertStringContainsString('return gallery.length ? gallery : legacyGallery()', $helpers);
        $this->assertStringNotContainsString('original_path', $view);
        $this->assertStringNotContainsString('pending_original_path', $view);
        $this->assertStringNotContainsString('v-html', $view);
    }

    public function test_related_products_use_bounded_public_category_results_and_discard_stale_requests(): void
    {
        $view = file_get_contents(resource_path('js/views/ProductDetail.vue'));
        $loader = $this->sourceBetween($view, 'async function loadRelatedProducts(', "\nfunction selectAdjacentImage");
        $request = $this->sourceBetween($loader, "const { data } = await api.get('/products'", "\n        if (current.signal.aborted");

        $this->assertStringContainsString("params: { category: categorySlug, page: 1, sort: 'newest' }", $request);
        $this->assertStringContainsString('signal: current.signal', $request);
        $this->assertStringContainsString('sequence !== requestId || product.value?.slug !== slug', $loader);
        $this->assertStringContainsString('candidate?.category?.slug === categorySlug', $loader);
        $this->assertStringContainsString('candidate?.is_active === true', $loader);
        $this->assertStringContainsString('seen.has(id)', $loader);
        $this->assertStringContainsString('.slice(0, 4)', $loader);
        $this->assertStringContainsString("import ProductCard from '@/components/ProductCard.vue'", $view);
        $this->assertStringContainsString('<ProductCard v-for="related in relatedProducts"', $view);
        $this->assertStringContainsString('relatedController?.abort()', $view);
        $this->assertStringContainsString('onBeforeUnmount(() => { controller?.abort(); relatedController?.abort(); resetProductReviews(); })', $view);
        $this->assertStringContainsString('category=${encodeURIComponent(product.value.category.slug)}', $view);
    }

    public function test_commercial_summary_preserves_server_price_stock_reviews_and_safe_product_content(): void
    {
        $view = file_get_contents(resource_path('js/views/ProductDetail.vue'));

        $this->assertStringContainsString('formatPen(currentPrice)', $view);
        $this->assertStringContainsString('hasValidSale(product.value)', $view);
        $this->assertStringContainsString('Math.max(0, Number(product.value?.stock || 0))', $view);
        $this->assertStringContainsString('cartStore.addItem(product.value, amount, trigger)', $view);
        $this->assertStringContainsString('Number(reviewSummary.average || 0).toFixed(1)', $view);
        $this->assertStringContainsString('{{ reviewSummary.total }}', $view);
        $this->assertStringContainsString("{ label: 'Presentación', value: product.value?.presentation }", $view);
        $this->assertStringContainsString("{ label: 'Viscosidad', value: product.value?.viscosity }", $view);
        $this->assertStringContainsString("{ label: 'Tipo', value: product.value?.type }", $view);
        $this->assertStringContainsString('.slice(0, 3)', $view);
        $this->assertStringContainsString('whitespace-pre-line leading-7', $view);
        $this->assertStringContainsString('{{ product.description ||', $view);
        $this->assertStringContainsString('product.specifications?.length', $view);
        $this->assertStringContainsString('{{ specification.value }}', $view);
        $this->assertStringContainsString('Resuelve tus dudas del producto por WhatsApp.', $view);
        $this->assertStringNotContainsString('v-html', $view);
    }

    private function sourceBetween(string $source, string $start, string $end): string
    {
        $startOffset = strpos($source, $start);
        $this->assertNotFalse($startOffset, "Expected source section start was not found: {$start}");
        $endOffset = strpos($source, $end, $startOffset + strlen($start));
        $this->assertNotFalse($endOffset, "Expected source section end was not found: {$end}");

        return substr($source, $startOffset, $endOffset - $startOffset);
    }
}
