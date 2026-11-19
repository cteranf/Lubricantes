<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\ElectronicDocument;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ElectronicInvoicingFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['electronic_invoicing.enabled' => true]);
    }

    public function test_billing_user_prepares_idempotent_historical_draft_and_can_correct_it_with_version_control(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($admin);
        BusinessProfile::create([
            'singleton_key' => 'primary', 'commercial_name' => 'LubriStore QA', 'legal_name' => 'LubriStore QA SAC',
            'tax_id_type' => 'RUC', 'tax_id_number' => '20123456789', 'country_code' => 'PE',
            'fiscal_address' => 'Dirección fiscal sintética', 'boleta_enabled' => true,
        ]);
        $customer = User::factory()->create(['role' => 'customer']);
        $order = $this->order($customer, '600.00');
        OrderItem::create(['order_id' => $order->id, 'product_id' => null, 'product_name' => 'Aceite histórico', 'quantity' => 2, 'regular_price' => '310.00', 'price' => '300.00', 'subtotal' => '600.00']);

        $billing = User::factory()->create(['role' => 'billing', 'is_active' => true]);
        Sanctum::actingAs($billing);
        $key = (string) Str::uuid();
        $response = $this->postJson('/api/v1/billing/orders/'.$order->id.'/electronic-documents', ['preparation_key' => $key])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.status_label', 'Borrador; no emitido')
            ->assertJsonPath('data.lines.items.0.name', 'Aceite histórico')->assertJsonPath('data.lines.items.0.unit_price', '300.00')
            ->assertJsonPath('data.amounts.total', '600.00')->assertJsonPath('data.tax_calculation_status', 'pending_configuration');
        $id = $response->json('data.id');
        $this->postJson('/api/v1/billing/electronic-documents/'.$id.'/issue')->assertStatus(409)
            ->assertJsonPath('message', 'El cálculo tributario está pendiente o requiere revisión. No se puede preparar una emisión hasta completar y validar el desglose.');
        $this->assertDatabaseCount('electronic_document_attempts', 0);
        $this->postJson('/api/v1/billing/orders/'.$order->id.'/electronic-documents', ['preparation_key' => $key])
            ->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(1, ElectronicDocument::where('order_id', $order->id)->count());
        $this->postJson('/api/v1/billing/orders/'.$order->id.'/electronic-documents', ['preparation_key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame(2, ElectronicDocument::where('order_id', $order->id)->count());
        $this->assertSame('600.00', $order->fresh()->total);

        $this->putJson('/api/v1/billing/electronic-documents/'.$id, [
            'version' => 1,
            'recipient' => ['name' => 'Corrección de prueba'],
        ])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.recipient.name', 'Corrección de prueba')
            ->assertJsonPath('data.history.1.event_type', 'updated')->assertJsonPath('data.history.1.actor_name', $billing->name);
        $this->putJson('/api/v1/billing/electronic-documents/'.$id, ['version' => 1, 'recipient' => ['name' => 'Obsoleto']])->assertStatus(409);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'total' => '600.00']);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'price' => '300.00']);
    }

    public function test_commercial_enablement_is_read_only_on_business_profile_and_disabled_module_blocks_preparation(): void
    {
        config(['electronic_invoicing.enabled' => false]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/business-profile')->assertOk()->assertJsonPath('data.electronic_invoicing.enabled', false)->assertJsonPath('data.electronic_invoicing.editable_here', false);
        $this->putJson('/api/v1/admin/business-profile', ['electronic_invoicing_enabled' => true])->assertUnprocessable()->assertJsonValidationErrors('request');
        $order = $this->order(User::factory()->create(['role' => 'customer']), '25.00');
        config(['electronic_invoicing.enabled' => true]);
        Sanctum::actingAs(User::factory()->create(['role' => 'billing', 'is_active' => true]));
        $id = $this->postJson('/api/v1/billing/orders/'.$order->id.'/electronic-documents', ['preparation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        config(['electronic_invoicing.enabled' => false]);
        $this->getJson('/api/v1/billing/electronic-documents/'.$id)->assertOk()->assertJsonPath('data.id', $id);
        $this->putJson('/api/v1/billing/electronic-documents/'.$id, ['version' => 1, 'recipient' => ['name' => 'No debe guardarse']])->assertStatus(409);
        $this->postJson('/api/v1/billing/orders/'.$order->id.'/electronic-documents', ['preparation_key' => (string) Str::uuid()])->assertStatus(409);
        $this->postJson('/api/v1/billing/electronic-documents/'.$id.'/issue')->assertStatus(409);
        $this->assertDatabaseHas('electronic_documents', ['id' => $id, 'status' => 'draft', 'version' => 1]);
        $this->assertDatabaseCount('electronic_document_events', 1);
        $this->assertDatabaseCount('electronic_documents', 1);
    }

    public function test_admin_configures_operational_mode_but_unsupported_issue_is_controlled_and_does_not_fake_acceptance(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/electronic-invoicing/settings')->assertOk()
            ->assertJsonPath('data.environment', 'testing')->assertJsonPath('data.connection_available', false)
            ->assertJsonPath('data.readiness.tax_rules', false);
        $this->putJson('/api/v1/admin/electronic-invoicing/settings', [
            'connection_type' => 'provider_api', 'provider_name' => 'provider-test', 'environment' => 'testing', 'version' => 1,
        ])->assertOk()->assertJsonPath('data.connection_available', false)->assertJsonPath('data.environment', 'testing');

        $order = $this->order(User::factory()->create(['role' => 'customer']), '75.00');
        $order->update([
            'tax_calculation_status' => 'complete',
            'tax_calculation_version' => 'peruvian-inclusive-tax-v1',
            'tax_snapshot' => ['status' => 'complete', 'missing_fields' => [], 'totals' => ['gross_minor' => '75.00']],
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'billing', 'is_active' => true]));
        $id = $this->postJson('/api/v1/billing/orders/'.$order->id.'/electronic-documents', ['preparation_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/billing/electronic-documents/'.$id.'/issue')->assertStatus(503)->assertJsonPath('code', 'connection_not_implemented');
        $this->assertDatabaseHas('electronic_documents', ['id' => $id, 'status' => 'draft']);
        $this->assertDatabaseCount('electronic_document_attempts', 0);
    }

    public function test_billing_draft_reads_the_order_tax_snapshot_without_recalculating_it(): void
    {
        config(['electronic_invoicing.enabled' => true]);
        BusinessProfile::create([
            'singleton_key' => 'primary', 'commercial_name' => 'LubriStore QA', 'legal_name' => 'LubriStore QA SAC',
            'tax_id_type' => 'RUC', 'tax_id_number' => '20123456789', 'country_code' => 'PE',
            'fiscal_address' => 'Dirección fiscal sintética', 'boleta_enabled' => true,
        ]);
        $order = $this->order(User::factory()->create(['role' => 'customer']), '118.00');
        $lineTax = [
            'calculation_status' => 'complete',
            'tax_affectation' => ['code' => '10', 'label' => 'Gravado: operación onerosa'],
            'quantity' => 1, 'regular_unit_gross' => '118.00', 'applied_unit_gross' => '118.00',
            'promotion_discount_gross' => '0.00', 'gross' => '118.00', 'base' => '100.00',
            'tax' => '18.00', 'igv' => '15.50', 'ipm' => '2.50', 'missing_fields' => [],
        ];
        $taxSnapshot = [
            'status' => 'complete', 'calculation_version' => 'peruvian-inclusive-tax-v1',
            'configuration_version' => 2, 'currency' => 'PEN', 'prices_include_tax' => true,
            'lines' => [$lineTax], 'shipping' => ['status' => 'complete', 'gross' => '0.00', 'base' => '0.00', 'tax' => '0.00'],
            'totals' => ['gross_minor' => '118.00', 'taxable_base_minor' => '100.00', 'igv_minor' => '15.50', 'ipm_minor' => '2.50', 'tax_minor' => '18.00'],
            'missing_fields' => [],
        ];
        $order->update(['tax_calculation_status' => 'complete', 'tax_calculation_version' => 'peruvian-inclusive-tax-v1', 'tax_snapshot' => $taxSnapshot]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => null, 'product_name' => 'Aceite snapshot', 'quantity' => 1,
            'regular_price' => '118.00', 'price' => '118.00', 'subtotal' => '118.00', 'tax_snapshot' => $lineTax,
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'billing', 'is_active' => true]));
        $response = $this->postJson('/api/v1/billing/orders/'.$order->id.'/electronic-documents', ['preparation_key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.tax_calculation_status', 'complete')
            ->assertJsonPath('data.lines.items.0.tax.base', '100.00')
            ->assertJsonPath('data.lines.items.0.tax.tax', '18.00')
            ->assertJsonPath('data.amounts.tax_snapshot.totals.igv_minor', '15.50')
            ->assertJsonPath('data.amounts.missing_fields', []);
        $this->assertSame('complete', $order->fresh()->tax_calculation_status);
        $this->assertSame('118.00', $order->fresh()->total);
        $this->assertDatabaseCount('electronic_document_attempts', 0);
        $this->assertNotEmpty($response->json('data.lines.items.0.tax'));
    }

    public function test_document_routes_remain_separate_from_treasury_and_customers(): void
    {
        $order = $this->order(User::factory()->create(['role' => 'customer']), '10.00');
        foreach (['customer', 'treasury'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
            $this->getJson('/api/v1/billing/orders/'.$order->id)->assertForbidden();
            $this->getJson('/api/v1/admin/electronic-invoicing/settings')->assertForbidden();
        }
        Sanctum::actingAs(User::factory()->create(['role' => 'billing', 'is_active' => true]));
        $this->getJson('/api/v1/admin/electronic-invoicing/settings')->assertForbidden();
    }

    private function order(User $customer, string $total): Order
    {
        return Order::create([
            'user_id' => $customer->id, 'status' => 'pending', 'total' => $total, 'subtotal' => $total,
            'shipping_amount' => '0.00', 'discount_total' => '0.00', 'shipping_info' => [],
            'payment_method' => 'transferencia', 'payment_status' => 'pending', 'delivery_type' => 'pickup', 'tracking_status' => 'pending',
            'requested_receipt_type' => 'boleta', 'requested_recipient_name' => 'Receptor histórico',
            'requested_identity_type' => 'DNI', 'requested_identity_number' => '12345678',
            'requested_receipt_email' => 'receptor@example.test',
        ]);
    }
}
