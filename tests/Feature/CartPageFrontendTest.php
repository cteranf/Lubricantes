<?php

namespace Tests\Feature;

use Tests\TestCase;

class CartPageFrontendTest extends TestCase
{
    public function test_cart_page_uses_the_shared_store_and_existing_checkout_contract(): void
    {
        $view = file_get_contents(resource_path('js/views/Cart.vue'));
        $store = file_get_contents(resource_path('js/stores/cart.js'));
        $navbar = file_get_contents(resource_path('js/components/Navbar.vue'));
        $drawer = file_get_contents(resource_path('js/components/cart/CartDrawer.vue'));
        $checkout = file_get_contents(resource_path('js/views/Checkout.vue'));

        $this->assertStringContainsString("import { useCartStore } from '@/stores/cart'", $view);
        $this->assertStringContainsString('const cartStore = useCartStore();', $view);
        $this->assertStringContainsString('v-for="item in cartStore.items"', $view);
        $this->assertStringNotContainsString('ref([])', $view);
        $this->assertStringNotContainsString('<table', $view);
        $this->assertStringContainsString('cartStore.count', $view);
        $this->assertStringContainsString('formatPen(cartStore.total)', $view);
        $this->assertStringContainsString('count: (state) => state.items.reduce((acc, item) => acc + item.quantity, 0)', $store);
        $this->assertStringContainsString('total: (state) => state.items.reduce((acc, item) => acc + (item.price * item.quantity), 0)', $store);
        $this->assertStringContainsString('cartStore.count', $navbar);
        $this->assertStringContainsString('cartStore.count', $drawer);
        $this->assertStringContainsString('cartStore.total', $drawer);
        $this->assertStringContainsString('items: cartStore.items.map(item => ({ product_id: item.product_id, quantity: item.quantity }))', $checkout);
        $this->assertStringContainsString('to="/checkout"', $view);
        $this->assertStringContainsString('to="/catalog"', $view);
        $this->assertStringNotContainsString('openDrawer', $view);
    }

    public function test_cart_items_are_responsive_accessible_and_use_safe_product_presentation(): void
    {
        $view = file_get_contents(resource_path('js/views/Cart.vue'));
        $item = $this->sourceBetween($view, '<li v-for="item in cartStore.items"', '</li>');

        $this->assertStringContainsString(':src="item.image"', $item);
        $this->assertStringContainsString('object-contain', $item);
        $this->assertStringContainsString('role="img"', $item);
        $this->assertStringContainsString('Imagen no disponible', $item);
        $this->assertStringContainsString('productPath(item)', $item);
        $this->assertStringContainsString('formatPen(item.price)', $item);
        $this->assertStringContainsString('formatPen(lineSubtotal(item))', $item);
        $this->assertStringContainsString('@input="validateQuantity(item, $event)"', $item);
        $this->assertStringContainsString('cartQuantityLimit(item)', $item);
        $this->assertStringContainsString('normalizeCartQuantity(value, item)', $view);
        $this->assertStringContainsString('cartStore.updateQuantity(item.product_id, quantity)', $view);
        $this->assertStringContainsString('cartStore.removeItem(item.product_id)', $view);
        $this->assertStringContainsString(':aria-label="`Disminuir cantidad de ${item.name}`"', $item);
        $this->assertStringContainsString(':aria-label="`Aumentar cantidad de ${item.name}`"', $item);
        $this->assertStringContainsString(':aria-label="`Quitar ${item.name} del carrito`"', $item);
        $this->assertStringContainsString('aria-live="polite"', $view);
        $this->assertStringContainsString('sm:grid-cols-', $view);
        $this->assertStringContainsString('Se confirmará nuevamente durante el checkout.', $item);
        $this->assertStringNotContainsString('v-html', $view);
    }

    public function test_summary_and_empty_state_are_honest_and_keep_existing_navigation(): void
    {
        $view = file_get_contents(resource_path('js/views/Cart.vue'));
        $summary = $this->sourceBetween($view, '<aside ', '</aside>');
        $empty = $this->sourceBetween($view, '<section v-else class="mx-auto', '</section>');

        $this->assertStringContainsString('Resumen del pedido', $summary);
        $this->assertStringContainsString('Subtotal actual', $summary);
        $this->assertStringContainsString('cartStore.count', $summary);
        $this->assertStringContainsString('cartStore.total', $summary);
        $this->assertStringContainsString('Envío o recojo se define durante el checkout.', $summary);
        $this->assertStringContainsString('El servidor confirmará los precios, la disponibilidad y los cargos definitivos', $summary);
        $this->assertStringNotContainsString('Total final', $summary);
        $this->assertStringNotContainsString('Envío gratis', $summary);
        $this->assertStringContainsString('Tu carrito está vacío', $empty);
        $this->assertStringContainsString('Explorar productos', $empty);
        $this->assertStringContainsString('to="/catalog"', $empty);
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
