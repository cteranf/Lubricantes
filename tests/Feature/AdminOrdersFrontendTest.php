<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminOrdersFrontendTest extends TestCase
{
    public function test_orders_view_statically_retires_the_legacy_approval_action_for_treasury_submissions(): void
    {
        $view = file_get_contents(resource_path('js/views/admin/Orders.vue'));

        foreach (['has_payment_submission', 'payment_submission_status', '!selectedOrder.has_payment_submission', 'paymentSubmissionLabel', 'Pendiente de Tesorería', 'Observado por Tesorería', 'Aprobado por Tesorería', 'Rechazado por Tesorería', 'Presentación vencida', 'Presentación cancelada', 'Este pago se gestiona desde el flujo de Tesorería.', 'approve-transfer'] as $expected) {
            $this->assertStringContainsString($expected, $view);
        }
        foreach (['/treasury/payments', 'v-html', 'localStorage', 'sessionStorage', 'operation_number', 'duplicate_fingerprint', 'idempotency_key', 'receiving_account_snapshot'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $view);
        }
    }
}
