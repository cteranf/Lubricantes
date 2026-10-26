<?php

namespace Tests\Feature;

use App\Services\PaymentGateway\MercadoPagoGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MercadoPago\Client\Payment\PaymentClient;
use Tests\Feature\Concerns\SyntheticMercadoPagoLink;
use Tests\TestCase;

class MercadoPagoRemoteLinkContractTest extends TestCase
{
    use RefreshDatabase;
    use SyntheticMercadoPagoLink;

    public function test_real_sdk_drops_preference_id_and_current_gateway_cannot_prove_link(): void
    {
        $this->withRemoteLink(function ($transport, $order, $preference) {
            $paymentId = $transport->payment['id'];
            $preferenceId = $preference->provider_preference_id;
            $reference = $preference->external_reference;
            // Extra transport field is deliberately discarded by the real SDK.
            // Only the separately retrieved merchant order can prove the link.
            $transport->payment['preference_id'] = 'untrusted-'.bin2hex(random_bytes(16));
            $payment = (new PaymentClient)->get((string) $paymentId);
            $this->assertSame($paymentId, $payment->id);
            $this->assertFalse(property_exists($payment, 'preference_id'));
            $this->assertNull($payment->preference_id ?? null);

            $verified = (new MercadoPagoGateway)->verifyPayment((string) $paymentId);
            $this->assertSame(2, count(array_filter($transport->calls, fn ($call) => $call[1] === '/v1/payments/'.$paymentId)));
            $this->assertSame($reference, $verified['external_reference']);
            $this->assertSame(10.25, $verified['transaction_amount']);
            $this->assertSame('PEN', $verified['currency_id']);

            $this->assertNotNull($verified['preference_id'], 'P1: the SDK-backed gateway supplies no verifiable preference identity required by PaymentProcessingService.');
            $this->assertSame($preferenceId, $verified['preference_id']);
            $this->assertContains(['GET', '/merchant_orders/'.$transport->merchant['id']], $transport->calls);
        });
    }
}
