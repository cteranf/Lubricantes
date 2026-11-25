<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentSubmission;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\CommercialOrderPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommercialOrderPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_download_private_commercial_summary_but_other_customers_cannot(): void
    {
        $this->app['config']->set('electronic_invoicing.enabled', false);
        $owner = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $other = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $order = $this->order($owner);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => null,
            'product_name' => 'Aceite histórico QA',
            'quantity' => 2,
            'price' => '100.00',
            'regular_price' => '109.00',
            'subtotal' => '200.00',
        ]);

        $this->getJson("/api/v1/orders/{$order->id}/commercial-documents")->assertUnauthorized();
        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/orders/{$order->id}/commercial-documents")
            ->assertOk()
            ->assertJsonPath('order_summary_available', true)
            ->assertJsonPath('payment_constancy_available', false);
        $pdf = $this->get("/api/v1/orders/{$order->id}/commercial-documents/summary")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('no-store', $pdf->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $path = getenv('COMMERCIAL_PDF_SINGLE_OUTPUT') ?: storage_path('app/commercial-pdf-review-summary.pdf');
        file_put_contents($path, $pdf->getContent());

        Sanctum::actingAs($other);
        $this->getJson("/api/v1/orders/{$order->id}/commercial-documents")->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->id}/commercial-documents/summary")->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->id}/commercial-documents/payment-constancy")->assertNotFound();
    }

    public function test_pickup_summary_supports_multiple_pages_without_changing_saved_amounts(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $order = $this->order($customer);
        $order->update(['delivery_type' => 'pickup', 'shipping_amount' => '0.00']);
        foreach (range(1, 55) as $index) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => null,
                'product_name' => 'Producto de recojo '.$index.' · lubricante histórico presentación QA',
                'quantity' => 1,
                'price' => '100.00',
                'regular_price' => '109.00',
                'subtotal' => '100.00',
            ]);
        }
        Sanctum::actingAs($customer);

        $response = $this->get("/api/v1/orders/{$order->id}/commercial-documents/summary")->assertOk();
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        preg_match_all('/\/Type\s*\/Page\b/', $pdf, $pageObjects);
        $this->assertGreaterThan(1, count($pageObjects[0]), 'El resumen de muchas líneas debe paginarse.');
        $this->assertSame('250.00', $order->fresh()->total);
        $this->assertSame('0.00', $order->fresh()->shipping_amount);
        $path = getenv('COMMERCIAL_PDF_MULTIPAGE_OUTPUT') ?: storage_path('app/commercial-pdf-review-multipage.pdf');
        file_put_contents($path, $pdf);
    }

    public function test_payment_constancy_requires_approved_financial_records_and_discloses_partial_refunds(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $order = $this->order($customer);
        PaymentSubmission::create([
            'order_id' => $order->id,
            'channel' => 'bank_transfer',
            'status' => PaymentSubmission::PENDING_REVIEW,
            'expected_amount' => $order->total,
            'currency' => 'PEN',
            'operation_number' => 'QA-VOUCHER-ONLY',
            'normalized_operation_number' => 'QA-VOUCHER-ONLY',
            'duplicate_fingerprint' => hash('sha256', 'commercial-voucher-'.$order->id),
            'declared_paid_at' => now(),
            'receiving_account_snapshot' => ['display_name' => 'Synthetic account'],
            'submitted_by' => $customer->id,
            'submitted_at' => now(),
            'idempotency_key' => 'commercial-voucher-'.$order->id,
        ]);
        Sanctum::actingAs($customer);

        $this->getJson("/api/v1/orders/{$order->id}/commercial-documents")
            ->assertOk()
            ->assertJsonPath('payment_constancy_available', false)
            ->assertJsonPath('payment_constancy_unavailable_reason', 'No hay un pago aprobado verificable. Un voucher pendiente de revisión no es suficiente.');
        $this->getJson("/api/v1/orders/{$order->id}/commercial-documents/payment-constancy")->assertNotFound();

        $rejected = $this->payment($order, 'failed', '20.00', 'rejected-only');
        $this->getJson("/api/v1/orders/{$order->id}/commercial-documents")
            ->assertOk()->assertJsonPath('payment_constancy_available', false);

        $first = $this->payment($order, PaymentTransaction::APPROVED, '100.00', 'partial-one');
        $second = $this->payment($order, PaymentTransaction::APPROVED, '50.00', 'partial-two');
        $this->refund($first, '20.00', 'completed', 'refund-done');
        $this->refund($second, '10.00', 'pending', 'refund-pending');

        $this->getJson("/api/v1/orders/{$order->id}/commercial-documents")
            ->assertOk()
            ->assertJsonPath('payment_constancy_available', true)
            ->assertJsonPath('approved_amount', '150.00')
            ->assertJsonPath('refunded_amount', '20.00')
            ->assertJsonPath('pending_refund_amount', '10.00')
            ->assertJsonPath('net_received_amount', '130.00')
            ->assertJsonPath('remaining_amount', '120.00')
            ->assertJsonPath('payment_progress_label', 'Pago parcial');
        $response = $this->get("/api/v1/orders/{$order->id}/commercial-documents/payment-constancy")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        file_put_contents(storage_path('app/commercial-pdf-review-payment.pdf'), $response->getContent());
        $this->assertDatabaseHas('payment_transactions', ['id' => $rejected->id, 'status' => 'failed']);
        $this->assertSame('250.00', $order->fresh()->total);
    }

    public function test_full_payment_is_distinguished_from_partial_payment(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $order = $this->order($customer);
        $this->payment($order, PaymentTransaction::APPROVED, '250.00', 'full-payment');
        Sanctum::actingAs($customer);

        $this->getJson("/api/v1/orders/{$order->id}/commercial-documents")
            ->assertOk()
            ->assertJsonPath('payment_progress_label', 'Pago completo')
            ->assertJsonPath('remaining_amount', '0.00');
        $this->get("/api/v1/orders/{$order->id}/commercial-documents/payment-constancy")->assertOk();
    }

    public function test_summary_uses_historical_promotional_prices_after_catalog_changes(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $product = Product::create([
            'name' => 'Nombre de catálogo al comprar',
            'slug' => 'commercial-pdf-historical-'.uniqid(),
            'sku' => 'COMM-PDF-'.uniqid(),
            'price' => '109.00',
            'sale_price' => '109.00',
            'is_active' => true,
        ]);
        $order = $this->order($customer);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Aceite sintético histórico 5W-30',
            'quantity' => 2,
            'price' => '100.00',
            'regular_price' => '109.00',
            'subtotal' => '200.00',
        ]);
        $product->update(['name' => 'Nombre nuevo de catálogo', 'price' => '159.00', 'sale_price' => '159.00']);

        $line = app(CommercialOrderPdfService::class)->orderSummaryLines($order)->first();
        $money = fn (mixed $amount): string => number_format((float) $amount, 2, '.', '');

        $this->assertSame('Aceite sintético histórico 5W-30', $line['name']);
        $this->assertSame('100.00', $money($line['unit_price']));
        $this->assertSame('109.00', $money($line['regular_unit_price']));
        $this->assertSame('200.00', $money($line['line_subtotal']));
        $this->assertSame('18.00', $money($line['line_savings']));
        $this->assertSame('250.00', $money($order->fresh()->total));
    }

    public function test_administrators_billing_and_treasury_can_download_with_their_existing_scopes(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $order = $this->order($customer);

        foreach ([
            ['admin', "/api/v1/admin/orders/{$order->id}/commercial-documents"],
            ['billing', "/api/v1/billing/orders/{$order->id}/commercial-documents"],
            ['treasury', "/api/v1/treasury/orders/{$order->id}/commercial-documents"],
        ] as [$role, $path]) {
            $staff = User::factory()->create(['role' => $role, 'is_active' => true]);
            Sanctum::actingAs($staff);
            $this->getJson($path)->assertOk()->assertJsonPath('order_summary_available', true);
            $pdfPath = str_replace('/commercial-documents', '/commercial-documents/summary', $path);
            $this->get($pdfPath)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/admin/orders/{$order->id}/commercial-documents")->assertForbidden();
        $this->getJson("/api/v1/billing/orders/{$order->id}/commercial-documents")->assertForbidden();
        $this->getJson("/api/v1/treasury/orders/{$order->id}/commercial-documents")->assertForbidden();

        $inactiveBilling = User::factory()->create(['role' => 'billing', 'is_active' => false]);
        Sanctum::actingAs($inactiveBilling);
        $this->getJson("/api/v1/billing/orders/{$order->id}/commercial-documents")->assertForbidden();
    }

    private function order(User $customer): Order
    {
        return Order::create([
            'user_id' => $customer->id,
            'status' => 'pending',
            'total' => '250.00',
            'subtotal' => '200.00',
            'discount_total' => '0.00',
            'shipping_amount' => '50.00',
            'shipping_info' => [],
            'payment_method' => 'transferencia',
            'payment_status' => 'pending',
            'delivery_type' => 'delivery',
        ]);
    }

    private function payment(Order $order, string $status, string $amount, string $key): PaymentTransaction
    {
        return PaymentTransaction::create([
            'order_id' => $order->id,
            'payment_method' => 'transferencia',
            'transaction_type' => PaymentTransaction::PAYMENT,
            'status' => $status,
            'amount' => $amount,
            'currency' => 'PEN',
            'idempotency_key' => 'commercial-pdf-'.$order->id.'-'.$key,
            'manual_reference' => 'REFERENCE-'.$key,
            'confirmed_at' => $status === PaymentTransaction::APPROVED ? now() : null,
        ]);
    }

    private function refund(PaymentTransaction $payment, string $amount, string $status, string $key): void
    {
        DB::table('payment_refunds')->insert([
            'payment_resolution_case_id' => null,
            'original_payment_transaction_id' => $payment->id,
            'refund_payment_transaction_id' => null,
            'amount' => $amount,
            'currency' => 'PEN',
            'status' => $status,
            'reason' => 'Synthetic QA refund',
            'requested_at' => now(),
            'completed_at' => $status === 'completed' ? now() : null,
            'idempotency_key' => $key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
