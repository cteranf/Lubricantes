<?php

namespace Tests\Feature;

use Tests\TestCase;

class CommercialOrderPdfFrontendTest extends TestCase
{
    public function test_customer_and_staff_order_details_use_private_commercial_document_actions(): void
    {
        $component = file_get_contents(resource_path('js/components/CommercialOrderDocuments.vue'));
        $tracking = file_get_contents(resource_path('js/views/OrderTracking.vue'));
        $orders = file_get_contents(resource_path('js/views/admin/Orders.vue'));
        $billing = file_get_contents(resource_path('js/views/admin/BillingInbox.vue'));
        $treasury = file_get_contents(resource_path('js/views/treasury/TreasuryPayments.vue'));
        $detailResource = file_get_contents(app_path('Http/Resources/TreasuryPaymentSubmissionDetailResource.php'));
        $summaryPdf = file_get_contents(resource_path('views/commercial-documents/order-summary.blade.php'));
        $paymentPdf = file_get_contents(resource_path('views/commercial-documents/payment-constancy.blade.php'));

        $this->assertStringContainsString('Descargar resumen del pedido', $component);
        $this->assertStringContainsString('Descargar constancia de pago', $component);
        $this->assertStringContainsString('payment_constancy_unavailable_reason', $component);
        $this->assertStringContainsString('flex flex-col gap-2 sm:flex-row sm:flex-wrap', $component);
        $this->assertStringContainsString('role="alert"', $component);
        $this->assertStringContainsString(':disabled="busy"', $component);
        $this->assertStringContainsString('window.setTimeout', $component);
        $this->assertStringContainsString("responseType: 'blob'", $component);
        $this->assertStringContainsString('response.data', $component);
        $this->assertStringContainsString('No son comprobantes de pago tributarios', $component);
        $this->assertStringNotContainsString('v-html', $component);
        $this->assertStringContainsString('<CommercialOrderDocuments :order-id="order.id" scope="customer" />', $tracking);
        $this->assertStringContainsString('scope="admin"', $orders);
        $this->assertStringContainsString('scope="billing"', $billing);
        $this->assertStringContainsString('scope="treasury"', $treasury);
        $this->assertStringContainsString("'order_id' => (int) \$this->order_id", $detailResource);
        foreach ([$summaryPdf, $paymentPdf] as $template) {
            $this->assertStringNotContainsString('F001', $template);
            $this->assertStringNotContainsString('B001', $template);
            $this->assertStringNotContainsString('CDR', $template);
            $this->assertStringNotContainsString('SUNAT', $template);
        }
    }
}
