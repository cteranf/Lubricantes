<?php

namespace Tests\Feature;

use Tests\TestCase;

class TreasuryPaymentResolutionOpeningFrontendTest extends TestCase
{
    public function test_opening_action_uses_only_the_backend_allowlisted_availability(): void
    {
        $view = $this->viewSource();
        $eligibility = $this->between($view, 'const canOpenResolution = computed', 'const canReassignAndApprove = computed');

        $this->assertStringContainsString("detail.value?.status === 'pending_review'", $eligibility);
        $this->assertStringContainsString('detail.value?.can_open_resolution === true', $eligibility);
        $this->assertStringContainsString('!resolutionOpeningBlocked.value', $eligibility);
        $this->assertStringContainsString('v-if="canOpenResolution"', $view);
        $this->assertStringContainsString('Abrir resolución manual', $view);
        foreach (['expires_at', 'reserved_until', 'decision_reason', 'order.status', 'reservation_status'] as $forbiddenInference) {
            $this->assertStringNotContainsString($forbiddenInference, $eligibility);
        }

        $this->assertStringContainsString("detail.value?.reservation_status === 'active'", $this->between($view, 'const canApprove = computed', 'const canObserve = computed'));
        $this->assertStringContainsString("['pending_review', 'observed'].includes(detail.value?.status)", $this->between($view, 'const canReject = computed', 'const canOpenResolution = computed'));
    }

    public function test_accessible_confirmation_contains_only_safe_business_context(): void
    {
        $modal = $this->resolutionModal($this->viewSource());

        foreach ([
            'role="dialog"',
            'aria-modal="true"',
            'aria-labelledby="resolution-opening-title"',
            'Pedido',
            'Cliente',
            'Canal',
            'Importe',
            'Estado de reserva',
            'Se registrará el pago como recibido y se abrirá un caso para resolver la falta de reserva. El pedido aún no será aprobado.',
            'Cancelar',
            'Abrir resolución',
            'Abriendo resolución…',
        ] as $expected) {
            $this->assertStringContainsString($expected, $modal);
        }

        foreach (['operation_number', 'fingerprint', 'idempotency', 'approved_scope', 'metadata', 'snapshot', 'reservation_sequence', 'transaction_id', 'case_id', 'receiving_account'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, strtolower($modal));
        }
    }

    public function test_cancel_never_requests_and_submission_posts_an_exact_empty_body_once(): void
    {
        $view = $this->viewSource();
        $cancel = $this->between($view, 'function closeResolutionConfirmation()', 'async function reloadAfterResolutionOpening()');
        $submit = $this->between($view, 'async function openManualResolution()', 'function openReassignmentConfirmation()');

        $this->assertStringNotContainsString('api.', $cancel);
        $this->assertStringContainsString('api.post(`/treasury/payment-submissions/${detail.value.id}/resolution`, {})', $submit);
        $this->assertStringContainsString('if (resolutionOpeningLoading.value || !canOpenResolution.value || !detail.value) return', $submit);
        $this->assertStringContainsString('resolutionOpeningLoading.value = true', $submit);
        $this->assertStringContainsString(':disabled="resolutionOpeningLoading"', $this->resolutionModal($view));
        $this->assertStringNotContainsString('Idempotency-Key', $view);
        foreach (['amount:', 'currency:', 'operation_number', 'account', 'reservation_id', 'warehouse_id', 'transaction_id', 'case_id', 'actor_id'] as $forbiddenPayload) {
            $this->assertStringNotContainsString($forbiddenPayload, $submit);
        }
    }

    public function test_success_refreshes_all_sources_and_transitions_to_reassignment(): void
    {
        $view = $this->viewSource();
        $reload = $this->between($view, 'async function reloadAfterResolutionOpening()', 'async function openManualResolution()');
        $submit = $this->between($view, 'async function openManualResolution()', 'function openReassignmentConfirmation()');

        foreach (['await refreshDetail()', 'await loadHistory()', 'await load(meta.value.current_page)'] as $refresh) {
            $this->assertStringContainsString($refresh, $reload);
        }
        $this->assertStringContainsString("status: 'manual_resolution_required'", $submit);
        $this->assertStringContainsString('can_open_resolution: false', $submit);
        $this->assertStringContainsString('can_reassign_and_approve: true', $submit);
        $this->assertStringContainsString("resolution: { status: 'open', type: null }", $submit);
        $this->assertStringContainsString('resolutionOpeningConfirmOpen.value = false', $submit);
        $this->assertStringContainsString('await reloadAfterResolutionOpening()', $submit);
        $this->assertStringContainsString('v-if="canReassignAndApprove"', $view);
    }

    public function test_conflict_is_safe_refreshes_state_and_blocks_an_immediate_retry(): void
    {
        $submit = $this->between($this->viewSource(), 'async function openManualResolution()', 'function openReassignmentConfirmation()');

        $this->assertStringContainsString("status === 409 && code === 'payment_resolution_conflict'", $submit);
        $this->assertStringContainsString('resolutionOpeningBlocked.value = true', $submit);
        $this->assertStringContainsString('resolutionOpeningConfirmOpen.value = false', $submit);
        $this->assertStringContainsString('await reloadAfterResolutionOpening()', $submit);
        $this->assertStringContainsString("detail: 'El estado cambió y la resolución no puede abrirse.'", $submit);
        foreach (['response.data.message', 'response.data.exception', 'response.data.trace', 'sql', 'bindings', 'constraint'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, strtolower($submit));
        }
    }

    private function viewSource(): string
    {
        return file_get_contents(resource_path('js/views/treasury/TreasuryPayments.vue'));
    }

    private function resolutionModal(string $view): string
    {
        return $this->between($view, '<div v-if="resolutionOpeningConfirmOpen"', '<div v-if="reassignmentConfirmOpen"');
    }

    private function between(string $contents, string $start, string $end): string
    {
        $offset = strpos($contents, $start);
        $this->assertNotFalse($offset, "No se encontró el inicio: $start");
        $endOffset = strpos($contents, $end, $offset);
        $this->assertNotFalse($endOffset, "No se encontró el final: $end");

        return substr($contents, $offset, $endOffset - $offset);
    }
}
