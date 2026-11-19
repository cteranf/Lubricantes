<?php

namespace Tests\Feature;

use Tests\TestCase;

class ElectronicInvoicingFrontendTest extends TestCase
{
    public function test_settings_and_billing_draft_flows_are_role_scoped_and_never_offer_issuance(): void
    {
        $router = file_get_contents(resource_path('js/router.js'));
        $layout = file_get_contents(resource_path('js/layouts/AdminLayout.vue'));
        $settings = file_get_contents(resource_path('js/views/admin/ElectronicInvoicingSettings.vue'));
        $inbox = file_get_contents(resource_path('js/views/admin/BillingInbox.vue'));
        $businessProfile = file_get_contents(resource_path('js/views/admin/BusinessProfile.vue'));
        $updateRequest = file_get_contents(app_path('Http/Requests/Admin/UpdateBusinessProfileRequest.php'));
        $checkout = file_get_contents(resource_path('js/views/Checkout.vue'));
        $products = file_get_contents(resource_path('js/views/admin/Products.vue'));

        $this->assertStringContainsString("path: '/admin/electronic-invoicing'", $router);
        $this->assertMatchesRegularExpression("/path: '\/admin\/electronic-invoicing'.*meta: \\{ requiresAdmin: true \\}/", $router);
        $this->assertStringContainsString("label: 'Facturación electrónica', to: '/admin/electronic-invoicing'", $layout);
        $billingNavigationStart = strpos($layout, 'const billingNavigation = [');
        $billingNavigationEnd = strpos($layout, 'const profileNavigation', $billingNavigationStart);
        $billingNavigation = substr($layout, $billingNavigationStart, $billingNavigationEnd - $billingNavigationStart);
        $this->assertStringNotContainsString('/admin/electronic-invoicing', $billingNavigation);
        $this->assertStringContainsString("api.get('/admin/electronic-invoicing/settings')", $settings);
        $this->assertStringContainsString("api.put('/admin/electronic-invoicing/settings'", $settings);
        $this->assertStringContainsString('connection_available', $settings);
        $this->assertStringContainsString('no se simulará aceptación', mb_strtolower($settings));
        $this->assertStringContainsString('Borrador · no emitido', $inbox);
        $this->assertStringContainsString('confirmPrepareDraft', $inbox);
        $this->assertStringContainsString('const confirm = useConfirm();', $inbox);
        $this->assertStringContainsString('`/billing/orders/${orderId}/electronic-documents`', $inbox);
        $this->assertStringContainsString('preparationStorageKey', $inbox);
        $this->assertStringContainsString('Carga la versión vigente', $inbox);
        $this->assertStringNotContainsString('emitir comprobante', mb_strtolower($inbox));
        $this->assertStringContainsString('electronicInvoicing.enabled', $businessProfile);
        $this->assertStringNotContainsString('electronic_invoicing_enabled', $updateRequest);
        $this->assertStringContainsString("api.get('/admin/electronic-invoicing/tax-configuration')", $settings);
        $this->assertStringContainsString("api.put('/admin/electronic-invoicing/tax-configuration'", $settings);
        $this->assertStringContainsString('businessTimezone', $settings);
        $this->assertStringContainsString('taxCalculationLabel(draft.tax_calculation_status)', $inbox);
        $this->assertStringContainsString('shipping_mixed_affectations', $inbox);
        $this->assertStringContainsString('tax_affectation', $products);
        $this->assertStringContainsString('Pendiente de clasificación', $products);
        $this->assertStringContainsString('tax_quote_token', $checkout);
        $this->assertStringContainsString("code === 'tax_calculation_changed'", $checkout);
        $this->assertStringContainsString("taxPreview?.status === 'complete'", $checkout);
        $this->assertStringContainsString("taxPreview?.status === 'review_required'", $checkout);
        $this->assertStringContainsString('Se conserva el total comercial mostrado', $checkout);
    }
}
