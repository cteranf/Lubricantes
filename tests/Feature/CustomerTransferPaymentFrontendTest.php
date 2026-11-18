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
        foreach (['v-html', 'base64', 'localStorage', 'sessionStorage', 'console.log', 'currency:', 'order_id:', 'user_id:', 'status:', 'snapshot:', 'fingerprint:', 'metadata:', 'idempotency_key:', 'reserved_until:'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $panel);
        }
        $this->assertStringContainsString('v-model="form.amount"', $panel);
        $this->assertStringContainsString('type="number"', $panel);
        $this->assertStringContainsString('amount: String(form.value.amount)', $panel);
        $this->assertStringContainsString("props.order?.payment_method === 'transferencia'", $panel);
        $this->assertStringContainsString("v-if=\"order.payment_method === 'transferencia' && (order.reservation_status !== 'expired'", $tracking);
        $this->assertStringContainsString("error.response?.status === 404 && error.response?.data?.code === 'payment_submission_not_found'", $panel);
        $this->assertStringContainsString('newSubmissionFlow.value = true', $panel);
        $this->assertStringContainsString('await loadOptions(orderId, requestId)', $panel);
        $this->assertStringNotContainsString('if (!canCreateSubmission.value', $panel);
        $this->assertStringContainsString('recoveryError.value =', $panel);
        $this->assertStringContainsString('@click="recoverSubmission"', $panel);
        $this->assertStringContainsString('submissions.value = Array.isArray(payload.submissions) ? payload.submissions : [payload]', $panel);
        $this->assertStringContainsString('submission.value = submissions.value[0] || null', $panel);
        $this->assertStringContainsString('newSubmissionFlow.value = false', $panel);
        $this->assertStringContainsString('revokeQr()', $panel);
        $this->assertStringContainsString("submission.status === 'observed' && submission.reason", $panel);
        $this->assertStringContainsString('Motivo: {{ submission.reason }}', $panel);
        $this->assertStringContainsString('submissionTone', $panel);
        $this->assertStringContainsString("'border-amber-200 bg-amber-50", $panel);
        $this->assertStringContainsString("'border-emerald-200 bg-emerald-50", $panel);
        $this->assertStringContainsString('submission.reservation_expires_at', $panel);
        $this->assertStringContainsString("submission.value?.status === 'observed'", $panel);
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
        $this->assertStringNotContainsString('Tu pedido continuará con preparación y entrega.', $panel);
        $this->assertStringContainsString('showExpectedAmount: { type: Boolean, default: true }', $panel);
        $this->assertStringContainsString('v-if="props.showExpectedAmount"', $panel);
        $this->assertStringContainsString('{{ submission.expected_amount }}', $panel);
        $this->assertStringContainsString(':show-expected-amount="false"', $tracking);
        $this->assertStringNotContainsString("submission.value.status === 'approved'", $panel);
        $this->assertStringContainsString('const payload = response.data?.data', $panel);
        $this->assertStringContainsString('options.value = Array.isArray(payload) ? payload : []', $panel);
        $this->assertStringContainsString('|| options.value[0]', $panel);
        $this->assertStringContainsString('options.length > 1', $panel);
        $this->assertStringContainsString('v-if="selected" id="payment-option-panel"', $panel);
        $this->assertSame(2, substr_count($panel, 'v-if="selected.instructions"'));
        $this->assertSame(2, substr_count($panel, '{{ selected.instructions }}'));
        $this->assertSame(2, substr_count($panel, '@click="copy(selected.account_number)"'));
        $this->assertSame(2, substr_count($panel, '@click="copy(selected.cci)"'));
        $this->assertStringContainsString('if (item.channel !== \'bank_transfer\') await loadQr(item)', $panel);
        $this->assertStringContainsString('img v-else-if="qrUrl"', $panel);
    }

    public function test_submission_and_observed_correction_forms_explain_channel_specific_required_fields(): void
    {
        $panel = file_get_contents(resource_path('js/components/CustomerTransferPaymentPanel.vue'));

        $this->assertSame(2, substr_count($panel, '>Datos del abono</h3>'));
        $this->assertSame(2, substr_count($panel, 'grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2'));
        $this->assertSame(1, substr_count($panel, 'Últimos 4 dígitos del celular desde el que pagaste'));
        $this->assertSame(1, substr_count($panel, 'Indica los últimos cuatro dígitos del celular del pagador. Es obligatorio.'));
        $this->assertSame(1, substr_count($panel, 'Banco desde el que transferiste'));
        $this->assertSame(1, substr_count($panel, 'Escribe el nombre del banco de la cuenta desde la que transferiste. Es obligatorio.'));
        $this->assertStringNotContainsString('Últimos cuatro dígitos</label>', $panel);
        $this->assertStringContainsString("const fieldError = (bag, field) => bag?.[field]?.[0] || ''", $panel);
        $this->assertStringContainsString("key: 'origin_phone_last_four'", $panel);
        $this->assertStringContainsString("key: 'origin_bank'", $panel);
        $this->assertStringContainsString("fieldError(errors, originField.key)", $panel);
        $this->assertStringContainsString("fieldError(correctionErrors, originField.key)", $panel);
        $this->assertStringContainsString('required :aria-invalid=', $panel);
        $this->assertSame(2, substr_count($panel, 'sm:col-span-2 sm:flex sm:justify-end'));
        $this->assertSame(2, substr_count($panel, 'v-if="selected.holder_name && selected.holder_name !== selected.display_name"'));
        $this->assertSame(2, substr_count($panel, 'class="mx-auto mt-3 max-h-64 max-w-full object-contain"'));

        foreach (['correction-amount', 'correction-operation-number', 'correction-paid-at', 'new-amount', 'new-operation-number', 'new-paid-at'] as $fieldId) {
            $this->assertStringContainsString('id="'.$fieldId.'"', $panel);
        }

        foreach ([
            ['correction-amount', 'correction-operation-number', 'correction-paid-at', 'correction-origin-phone'],
            ['new-amount', 'new-operation-number', 'new-paid-at', 'new-origin-phone'],
        ] as $fieldOrder) {
            $positions = array_map(fn (string $fieldId) => strpos($panel, 'id="'.$fieldId.'"'), $fieldOrder);
            $this->assertNotContains(false, $positions);
            $sortedPositions = $positions;
            sort($sortedPositions);
            $this->assertSame($sortedPositions, $positions);
        }

        $this->assertStringContainsString('role="alert">{{ fieldError(correctionErrors, originField.key) }}</span>', $panel);
        $this->assertStringContainsString('role="alert">{{ fieldError(errors, originField.key) }}</span>', $panel);
    }

    public function test_installment_summary_shows_registered_and_available_operations_and_blocks_full_in_review(): void
    {
        $panel = file_get_contents(resource_path('js/components/CustomerTransferPaymentPanel.vue'));

        $this->assertSame(2, substr_count($panel, 'Operaciones registradas {{ accounting.operations_used }} de {{ accounting.max_operations }}'));
        $this->assertSame(2, substr_count($panel, 'v-if="Number(accounting.remaining_amount) > 0"'));
        $this->assertSame(2, substr_count($panel, 'Operaciones disponibles {{ accounting.slots_left }}'));
        $this->assertSame(2, substr_count($panel, 'Importe completo en revisión. No registres otro abono mientras Tesorería termina de validar.'));
        $this->assertStringNotContainsString('· Cupos {{ accounting.slots_left }}', $panel);
        $this->assertStringContainsString('accounting.slots_left <= 0 || Number(accounting.remaining_amount) <= 0', $panel);
        $this->assertStringContainsString('operations_used: 0', $panel);
    }
}
