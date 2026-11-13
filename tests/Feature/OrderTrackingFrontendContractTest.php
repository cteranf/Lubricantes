<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderTrackingFrontendContractTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = file_get_contents(resource_path('js/views/OrderTracking.vue'));
    }

    public function test_tracking_view_presents_real_timeline_accessibly_and_responsively(): void
    {
        foreach ([
            'aria-label="Etapas del pedido"',
            ':aria-current="step.active ? \'step\' : undefined"',
            'step.completed',
            'step.active',
            'step.date',
            'step.status === \'canceled\'',
            '.tracking-timeline',
            '@media (min-width: 768px)',
            '@media (prefers-reduced-motion: reduce)',
            'role="status"',
            'role="alert"',
            'label="Reintentar"',
        ] as $contract) {
            $this->assertStringContainsString($contract, $this->source);
        }
        $this->assertStringNotContainsString('v-html', $this->source);
    }

    public function test_payment_labels_cover_transfer_card_cash_on_delivery_and_cancellation(): void
    {
        foreach ([
            'Transferencia bancaria',
            'Pago con tarjeta',
            'Pago contra entrega',
            'Pago en sede',
            'Pago confirmado',
            'Pendiente de confirmación',
            'Pago pendiente al recibir o recoger',
            'Pedido cancelado antes del pago',
            'Total del pedido',
            'Total pagado',
            'CustomerTransferPaymentPanel',
        ] as $visibleContract) {
            $this->assertStringContainsString($visibleContract, $this->source);
        }
        $this->assertStringNotContainsString('{{ order.payment_method }}', $this->source);
        $this->assertStringNotContainsString('{{ order.payment_status }}', $this->source);
    }

    public function test_pickup_and_delivery_render_only_available_real_details(): void
    {
        foreach ([
            'Recojo en sede',
            'Entrega a domicilio',
            'order.pickup.ready_at',
            'order.pickup.picked_up_at',
            'v-else-if="order.pickup.deadline_at"',
            'order.pickup.deadline_status',
            'delivery?.scheduled_at',
            'delivery?.courier?.tracking_number',
            'safeTrackingUrl',
            'Hubo un inconveniente con el intento de entrega',
        ] as $contract) {
            $this->assertStringContainsString($contract, $this->source);
        }
        $this->assertStringNotContainsString('delivery?.last_failure?.failure_reason', $this->source);
    }

    public function test_product_rows_use_order_values_reviews_and_safe_image_fallback(): void
    {
        foreach ([
            '{{ item.name }}',
            '{{ item.quantity }}',
            '{{ formatMoney(item.price) }}',
            'item.subtotal ?? (Number(item.quantity) * Number(item.price))',
            'Sin imagen para',
            'Opinión pendiente de revisión',
            'Ver o editar mi opinión',
            'Revisar mi opinión',
            'Editar mi opinión',
            'Opinar sobre este producto',
            'item.review_access',
        ] as $contract) {
            $this->assertStringContainsString($contract, $this->source);
        }
        foreach (['review_id', 'order_item_id', 'verified_order_item_id'] as $privateId) {
            $this->assertStringNotContainsString($privateId, $this->source);
        }
    }

    public function test_route_changes_abort_stale_gets_and_keep_the_screen_read_only(): void
    {
        $fetch = $this->functionBody('async function fetchTracking()');
        $this->assertStringContainsString('api.get(', $fetch);
        $this->assertStringContainsString('{ signal: controller.signal }', $fetch);
        $this->assertStringContainsString('requestController?.abort()', $fetch);
        $this->assertStringContainsString('sequence !== requestSequence', $fetch);
        $this->assertStringContainsString('String(route.params.id) !== String(id)', $fetch);
        $this->assertSame(1, substr_count($fetch, 'api.get('));
        foreach (['api.post(', 'api.put(', 'api.patch(', 'api.delete('] as $mutation) {
            $this->assertStringNotContainsString($mutation, $fetch);
        }
        $this->assertStringContainsString('watch(() => route.params.id, fetchTracking, { immediate: true })', $this->source);
        $this->assertStringContainsString('onBeforeUnmount(() => {', $this->source);
        $this->assertStringContainsString('requestController?.abort()', $this->section('onBeforeUnmount(() => {'));
    }

    private function functionBody(string $declaration): string
    {
        $start = strpos($this->source, $declaration);
        $this->assertNotFalse($start, "Missing function declaration: {$declaration}");
        $open = strpos($this->source, '{', $start);
        $this->assertNotFalse($open);
        $depth = 0;
        $quote = null;
        $escaped = false;
        for ($index = $open, $length = strlen($this->source); $index < $length; $index++) {
            $char = $this->source[$index];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if (in_array($char, ["'", '"', '`'], true)) {
                $quote = $char;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                return substr($this->source, $open, $index - $open + 1);
            }
        }

        $this->fail("Unclosed function body: {$declaration}");
    }

    private function section(string $startNeedle): string
    {
        $start = strpos($this->source, $startNeedle);
        $this->assertNotFalse($start);

        return substr($this->source, $start, 260);
    }
}
