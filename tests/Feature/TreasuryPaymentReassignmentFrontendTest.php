<?php

namespace Tests\Feature;

use Tests\TestCase;

class TreasuryPaymentReassignmentFrontendTest extends TestCase
{
    public function test_reassignment_action_uses_only_the_backend_allowlisted_eligibility(): void
    {
        $view = $this->viewSource();

        $this->assertStringContainsString("detail.value?.status === 'manual_resolution_required'", $view);
        $this->assertStringContainsString("detail.value?.resolution?.status === 'open'", $view);
        $this->assertStringContainsString('detail.value?.can_reassign_and_approve === true', $view);
        $this->assertStringContainsString('v-if="canReassignAndApprove"', $view);
        $this->assertStringContainsString('Reasignar stock y aprobar', $view);
        $this->assertStringNotContainsString('expires_at', $this->computedEligibility($view));
        $this->assertStringNotContainsString('reservation_status', $this->computedEligibility($view));

        foreach (['pending_review', 'approved', 'rejected', 'observed'] as $incompatible) {
            $this->assertStringNotContainsString("status === '$incompatible'", $this->computedEligibility($view));
        }
    }

    public function test_accessible_confirmation_contains_only_safe_business_context(): void
    {
        $modal = $this->reassignmentModal($this->viewSource());

        foreach ([
            'role="dialog"',
            'aria-modal="true"',
            'aria-labelledby="reassignment-confirmation-title"',
            'Pedido',
            'Cliente',
            'Canal',
            'Importe',
            'Estado de resolución',
            'Se intentará reservar nuevamente, consumir inventario y aprobar el pago.',
            'Cancelar',
            'Procesando reasignación…',
        ] as $expected) {
            $this->assertStringContainsString($expected, $modal);
        }

        foreach ([
            'operation_number',
            'fingerprint',
            'idempotency',
            'metadata',
            'snapshot',
            'reservation_sequence',
            'approved_scope',
            'receiving_account',
        ] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, strtolower($modal));
        }
    }

    public function test_confirmation_posts_an_empty_body_once_and_cancel_never_requests(): void
    {
        $view = $this->viewSource();
        $cancel = $this->between($view, 'function closeReassignmentConfirmation()', 'async function reloadAfterReassignment()');
        $submit = $this->between($view, 'async function reassignAndApprove()', 'onMounted(() => load())');

        $this->assertStringContainsString('api.post(`/treasury/payment-submissions/${detail.value.id}/resolution/reassign`, {})', $submit);
        $this->assertStringContainsString('if (reassignmentLoading.value || !canReassignAndApprove.value || !detail.value) return', $submit);
        $this->assertStringContainsString('reassignmentLoading.value = true', $submit);
        $this->assertStringContainsString(':disabled="reassignmentLoading"', $this->reassignmentModal($view));
        $this->assertStringNotContainsString('api.', $cancel);
        $this->assertStringNotContainsString('Idempotency-Key', $view);
        foreach (['amount:', 'currency:', 'transaction_id', 'case_id', 'warehouse_id'] as $forbiddenPayload) {
            $this->assertStringNotContainsString($forbiddenPayload, $submit);
        }
    }

    public function test_success_and_conflicts_refresh_all_sources_and_remove_dangerous_actions(): void
    {
        $view = $this->viewSource();
        $reload = $this->between($view, 'async function reloadAfterReassignment()', 'async function reassignAndApprove()');
        $submit = $this->between($view, 'async function reassignAndApprove()', 'onMounted(() => load())');

        foreach (['await refreshDetail()', 'await loadHistory()', 'await load(meta.value.current_page)'] as $refresh) {
            $this->assertStringContainsString($refresh, $reload);
        }
        $this->assertStringContainsString("status: 'approved'", $submit);
        $this->assertStringContainsString('can_reassign_and_approve: false', $submit);
        $this->assertStringContainsString("status: data.data?.resolution?.status || 'reassigned'", $submit);
        $this->assertStringContainsString("code === 'insufficient_stock'", $submit);
        $this->assertStringContainsString("code === 'payment_reassignment_conflict'", $submit);
        $this->assertStringContainsString("summary: 'Stock insuficiente'", $submit);
        $this->assertStringContainsString("summary: 'Conflicto de reasignación'", $submit);
        $this->assertStringContainsString('reassignmentBlocked.value = true', $submit);
        $this->assertStringContainsString('reassignmentConfirmOpen.value = false', $submit);
        $this->assertStringContainsString('await reloadAfterReassignment()', $submit);
    }

    private function viewSource(): string
    {
        return file_get_contents(resource_path('js/views/treasury/TreasuryPayments.vue'));
    }

    private function computedEligibility(string $view): string
    {
        return $this->between($view, 'const canReassignAndApprove = computed', 'const detailItems = computed');
    }

    private function reassignmentModal(string $view): string
    {
        return $this->between($view, '<div v-if="reassignmentConfirmOpen"', '</main>');
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
