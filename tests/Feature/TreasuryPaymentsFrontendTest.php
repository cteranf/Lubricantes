<?php

namespace Tests\Feature;

use Tests\TestCase;

class TreasuryPaymentsFrontendTest extends TestCase
{
    public function test_treasury_route_and_navigation_are_statically_scoped_to_the_treasury_role(): void
    {
        $router = file_get_contents(resource_path('js/router.js'));
        $layout = file_get_contents(resource_path('js/layouts/AdminLayout.vue'));
        $store = file_get_contents(resource_path('js/stores/auth.js'));

        $this->assertStringContainsString("path: '/treasury/payments'", $router);
        $this->assertStringContainsString('requiresTreasury: true', $router);
        $this->assertStringContainsString('to.meta.requiresTreasury', $router);
        $this->assertStringContainsString('authStore.isTreasury', $router);
        $this->assertStringContainsString('isTreasury:', $store);
        $this->assertStringContainsString('isTreasury', $layout);
        $this->assertStringContainsString('treasuryNavigation', $layout);
        $this->assertStringContainsString("{ label: 'Bandeja de pagos'", $layout);
        $this->assertStringNotContainsString("{ label: 'Usuarios'", substr($layout, strpos($layout, 'const treasuryNavigation')));
    }

    public function test_treasury_payments_view_statically_uses_the_five_safe_operations_and_real_list_contract(): void
    {
        $view = file_get_contents(resource_path('js/views/treasury/TreasuryPayments.vue'));

        foreach (['/treasury/payment-submissions', '/history', '/approve', "openDecision('observe')", "openDecision('reject')", 'search', 'status', 'channel', 'date_from', 'date_to', 'per_page', 'current_page', 'last_page', 'meta.value.total', 'operation_number_masked', 'AbortController', 'requestId'] as $needle) {
            $this->assertStringContainsString($needle, $view);
        }
        $this->assertStringContainsString('AdminPagination', $view);
        $this->assertStringContainsString('AdminEmptyState', $view);
        $this->assertStringContainsString('AdminIconButton', $view);
        $this->assertStringContainsString('AdminStatusBadge', $view);
        $this->assertStringContainsString('reservation_status', $view);
        $this->assertStringContainsString('Reserva vencida', $view);
        $this->assertStringContainsString('Revisión manual requerida', $view);
        $this->assertStringContainsString('canObserve', $view);
    }

    public function test_treasury_view_statically_keeps_errors_privacy_accessibility_and_mobile_cards_separate(): void
    {
        $view = file_get_contents(resource_path('js/views/treasury/TreasuryPayments.vue'));

        foreach (['listError', 'detailError', 'historyError', 'decisionError', 'fieldErrors', 'role="dialog"', 'aria-modal="true"', 'aria-labelledby', 'scope="col"', 'overflow-x-auto', 'md:hidden', 'hidden overflow-x-auto md:block', 'decisionLoading'] as $needle) {
            $this->assertStringContainsString($needle, $view);
        }
        foreach (['v-html', 'localStorage', 'sessionStorage', 'base64', 'console.log', 'Idempotency-Key'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $view);
        }
    }

    public function test_treasury_view_statically_preserves_the_review_layout_and_manual_review_guard(): void
    {
        $view = file_get_contents(resource_path('js/views/treasury/TreasuryPayments.vue'));

        foreach (['Buscar', 'Estado', 'Canal', 'Aplicar', 'Limpiar', 'Actualizar', 'grid gap-4', 'md:hidden', 'hidden overflow-x-auto md:block', 'scope="col"', 'max-h-[94vh]', 'overflow-y-auto', 'treasury-detail-title', 'Detalle para validación de Tesorería.', 'Historial', 'detailItems', 'eventLabel', 'Reserva vencida', 'Revisión manual requerida', 'No se debe aprobar ni reasignar stock automáticamente.', 'canObserve', 'canReject', 'v-if="canObserve"', 'v-if="canReject"', 'Cerrar'] as $needle) {
            $this->assertStringContainsString($needle, $view);
        }

        foreach (['v-html', 'localStorage', 'sessionStorage', 'base64', 'console.log', 'qr_path', 'qr_disk'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $view);
        }
    }

    public function test_treasury_detail_shows_channel_origin_data_safely_with_historical_fallback(): void
    {
        $view = file_get_contents(resource_path('js/views/treasury/TreasuryPayments.vue'));

        $this->assertStringContainsString("const originDetails = computed(() =>", $view);
        $this->assertStringContainsString("['yape', 'plin'].includes(detail.value?.channel)", $view);
        $this->assertStringContainsString("label: 'Celular del pagador'", $view);
        $this->assertStringContainsString('`•••• ${digits}`', $view);
        $this->assertStringContainsString("label: 'Banco de origen'", $view);
        $this->assertSame(2, substr_count($view, "'No registrado'"));
        $this->assertStringContainsString('...originDetails.value', $view);
        $this->assertStringNotContainsString('detail.value.origin_phone_last4 ||', $view);
    }

    public function test_treasury_detail_distinguishes_registered_operations_from_available_operations(): void
    {
        $view = file_get_contents(resource_path('js/views/treasury/TreasuryPayments.vue'));

        $this->assertStringContainsString("label: 'Operaciones registradas'", $view);
        $this->assertStringContainsString('`${operationsUsed} de ${accounting.max_operations}`', $view);
        $this->assertStringContainsString("Number(accounting.remaining_amount) > 0", $view);
        $this->assertStringContainsString("label: 'Operaciones disponibles'", $view);
        $this->assertStringContainsString('accounting.slots_left', $view);
        $this->assertStringContainsString("label: 'Estado del importe', value: 'Importe completo en revisión'", $view);
        $this->assertStringNotContainsString("label: 'Cupos restantes'", $view);
    }

    public function test_treasury_approval_is_statically_guarded_confirmed_and_safe(): void
    {
        $view = file_get_contents(resource_path('js/views/treasury/TreasuryPayments.vue'));

        foreach ([
            "detail.value?.status === 'pending_review'",
            "detail.value?.reservation_status === 'active'",
            'canApprove',
            'Aprobar pago',
            'approvalConfirmOpen',
            'approval-confirmation-title',
            'role="dialog"',
            'aria-modal="true"',
            'Esta acción confirmará el pago y consumirá la reserva de inventario.',
            'approvalLoading',
            'api.patch(`/treasury/payment-submissions/${detail.value.id}/approve`, {})',
            'manual_resolution_required',
            'payment_submission_approval_conflict',
            'reloadAfterApproval',
            'approved_payment',
            'Pago aprobado correctamente',
            'overflow-y-auto',
            'max-h-[94vh]',
        ] as $needle) {
            $this->assertStringContainsString($needle, $view);
        }

        foreach (['v-html', 'localStorage', 'sessionStorage', 'base64', 'console.log', 'idempotency_key', 'approved_scope_key', 'duplicate_fingerprint', 'qr_path', 'qr_disk'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $view);
        }
    }
}
