<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductReviewsFrontendTest extends TestCase
{
    public function test_rating_control_keeps_primevue_clicks_and_numeric_model_accessible(): void
    {
        $detail = file_get_contents(resource_path('js/views/ProductDetail.vue'));
        $appCss = file_get_contents(resource_path('css/app.css'));
        $this->assertIsString($detail);
        $this->assertIsString($appCss);

        $this->assertSame(1, preg_match('~<fieldset\b[^>]*>.*?</fieldset>~s', $detail, $ratingGroup));
        $this->assertMatchesRegularExpression('~<legend\b[^>]*id="review-rating-label"[^>]*>\s*Calificación\s*</legend>~', $ratingGroup[0]);
        $this->assertMatchesRegularExpression('~<Rating\b(?=[^>]*v-model="reviewRating")(?=[^>]*name="product-review-rating")(?=[^>]*:stars="5")(?=[^>]*:cancel="false")(?=[^>]*aria-labelledby="review-rating-label")[^>]*/>~', $ratingGroup[0]);
        $this->assertDoesNotMatchRegularExpression('~<label\b~i', $ratingGroup[0], 'The Rating radios must not be nested in an implicit native label.');

        $this->assertSame(1, preg_match('/const reviewRating = computed\(\{(?<body>.*?)\n\}\);/s', $detail, $ratingModel));
        $this->assertSame(2, substr_count($ratingModel['body'], 'Number.isInteger(rating) && rating >= 1 && rating <= 5 ? rating : 0'));
        $this->assertStringContainsString("reviewForm.value = { rating: 0, title: '', comment: '' }", $detail);
        $this->assertStringContainsString('rating: Number(data.existing_review.rating)', $detail);
        $this->assertStringContainsString('rating: Number(reviewForm.value.rating)', $detail);

        $this->assertDoesNotMatchRegularExpression(
            '/(?:^|})\s*input(?:\s*,\s*input)?\s*\{[^}]*\b(?:width|position|inset|top|left|right|bottom|transform)\s*:/is',
            $detail."\n".$appCss,
            'Global layout rules must not stretch or overlay PrimeVue Rating radio inputs.'
        );
    }

    public function test_product_detail_loads_reviews_on_expansion_and_renders_safe_states(): void
    {
        $detail = file_get_contents(resource_path('js/views/ProductDetail.vue'));
        $this->assertIsString($detail);
        $this->assertStringContainsString('aria-expanded="reviewsExpanded"', $detail);
        $this->assertStringContainsString('aria-controls="product-reviews-panel"', $detail);
        $this->assertStringContainsString('Mostrar opiniones (', $detail);
        $this->assertStringContainsString('Ocultar opiniones', $detail);
        $this->assertStringContainsString('Promise.all([loadReviewEligibility(), loadReviewPage(1)])', $detail);
        $this->assertStringContainsString('if (reviewInitialLoadPromise) return reviewInitialLoadPromise;', $detail);
        $this->assertStringContainsString('/reviews/summary', $detail);
        $this->assertStringContainsString('/reviews`, { params', $detail);
        $this->assertStringContainsString('onBeforeUnmount(() => { controller?.abort(); resetProductReviews(); })', $detail);
        $this->assertStringContainsString('sequence !== reviewSequence', $detail);
        $this->assertStringNotContainsString('v-html', $detail);
        $this->assertStringNotContainsString('user_id', $detail);
        $this->assertStringNotContainsString('order_id', $detail);
        $this->assertStringNotContainsString('email', $detail);
    }

    public function test_customer_tracking_links_to_reviews_and_detail_consumes_open_query_once(): void
    {
        $tracking = file_get_contents(resource_path('js/views/OrderTracking.vue'));
        $detail = file_get_contents(resource_path('js/views/ProductDetail.vue'));
        $api = file_get_contents(base_path('routes/api.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/V1/OrderTrackingController.php'));
        $service = file_get_contents(app_path('Services/ProductReviewService.php'));

        $this->assertStringContainsString("Route::middleware(['auth:sanctum', 'active_user'])->group", $api);
        $this->assertStringContainsString("Route::get('/orders/{id}/tracking'", $api);
        $this->assertStringContainsString('trackingReviewAccess($order, $user)', $controller);
        $this->assertStringContainsString('$user->is_active', $service);
        $this->assertStringContainsString('(int) $order->user_id !== (int) $user->id', $service);
        $this->assertStringContainsString(<<<'PHP'
$order->user_id
PHP, $service);
        $this->assertStringContainsString('itemPayload[\'review_access\']', $controller);
        $this->assertStringContainsString("query: { reviews: 'open' }", $tracking);
        $this->assertStringContainsString(<<<'JS'
path: `/product/${encodeURIComponent(slug)}`
JS, $tracking);
        $reviewButtonPattern = <<<'REGEX'
~<Button\b(?=[^>]*\bicon\s*=\s*["']pi pi-star["'])(?=[^>]*@click="openProductReviews\(item\)")[^>]*>~
REGEX;
        $this->assertMatchesRegularExpression($reviewButtonPattern, $tracking);
        $this->assertStringContainsString('Opinión pendiente de revisión', $tracking);
        $this->assertStringContainsString('w-full sm:w-auto', $tracking);
        $this->assertStringContainsString(<<<'JS'
:aria-label="`${reviewAction(item)}: ${item.name}`"
JS, $tracking);
        $this->assertStringNotContainsString('review-eligibility', $tracking);
        $this->assertStringNotContainsString('v-html', $tracking);

        $this->assertStringContainsString("route.query.reviews !== 'open'", $detail);
        $this->assertStringContainsString('delete query.reviews;', $detail);
        $this->assertStringContainsString('...route.query', $detail);
        $this->assertStringContainsString('router.replace({ name: route.name, params: route.params, query, hash: route.hash })', $detail);
        $this->assertStringContainsString('reviewSummary.value?.enabled', $detail);
        $this->assertStringContainsString('reviewFocusTarget.value?.focus()', $detail);
        $this->assertStringContainsString('if (reviewInitialLoadPromise) return reviewInitialLoadPromise;', $detail);
        $this->assertStringContainsString('sequence === requestId && product.value?.slug === slug', $detail);
        $this->assertStringNotContainsString('v-html', $detail);
    }

    public function test_admin_reviews_routes_and_view_are_guarded_paginated_and_moderated(): void
    {
        $router = file_get_contents(resource_path('js/router.js'));
        $view = file_get_contents(resource_path('js/views/admin/ProductReviews.vue'));
        $layout = file_get_contents(resource_path('js/layouts/AdminLayout.vue'));
        $api = file_get_contents(base_path('routes/api.php'));
        $this->assertStringContainsString("name: 'admin-product-reviews'", $router);
        $this->assertStringContainsString('meta: { requiresAdmin: true }', $router);
        $this->assertStringContainsString("{ label: 'Opiniones', to: '/admin/product-reviews'", $layout);
        $this->assertStringContainsString("Route::get('/product-reviews'", $api);
        $this->assertStringContainsString("Route::patch('/product-reviews/{review}/moderation'", $api);
        $this->assertStringContainsString('<DataTable :value="rows" lazy paginator', $view);
        $this->assertStringContainsString('function confirmAction', $view);
        $this->assertStringContainsString('if (busy.has(row.id)) return;', $view);
        $this->assertStringContainsString('rejection_reason', $view);
        $this->assertStringNotContainsString('customer_email', $view);
        $this->assertStringNotContainsString('customer_phone', $view);
    }

    public function test_product_admin_toggle_uses_existing_product_save_contract(): void
    {
        $view = file_get_contents(resource_path('js/views/admin/Products.vue'));
        $request = file_get_contents(app_path('Http/Requests/Admin/StoreProductRequest.php'));
        $update = file_get_contents(app_path('Http/Requests/Admin/UpdateProductRequest.php'));
        $resource = file_get_contents(app_path('Http/Resources/AdminProductListResource.php'));
        $this->assertStringContainsString('Permitir opiniones para este producto', $view);
        $this->assertStringContainsString("data.append('allow_reviews'", $view);
        $this->assertStringContainsString("'allow_reviews' => ['sometimes', 'boolean']", $request);
        $this->assertStringContainsString("'allow_reviews' => ['sometimes', 'boolean']", $update);
        $expectedResourceField = <<<'PHP'
'allow_reviews' => (bool) $this->allow_reviews
PHP;
        $this->assertStringContainsString($expectedResourceField, $resource);
        $this->assertStringNotContainsString("'reviews' =>", file_get_contents(app_path('Http/Resources/ProductResource.php')));
    }
}
