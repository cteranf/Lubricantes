<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductPromotionsFrontendTest extends TestCase
{
    public function test_admin_offer_form_and_checkout_require_review_of_a_recalculated_total(): void
    {
        $admin = file_get_contents(resource_path('js/views/admin/ProductPromotions.vue'));
        $checkout = file_get_contents(resource_path('js/views/Checkout.vue'));
        $cart = file_get_contents(resource_path('js/stores/cart.js'));
        $resource = file_get_contents(app_path('Http/Resources/ProductResource.php'));
        $resourceSaleField = <<<'SOURCE'
'sale_price' => $salePrice
SOURCE;
        $editEndpoint = <<<'SOURCE'
api.put(`/admin/product-promotions/${form.value.id}`
SOURCE;

        foreach ([
            'Buscar por nombre, modelo o SKU',
            'promotion_price',
            'type="datetime-local"',
            '{{ timezone }}',
            'Ofertas registradas',
            'Desactivar',
            'Activar',
            "api.get('/admin/product-promotions/products'",
            "api.post('/admin/product-promotions'",
            $editEndpoint,
        ] as $expected) {
            $this->assertStringContainsString($expected, $admin);
        }

        $this->assertStringContainsString("price: productPrice(product)", $cart);
        $this->assertStringContainsString($resourceSaleField, $resource);
        $this->assertStringContainsString("expected_total: money(quotedTotal.value)", $checkout);
        $this->assertStringContainsString("code === 'price_changed'", $checkout);
        $this->assertStringContainsString('price_confirmation_token', $checkout);
        $this->assertStringContainsString('Confirmar el nuevo total', $checkout);
        $this->assertStringContainsString('priceChangePrompt.value', $checkout);
        $this->assertStringNotContainsString('sale_price || product.price', $cart);
    }
}
