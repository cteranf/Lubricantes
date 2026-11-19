<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReceiptBillingFrontendTest extends TestCase
{
    public function test_checkout_captures_the_server_configured_receipt_request_and_preserves_user_input_on_conflicts(): void
    {
        $checkout = file_get_contents(resource_path('js/views/Checkout.vue'));
        $login = file_get_contents(resource_path('js/views/Login.vue'));
        $router = file_get_contents(resource_path('js/router.js'));
        $inbox = file_get_contents(resource_path('js/views/admin/BillingInbox.vue'));

        $this->assertStringContainsString('Comprobante de venta', $checkout);
        $this->assertStringContainsString("api.get('/checkout/receipt-options')", $checkout);
        $this->assertStringContainsString('receiptOptions.enabled_types.length === 1', $checkout);
        $this->assertStringContainsString('boletaIdentityRequired', $checkout);
        $this->assertStringContainsString('receipt_request: { ...form.value.receipt_request', $checkout);
        $this->assertStringContainsString('receipt_settings_version: receiptOptions.value.version', $checkout);
        $this->assertStringContainsString("code === 'receipt_settings_changed'", $checkout);
        $this->assertStringContainsString('receiptOptionsReady.value', $checkout);
        $this->assertStringContainsString('Copiar datos de entrega', $checkout);
        $this->assertStringContainsString("authStore.isBilling ? '/admin/billing'", $login);
        $this->assertStringContainsString('requiresBilling: true', $router);
        $this->assertStringContainsString('/billing/orders', $inbox);
        $this->assertStringContainsString('current_page', $inbox);
        $this->assertStringContainsString('requested_not_issued', $inbox);
        $this->assertStringNotContainsString('emitir comprobante', mb_strtolower($inbox));
    }

    public function test_billing_role_navigation_does_not_grant_treasury_or_business_configuration_routes(): void
    {
        $layout = file_get_contents(resource_path('js/layouts/AdminLayout.vue'));
        $router = file_get_contents(resource_path('js/router.js'));
        $middleware = file_get_contents(app_path('Http/Middleware/CanAccessBilling.php'));
        $start = strpos($layout, 'const billingNavigation = [');
        $end = strpos($layout, 'const profileNavigation', $start);
        $billingNavigation = substr($layout, $start, $end - $start);

        $this->assertStringContainsString('billingNavigation', $layout);
        $this->assertStringContainsString("{ label: 'Facturación', to: '/admin/billing'", $layout);
        $this->assertStringContainsString('!authStore.isAdmin && !authStore.isBilling', $router);
        $this->assertStringContainsString('$user->isAdmin() || $user->isBilling()', $middleware);
        $this->assertStringContainsString('$user->is_active', $middleware);
        $this->assertStringNotContainsString("'/admin/business-profile'", $billingNavigation);
        $this->assertStringNotContainsString("'/treasury/payments'", $billingNavigation);
    }
}
