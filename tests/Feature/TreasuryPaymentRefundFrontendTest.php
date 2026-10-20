<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class TreasuryPaymentRefundFrontendTest extends TestCase
{
    public function test_refund_state_machine_contract_runs_against_the_actual_frontend_module(): void
    {
        $process = new Process(['node', '--test', base_path('tests/frontend/treasuryRefunds.test.mjs')], base_path());
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Refund frontend behavioral contracts must pass. '.$process->getOutput().$process->getErrorOutput());
    }

    public function test_refunds_are_integrated_without_replacing_existing_treasury_flows(): void
    {
        $view = file_get_contents(resource_path('js/views/treasury/TreasuryPayments.vue'));
        foreach (['TreasuryRefunds', "activeSection === 'refunds'", "activeSection === 'payments'", 'refundBusy', 'canReassignAndApprove', 'canOpenResolution'] as $expected) {
            $this->assertStringContainsString($expected, $view);
        }
        $module = file_get_contents(resource_path('js/treasury/refundManager.mjs'));
        foreach (["'/treasury/refund-worklist'", '`/treasury/orders/${orderId}/refund-state`', '`/treasury/orders/${orderId}/refunds`', '`/treasury/payment-refunds/${refundId}/complete`'] as $expected) {
            $this->assertStringContainsString($expected, $module);
        }
        foreach (['Idempotency-Key', 'setInterval', 'setTimeout', 'console.', 'localStorage', 'sessionStorage', 'mercadopago', 'api.patch', 'api.delete'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $module);
        }
    }

    public function test_forms_privacy_actions_and_accessibility_are_explicit(): void
    {
        $view = file_get_contents(resource_path('js/components/treasury/TreasuryRefunds.vue'));
        foreach (["manager.can('pending')", "manager.can('complete')", 'requires_manual_review === true', 'v-model="s.reason"', 'v-model="s.external_reference"', 'aria-live="polite"', 'scope="col"', 'aria-describedby', 'autofocus', 's.commandLoading', 'Actualizar estado', 'Confirmo que Tesorería realizó la devolución', 'AdminPagination', 'md:hidden', 'hidden overflow-x-auto md:block'] as $expected) {
            $this->assertStringContainsString($expected, $view);
        }
        foreach (['v-model.trim', 'v-html', 'detail.reason', 'detail.external_reference', 'metadata', 'fingerprint', 'operation_number', 'error.message', 'response.data.message'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $view);
        }
        $modal = file_get_contents(resource_path('js/components/treasury/RefundDialog.vue'));
        foreach (['<dialog', 'showModal()', 'aria-labelledby', 'aria-describedby', '@cancel.prevent', 'previousFocus', '.focus()'] as $expected) {
            $this->assertStringContainsString($expected, $modal);
        }
    }
}
