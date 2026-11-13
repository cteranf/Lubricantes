<?php

namespace Tests\Feature;

use Tests\TestCase;

class CustomerOrdersFrontendTest extends TestCase
{
    public function test_customer_history_keeps_the_authenticated_endpoint_and_full_array_contract(): void
    {
        $view = file_get_contents(resource_path('js/views/Orders.vue'));
        $router = file_get_contents(resource_path('js/router.js'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/V1/OrderController.php'));

        $this->assertStringContainsString('Mis pedidos', $view);
        $this->assertStringContainsString('Consulta tus compras y revisa su seguimiento.', $view);
        $this->assertStringContainsString("api.get('/orders', { signal: controller.signal })", $view);
        $this->assertStringContainsString('Array.isArray(response.data) ? response.data : []', $view);
        $this->assertStringContainsString("{ path: '/orders', name: 'Orders', component: Orders, meta: { requiresAuth: true, noindex: true } }", $router);
        $this->assertStringContainsString("Route::apiResource('orders', OrderController::class)->only(['index', 'store', 'show'])", file_get_contents(base_path('routes/api.php')));
        $this->assertStringContainsString('$request->user()->orders()', $controller);
        $this->assertStringNotContainsString('meta.total', $view);
        $this->assertStringNotContainsString('last_page', $view);
        $this->assertStringNotContainsString('page.value', $view);
    }

    public function test_filters_and_search_use_the_complete_loaded_history_and_real_operational_states(): void
    {
        $view = file_get_contents(resource_path('js/views/Orders.vue'));
        $presentation = file_get_contents(resource_path('js/utils/customerOrderPresentation.js'));
        $filtering = $this->sourceBetween($view, 'const visibleOrders = computed(() => {', 'function filterCount(filterId)');
        $stateMapping = $this->sourceBetween($presentation, 'export function customerOrderState(order)', 'export function customerOrderNumber');

        foreach ([
            "{ id: 'all', label: 'Todos' }",
            "{ id: 'pending', label: 'Pendientes' }",
            "{ id: 'preparing', label: 'En preparación' }",
            "{ id: 'ready', label: 'Listos para entrega o recojo' }",
            "{ id: 'completed', label: 'Completados' }",
            "{ id: 'canceled', label: 'Cancelados' }",
        ] as $filter) {
            $this->assertStringContainsString($filter, $view);
        }

        foreach (["'pending'", "'confirmed'", "'processing'", "'shipped'", "'ready_for_pickup'", "'delivered'", "'picked_up'", "'canceled'", "'rejected'"] as $state) {
            $this->assertStringContainsString($state, $stateMapping);
        }
        $this->assertStringContainsString('orders.value.filter', $filtering);
        $this->assertStringContainsString('stateFor(order).group', $filtering);
        $this->assertStringContainsString('orderNumber(order.id)', $filtering);
        $this->assertStringNotContainsString('response.data.meta', $view);
        $this->assertStringNotContainsString('api.get', $filtering);
    }

    public function test_order_cards_use_historical_amounts_public_images_compact_items_and_tracking_route(): void
    {
        $view = file_get_contents(resource_path('js/views/Orders.vue'));
        $card = $this->sourceBetween($view, '<article v-for="order in visibleOrders"', '</article>');

        foreach ([
            ':key="order.id"',
            '#{{ orderNumber(order.id) }}',
            'stateFor(order).label',
            'stateFor(order).classes',
            ':to="`/orders/${order.id}/tracking`"',
            'Ver detalle y seguimiento',
            'formatPen(order.total)',
            'item.product?.name',
            'item.subtotal ?? historicalSubtotal(item)',
            'itemImage(item)',
            'item.quantity',
            'Ver ${order.items.length - visibleItemLimit} productos más',
            'aria-expanded="isExpanded(order)"',
            'object-contain',
            'loading="lazy"',
        ] as $expected) {
            $this->assertStringContainsString($expected, $card, "Missing customer order card behavior: {$expected}");
        }

        $this->assertStringNotContainsString('item.product.price', $card);
        $this->assertStringContainsString('const visibleItemLimit = 3;', $view);
        $this->assertStringContainsString('normalizeImageUrl(item?.product?.image_url)', $view);
        $this->assertStringNotContainsString('shipping_info', $card);
        $this->assertStringNotContainsString('warehouse', $card);
        $this->assertStringNotContainsString('reservation', $card);
        $this->assertStringNotContainsString('v-html', $view);
    }

    public function test_loading_error_empty_and_filtered_empty_states_are_distinct_and_accessible(): void
    {
        $view = file_get_contents(resource_path('js/views/Orders.vue'));

        foreach ([
            'role="status" aria-label="Cargando pedidos"',
            'role="alert"',
            'No pudimos cargar tus pedidos',
            '@click="fetchOrders"',
            'Aún no tienes pedidos',
            'Explorar productos',
            'No encontramos pedidos',
            '@click="clearFilters"',
            'aria-live="polite"',
            ':aria-busy="loading"',
            'focus-visible:outline',
            'min-h-12',
            'sm:flex-row',
            'lg:grid-cols-',
        ] as $expected) {
            $this->assertStringContainsString($expected, $view);
        }
        $this->assertStringContainsString("error.value = 'Inténtalo nuevamente en unos momentos.'", $view);
        $this->assertStringNotContainsString('requestError.response', $view);
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
