<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\SyntheticMercadoPagoLink;
use Tests\TestCase;

class PaymentReturnReadOnlyTest extends TestCase
{
    use RefreshDatabase;
    use SyntheticMercadoPagoLink;

    public function test_forged_browser_parameters_cannot_approve_or_write(): void
    {
        $this->withRemoteLink(function ($transport, $order, $preference) {
            Sanctum::actingAs($order->user);
            $before = $order->getRawOriginal();
            $writes = new \ArrayObject;
            DB::listen(static function ($query) use ($writes) {
                if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                    $writes->append(true);
                }
            });
            $response = $this->getJson('/api/v1/payment/return?'.http_build_query([
                'preference_id' => $preference->provider_preference_id,
                'payment_id' => (string) $transport->paymentId,
                'collection_id' => (string) $transport->paymentId,
                'external_reference' => 'synthetic-'.bin2hex(random_bytes(16)),
                'status' => 'approved', 'result' => 'approved',
            ]));
            $this->assertTrue($response->status() === 200, 'Owned preference must be readable.');
            $this->assertSame('pending', $response->json('display_status'));
            $this->assertSame($order->id, $response->json('order_id'));
            $this->assertTrue($before === $order->refresh()->getRawOriginal(), 'Browser return must preserve the order.');
            $this->assertCount(0, $writes);
            $this->assertCount(0, $transport->calls);
            $this->assertDatabaseCount('payment_transactions', 0);
        });
    }

    public function test_another_customer_cannot_read_return_or_mutate_the_order(): void
    {
        $this->withRemoteLink(function ($transport, $order, $preference) {
            Sanctum::actingAs(\App\Models\User::factory()->create());
            $before = $order->getRawOriginal();
            $response = $this->getJson('/api/v1/payment/return?'.http_build_query(['preference_id' => $preference->provider_preference_id, 'result' => 'approved']));
            $this->assertTrue($response->status() === 404, 'Foreign preference must not be exposed.');
            $this->assertTrue($before === $order->refresh()->getRawOriginal(), 'Foreign return must preserve the order.');
            $this->assertCount(0, $transport->calls);
        });
    }

    public function test_frontend_uses_only_read_api_and_server_display_status(): void
    {
        $source = file_get_contents(resource_path('js/views/PaymentReturn.vue'));
        $this->assertStringContainsString("api.get('/payment/return'", $source);
        $this->assertStringContainsString('status.value = response.data.display_status', $source);
        $this->assertDoesNotMatchRegularExpression('/api\.(post|put|patch|delete)\s*\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/status\.value\s*=\s*route\.query/', $source);
    }
}
