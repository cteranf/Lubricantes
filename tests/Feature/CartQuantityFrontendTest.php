<?php

namespace Tests\Feature;

use Tests\TestCase;

class CartQuantityFrontendTest extends TestCase
{
    public function test_cart_quantity_does_not_depend_on_the_global_event_object(): void
    {
        $source = file_get_contents(resource_path('js/views/Cart.vue'));

        $this->assertStringContainsString('@input="validateQuantity(item, $event)"', $source);
        $this->assertStringContainsString('const validateQuantity = (item, valueOrEvent)', $source);
        $this->assertStringNotContainsString('const input = event.target', $source);
        $this->assertStringNotContainsString('@blur="validateQuantity(item)"', $source);
    }

    public function test_cart_and_store_share_the_same_quantity_normalizer(): void
    {
        $view = file_get_contents(resource_path('js/views/Cart.vue'));
        $store = file_get_contents(resource_path('js/stores/cart.js'));
        $normalizer = file_get_contents(resource_path('js/utils/cartQuantity.js'));

        $this->assertStringContainsString("from '@/utils/cartQuantity'", $view);
        $this->assertStringContainsString("from '@/utils/cartQuantity'", $store);
        $this->assertStringContainsString('Number.isFinite', $normalizer);
        $this->assertStringContainsString('Math.trunc', $normalizer);
        $this->assertStringContainsString('MAX_CART_QUANTITY', $normalizer);
    }
}
