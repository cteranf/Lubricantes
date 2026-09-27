<?php

namespace Tests\Feature;

use Tests\TestCase;

class CustomerTransferPaymentFrontendTest extends TestCase
{
    public function test_transfer_payment_panel_static_contract_is_present(): void
    {
        $panel = file_get_contents(resource_path('js/components/CustomerTransferPaymentPanel.vue'));
        $tracking = file_get_contents(resource_path('js/views/OrderTracking.vue'));
        $resource = file_get_contents(app_path('Http/Resources/CustomerPaymentSubmissionResource.php'));
        foreach (['CustomerTransferPaymentPanel', '/payment-submission', '/payment-options', "responseType: 'blob'", 'URL.createObjectURL', 'URL.revokeObjectURL', "'Idempotency-Key'", 'operation_number', 'type="text"', 'origin_phone_last_four', 'origin_bank', 'new Date(form.value.paid_at).toISOString()', 'sending.value', 'error.response?.status === 404', 'recoverSubmission', 'recoveringSubmission', 'recoveryError', 'loadingOptions', 'optionsError', 'recoveryRequestId', 'AbortController', 'watch(() => props.order?.id', 'Pendiente de validación por Tesorería', 'navigator.clipboard', 'onBeforeUnmount'] as $needle) {
            $this->assertStringContainsString($needle, $panel.$tracking);
        }
        foreach (['type="number"', 'v-html', 'base64', 'localStorage', 'sessionStorage', 'console.log', 'amount:', 'currency:', 'order_id:', 'user_id:', 'status:', 'snapshot:', 'fingerprint:', 'metadata:', 'idempotency_key:', 'reserved_until:'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $panel);
        }
        $this->assertStringContainsString("props.order?.payment_method === 'transferencia'", $panel);
        $this->assertStringContainsString("v-if=\"order.payment_method === 'transferencia'\"", $tracking);
        $this->assertStringContainsString("error.response?.status === 404 && error.response?.data?.code === 'payment_submission_not_found'", $panel);
        $this->assertStringContainsString('newSubmissionFlow.value = true', $panel);
        $this->assertStringContainsString('await loadOptions(orderId, requestId)', $panel);
        $this->assertStringNotContainsString('if (!canCreateSubmission.value', $panel);
        $this->assertStringContainsString('recoveryError.value =', $panel);
        $this->assertStringContainsString('@click="recoverSubmission"', $panel);
        $this->assertStringContainsString('submission.value = response.data.data', $panel);
        $this->assertStringContainsString('newSubmissionFlow.value = false', $panel);
        $this->assertStringContainsString('revokeQr()', $panel);
        $this->assertStringContainsString("submission.status === 'observed' && submission.reason", $panel);
        $this->assertStringContainsString('Motivo: {{ submission.reason }}', $panel);
        $this->assertStringContainsString('submissionTone', $panel);
        $this->assertStringContainsString("'border-amber-200 bg-amber-50", $panel);
        $this->assertStringContainsString("'border-emerald-200 bg-emerald-50", $panel);
        $this->assertStringContainsString('submission.reservation_expires_at', $panel);
        $this->assertStringContainsString("submission.value.status === 'observed'", $panel);
        $this->assertStringContainsString('correctionFlow', $panel);
        $this->assertStringContainsString('startCorrection', $panel);
        $this->assertStringContainsString('submitCorrection', $panel);
        $this->assertStringContainsString('/payment-submission/correction', $panel);
        $this->assertStringContainsString('api.put(', $panel);
        $this->assertStringContainsString('correctionKey.value ||= newKey()', $panel);
        $this->assertStringContainsString("headers: { 'Idempotency-Key': correctionKey.value }", $panel);
        $this->assertStringContainsString('submissionPayload()', $panel);
        $this->assertStringContainsString('correctionErrors', $panel);
        $this->assertStringContainsString('correctionConflict', $panel);
        $this->assertStringContainsString('if (correctionConflict.value)', $panel);
        $this->assertStringContainsString("correctionKey.value = ''", $panel);
        $this->assertStringContainsString('error.response?.status === 409', $panel);
        $this->assertStringContainsString("submission.value?.status === 'pending_review'", $panel);
        $this->assertStringContainsString('item.channel === submission.value?.channel', $panel);
        $this->assertStringContainsString('correctionSending.value', $panel);
        $this->assertStringContainsString('manualReviewRequired', $panel);
        $this->assertStringContainsString("optionsErrorCode.value === 'reservation_expired'", $panel);
        $this->assertStringContainsString('Tu reserva venció, pero tu pago seguirá siendo revisado', $panel);
        $this->assertStringContainsString('Revisión manual requerida', $panel);
        $this->assertStringContainsString('no vuelvas a pagar ni crees otro pedido', $panel);
        $this->assertStringContainsString('VITE_WHATSAPP_PHONE', $panel);
        $this->assertStringContainsString('supportUrl', $panel);
        $this->assertStringContainsString("submission.status === 'approved'", $panel);
        $this->assertStringContainsString('Pago validado por Tesorería.', $resource);
        $this->assertStringContainsString('submission.validated_at', $panel);
        $this->assertStringContainsString('Tu pedido continuará con preparación y entrega.', $panel);
        $this->assertStringNotContainsString("submission.value.status === 'approved'", $panel);
    }
}
