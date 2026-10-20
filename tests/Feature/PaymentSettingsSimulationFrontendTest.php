<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class PaymentSettingsSimulationFrontendTest extends TestCase
{
    public function test_real_payment_settings_component_uses_confirmed_backend_simulation_state(): void
    {
        $process = new Process(['node', '--test', base_path('tests/frontend/paymentSettingsSimulation.test.mjs')], base_path());
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Payment settings simulation contracts must pass. '.$process->getOutput().$process->getErrorOutput());
    }
}
