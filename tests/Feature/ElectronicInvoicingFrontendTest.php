<?php

namespace Tests\Feature;

use Tests\TestCase;

class ElectronicInvoicingFrontendTest extends TestCase
{
    public function test_settings_and_billing_emission_flows_are_role_scoped_and_require_server_readiness(): void
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
        $this->assertStringContainsString('new FormData()', $settings);
        $this->assertStringContainsString("payload.append('_method', 'PUT')", $settings);
        $this->assertStringContainsString("api.post('/admin/electronic-invoicing/settings', payload)", $settings);
        $this->assertStringContainsString('connection_available', $settings);
        $this->assertStringContainsString('no implica conexión real', mb_strtolower($settings));
        $this->assertStringContainsString('Solicitud del cliente', $inbox);
        $this->assertStringContainsString('Documento electrónico', $inbox);
        $this->assertStringContainsString('document.status_label', $inbox);
        $this->assertStringContainsString('confirmPrepareDraft', $inbox);
        $this->assertStringContainsString('const confirm = useConfirm();', $inbox);
        $this->assertStringContainsString('`/billing/orders/${orderId}/electronic-documents`', $inbox);
        $this->assertStringContainsString('preparationStorageKey', $inbox);
        $this->assertStringContainsString('loadCurrentDraft', $inbox);
        $this->assertStringContainsString('Confirmar emisión de prueba', $inbox);
        $this->assertStringContainsString('!emissionReadiness?.ready', $inbox);
        $this->assertStringContainsString('/readiness`', $inbox);
        $this->assertStringContainsString('emissionReadiness.value = document?.readiness ?? null', $inbox);
        $this->assertStringContainsString('function onDraftFieldInput(path) { clearFieldError(path); emissionReadiness.value = null; }', $inbox);
        $this->assertStringContainsString('return { document, readiness: document.readiness ?? null };', $inbox);
        $this->assertStringContainsString('setDraft(response.data.data);', $inbox);
        $this->assertStringContainsString('setDraft((await api.put(`/billing/electronic-documents/${id}`', $inbox);
        $emissionService = file_get_contents(app_path('Services/ElectronicDocumentEmissionService.php'));
        $this->assertStringContainsString('ElectronicDocumentOutbox::query()->create', $emissionService);
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

    public function test_billing_interface_shows_actual_results_and_does_not_offer_unsupported_beta_queries(): void
    {
        $inbox = file_get_contents(resource_path('js/views/admin/BillingInbox.vue'));
        $resource = file_get_contents(app_path('Http/Resources/ElectronicDocumentResource.php'));
        $support = file_get_contents(app_path('Support/ElectronicDocumentStatus.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/V1/BillingElectronicDocumentController.php'));

        $this->assertStringContainsString('draft.result_code', $inbox);
        $this->assertStringContainsString('draft.result_message', $inbox);
        $this->assertStringContainsString('draft.series && draft.document_number', $inbox);
        $this->assertStringContainsString('HTTP 200 por sí solo no significa aceptación', $inbox);
        $this->assertStringContainsString('Beta no ofrece una consulta de estado', $inbox);
        $this->assertStringNotContainsString('Consultar resultado SUNAT', $inbox);
        $this->assertStringNotContainsString('/query`', $inbox);
        $this->assertStringContainsString('loadCurrentDraft', $inbox);
        $this->assertStringContainsString('conservar mis cambios', mb_strtolower($inbox));
        $this->assertStringContainsString('draftErrors.value = exception.response?.data?.errors || {}', $inbox);
        $this->assertStringContainsString('daily_summary_workflow_required', $inbox);
        $this->assertStringContainsString("'uncertain' => 'Resultado incierto; requiere revisión manual'", $support);
        $this->assertStringContainsString('ElectronicDocumentStatus::label($this->status)', $resource);
        $this->assertStringContainsString('SUNAT Beta no ofrece consulta de estado', $controller);
        $this->assertStringContainsString('abort_unless($this->documents->enabled()', $controller);
    }
}
