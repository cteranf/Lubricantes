<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderSuccessFrontendContractTest extends TestCase
{
    public function test_success_page_uses_saved_order_price_snapshots_and_hides_zero_savings(): void
    {
        $source = file_get_contents(resource_path('js/views/OrderSuccess.vue'));
        $pricing = file_get_contents(resource_path('js/utils/cartPricing.mjs'));
        $snapshotSummaryStart = strpos($pricing, 'export function summarizeOrderPriceSnapshots');
        $snapshotSummaryEnd = strpos($pricing, 'export function applyCartPriceQuotes', $snapshotSummaryStart);
        $this->assertNotFalse($snapshotSummaryStart);
        $this->assertNotFalse($snapshotSummaryEnd);
        $snapshotSummary = substr($pricing, $snapshotSummaryStart, $snapshotSummaryEnd - $snapshotSummaryStart);

        $this->assertStringContainsString('api.get(`/orders/${route.params.id}`)', $source);
        $this->assertStringContainsString("import { summarizeOrderPriceSnapshots } from '@/utils/cartPricing.mjs'", $source);
        $this->assertStringContainsString('summarizeOrderPriceSnapshots(order.value?.items || [])', $source);
        $this->assertStringContainsString('Subtotal sin ofertas', $source);
        $this->assertStringContainsString('v-if="orderPriceSummary.hasSavings"', $source);
        $this->assertStringContainsString('Ahorro en ofertas', $source);
        $this->assertStringContainsString('order.total', $source);
        $this->assertStringContainsString('v-if="Number(order.shipping_amount) > 0"', $source);
        $this->assertSame(1, substr_count($source, 'Costo de envío'));
        $this->assertStringContainsString("order.value?.payment_status === 'pending' ? 'pendiente'", $source);
        $this->assertStringContainsString("order.value?.payment_status === 'approved'", $source);
        $this->assertStringContainsString('v-if="Number(order.discount_total) > 0"', $source);
        $this->assertStringContainsString('regular_price: item?.regular_price ?? item?.price', $snapshotSummary);
        $this->assertStringNotContainsString('product?.price', $snapshotSummary);
    }
}
