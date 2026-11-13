<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductCardCartDrawerFrontendTest extends TestCase
{
    public function test_product_card_uses_public_responsive_images_and_adds_without_navigating(): void
    {
        $card = file_get_contents(resource_path('js/components/ProductCard.vue'));
        $detail = file_get_contents(resource_path('js/views/ProductDetail.vue'));
        $add = $this->extractBodyAfter($card, 'const addToCart = async event =>');
        $detailAdd = $this->extractBodyAfter($detail, 'const addToCart = async event =>');

        $this->assertMatchesRegularExpression('/<img[^>]*:src="primaryImage"[^>]*:srcset="productImageSrcset\(product, \[\x27card\x27, \x27medium\x27\]\)"[^>]*loading="lazy"/s', $card);
        $this->assertStringContainsString('object-contain', $card);
        $this->assertStringContainsString('productImageDimensions(props.product)', $card);
        $this->assertSame(2, substr_count($card, ':to="productRoute"'));
        $this->assertStringContainsString('const trigger = event?.currentTarget || null;', $add);
        $this->assertStringContainsString('cartStore.addItem(props.product, 1, trigger)', $add);
        $this->assertLessThan(strpos($add, 'await nextTick()'), strpos($add, 'const trigger = event?.currentTarget || null;'));
        $this->assertStringContainsString('cartStore.addItem(product.value, amount, trigger)', $detailAdd);
        $this->assertLessThan(strpos($detailAdd, 'await nextTick()'), strpos($detailAdd, 'const trigger = event?.currentTarget || null;'));
        $this->assertStringNotContainsString('router.push', $add);
        $this->assertStringNotContainsString('toast.add({ severity: \'success\'', $add);
        $this->assertStringContainsString('adding.value || justAdded.value', $card);
        $this->assertStringContainsString('if (!hasStock.value || adding.value || justAdded.value) return;', $add);
        $this->assertStringContainsString('No se pudo actualizar el carrito. Inténtalo nuevamente.', $add);
        $this->assertStringContainsString("const buttonText = computed(() => !hasStock.value ? 'Sin stock'", $card);
    }

    public function test_cart_drawer_is_mounted_once_and_uses_primevue_modal_accessibility(): void
    {
        $layout = file_get_contents(resource_path('js/layouts/AppLayout.vue'));
        $drawer = file_get_contents(resource_path('js/components/cart/CartDrawer.vue'));
        $navbar = file_get_contents(resource_path('js/components/Navbar.vue'));

        $this->assertSame(1, substr_count($layout, '<CartDrawer />'));
        $this->assertStringContainsString("import Drawer from 'primevue/drawer'", $drawer);
        $this->assertStringContainsString(':modal="true"', $drawer);
        $this->assertStringContainsString(':block-scroll="true"', $drawer);
        $this->assertStringContainsString(':close-on-escape="true"', $drawer);
        $this->assertStringContainsString(':dismissable="true"', $drawer);
        $this->assertStringContainsString('autofocus', $drawer);
        $this->assertStringContainsString('@after-hide="cartStore.restoreDrawerFocus()"', $drawer);
        $this->assertStringContainsString('cartStore.openDrawer($event.currentTarget)', $navbar);
        $this->assertStringContainsString('aria-live="polite"', $drawer);
        $this->assertStringContainsString('drawerPassThrough', $drawer);
        $this->assertStringContainsString('safe-area-inset-bottom', $drawer);
    }

    public function test_cart_store_keeps_one_source_of_truth_and_consistent_unit_count_and_totals(): void
    {
        $store = file_get_contents(resource_path('js/stores/cart.js'));
        $cart = file_get_contents(resource_path('js/views/Cart.vue'));
        $checkout = file_get_contents(resource_path('js/views/Checkout.vue'));
        $navbar = file_get_contents(resource_path('js/components/Navbar.vue'));
        $drawer = file_get_contents(resource_path('js/components/cart/CartDrawer.vue'));
        $add = $this->extractBodyAfter($store, 'addItem(product, quantity = 1, trigger = null)');
        $open = $this->extractBodyAfter($store, 'openDrawer(trigger = null)');
        $close = $this->extractBodyAfter($store, 'closeDrawer({ restoreFocus = true } = {})');
        $update = $this->extractBodyAfter($store, 'updateQuantity(productId, quantity)');
        $remove = $this->extractBodyAfter($store, 'removeItem(productId)');

        $this->assertStringContainsString('count: (state) => state.items.reduce((acc, item) => acc + item.quantity, 0)', $store);
        $this->assertStringContainsString('total: (state) => state.items.reduce((acc, item) => acc + (item.price * item.quantity), 0)', $store);
        $this->assertStringContainsString('cartStore.count', $navbar);
        $this->assertStringContainsString('cartStore.count', $cart);
        $this->assertStringContainsString('cartStore.count', $drawer);
        $this->assertStringContainsString('cartStore.total', $cart);
        $this->assertStringContainsString('cartStore.total', $checkout);
        $this->assertStringContainsString('cartStore.total', $drawer);
        $this->assertStringContainsString('normalizeCartQuantity', $store);
        $this->assertStringContainsString('localStorage.setItem(\'cartItems\'', $store);
        $this->assertStringContainsString('addingProducts[product.id]', $add);
        $this->assertStringContainsString('lineOperations[product.id]', $add);
        $this->assertStringContainsString('drawerNotice = { id: ++this.noticeSequence', $add);
        $this->assertStringContainsString('this.drawerOpen = true', $add);
        $this->assertStringContainsString('this.drawerNotice = null', $open);
        $this->assertStringContainsString('this.drawerNotice = null', $close);
        $this->assertStringContainsString('this.lineOperations[productId]', $update);
        $this->assertStringContainsString('this.lineOperations[productId]', $remove);
        $this->assertStringContainsString('this.items = previousItems', $remove);
        $this->assertStringContainsString('item.quantity = previousQuantity', $update);
        $this->assertStringContainsString('items: cartStore.items.map(item => ({ product_id: item.product_id, quantity: item.quantity }))', $checkout);
    }

    public function test_drawer_line_controls_are_per_product_and_navigation_does_not_change_catalog_context(): void
    {
        $drawer = file_get_contents(resource_path('js/components/cart/CartDrawer.vue'));
        $card = file_get_contents(resource_path('js/components/ProductCard.vue'));
        $catalog = file_get_contents(resource_path('js/views/Catalog.vue'));
        $appCss = file_get_contents(resource_path('css/app.css'));
        $store = file_get_contents(resource_path('js/stores/cart.js'));
        $change = $this->extractBodyAfter($drawer, 'function changeQuantity(item, quantity)');
        $remove = $this->extractBodyAfter($drawer, 'function removeItem(item)');
        $manualOpen = $this->extractBodyAfter($store, 'openDrawer(trigger = null)');

        $this->assertStringContainsString('cartQuantityLimit(item)', $drawer);
        $this->assertStringContainsString('item.quantity <= 1', $drawer);
        $this->assertStringContainsString('lineOperations[item.product_id]', $drawer);
        $this->assertStringNotContainsString('localOperations', $drawer);
        $this->assertStringContainsString('cartStore.updateQuantity(item.product_id, quantity)', $change);
        $this->assertStringContainsString('cartStore.removeItem(item.product_id)', $remove);
        $this->assertStringContainsString('cartStore.closeDrawer({ restoreFocus: false })', $drawer);
        $this->assertStringContainsString("router.push('/cart')", $drawer);
        $this->assertStringContainsString("router.push('/catalog')", $drawer);
        $this->assertStringNotContainsString('router.back()', $drawer);
        $this->assertStringNotContainsString('router.', $manualOpen);
        $this->assertStringNotContainsString('@click.stop', $card);
        $this->assertStringContainsString('watch(requestSignature, loadProducts', $catalog);
        $this->assertStringContainsString('sessionStorage.setItem(`catalog:scroll:${route.fullPath}`', $catalog);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $appCss);
        $this->assertStringContainsString('min-h-11', $drawer);
        $this->assertStringContainsString('Tu carrito está vacío', $drawer);
        $this->assertStringContainsString('message: \'Producto agregado correctamente\'', $store);
        $this->assertStringContainsString('{{ cartStore.drawerNotice.message }}', $drawer);
        $this->assertStringContainsString('if (cartStore.drawerNotice?.id === id)', $drawer);
        $this->assertStringNotContainsString('api.', $drawer);
    }

    private function extractBodyAfter(string $source, string $signature): string
    {
        $signaturePosition = strpos($source, $signature);
        $this->assertNotFalse($signaturePosition, "Could not find source signature: {$signature}");

        $openingBrace = strpos($source, '{', $signaturePosition + strlen($signature));
        $this->assertNotFalse($openingBrace, "Could not find body for source signature: {$signature}");

        $depth = 0;
        $length = strlen($source);
        for ($index = $openingBrace; $index < $length; $index++) {
            if ($source[$index] === '{') $depth++;
            if ($source[$index] === '}') $depth--;
            if ($depth === 0) return substr($source, $openingBrace + 1, $index - $openingBrace - 1);
        }

        $this->fail("Unbalanced body for source signature: {$signature}");
    }
}
