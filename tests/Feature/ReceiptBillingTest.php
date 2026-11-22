<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BusinessProfile;
use App\Models\ElectronicDocument;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReceiptBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_options_default_to_boleta_and_server_applies_strict_total_threshold(): void
    {
        $customer = $this->customer();
        Sanctum::actingAs($customer);
        $options = $this->getJson('/api/v1/checkout/receipt-options')->assertOk()
            ->assertJsonPath('data.enabled_types.0.value', 'boleta')
            ->assertJsonPath('data.default_type', 'boleta')
            ->assertJsonPath('data.boleta_identification_threshold', '700.00')
            ->json('data');

        $atThreshold = $this->product(350);
        $this->postJson('/api/v1/orders', $this->payload($atThreshold, $options, 2))
            ->assertCreated()->assertJsonPath('requested_receipt_type', 'boleta');

        $aboveThreshold = $this->product(700.01);
        $this->postJson('/api/v1/orders', $this->payload($aboveThreshold, $options, 1))
            ->assertUnprocessable()->assertJsonValidationErrors('receipt_request.identity_number');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_checkout_reflects_enabled_receipt_types_and_profile_rejects_disabling_every_type(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/admin/business-profile', [
            'boleta_enabled' => true,
            'factura_enabled' => true,
            'default_receipt_type' => 'factura',
        ])->assertOk()->assertJsonPath('data.receipt_settings.default_receipt_type', 'factura')
            ->assertJsonPath('data.receipt_settings.boleta_enabled', true)
            ->assertJsonPath('data.receipt_settings.factura_enabled', true);

        Sanctum::actingAs($this->customer());
        $options = $this->getJson('/api/v1/checkout/receipt-options')->assertOk();
        $this->assertCount(2, $options->json('data.enabled_types'));
        $this->assertSame('factura', $options->json('data.default_type'));

        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/admin/business-profile', [
            'boleta_enabled' => false,
            'factura_enabled' => false,
            'default_receipt_type' => 'boleta',
        ])->assertUnprocessable()->assertJsonValidationErrors('boleta_enabled');
        $this->assertDatabaseHas('business_profiles', ['boleta_enabled' => 1, 'factura_enabled' => 1, 'default_receipt_type' => 'factura']);
    }

    public function test_boleta_accepts_voluntary_identity_and_checkout_retry_keeps_the_original_snapshot(): void
    {
        $customer = $this->customer();
        Sanctum::actingAs($customer);
        $options = $this->getJson('/api/v1/checkout/receipt-options')->assertOk()->json('data');
        $product = $this->product(25);
        $payload = $this->payload($product, $options, 1);
        $payload['receipt_request'] = [
            'type' => 'boleta',
            'identity_type' => 'DNI',
            'identity_number' => '12345678',
            'recipient_name' => 'Comprador de prueba',
            'billing_address' => '',
            'email' => 'buyer@example.test',
        ];
        $id = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('id');
        $payload['receipt_request']['recipient_name'] = 'Nombre posterior ignorado';
        $payload['receipt_request']['email'] = 'changed@example.test';
        $this->postJson('/api/v1/orders', $payload)->assertOk()->assertJsonPath('id', $id);

        $customer->update(['name' => 'Nombre de cuenta cambiado', 'email' => 'changed-profile@example.test']);
        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/orders/'.$id.'/tracking')->assertOk()
            ->assertJsonPath('order.receipt_request.recipient_name', 'Comprador de prueba')
            ->assertJsonPath('order.receipt_request.email', 'buyer@example.test');

        $this->assertDatabaseHas('orders', [
            'id' => $id,
            'requested_identity_type' => 'DNI',
            'requested_identity_number' => '12345678',
            'requested_recipient_name' => 'Comprador de prueba',
            'requested_receipt_email' => 'buyer@example.test',
        ]);
        $this->assertSame(1, Order::where('checkout_token', $payload['checkout_token'])->count());
    }

    public function test_boleta_threshold_uses_the_server_recalculated_total_including_shipping(): void
    {
        $customer = $this->customer();
        Sanctum::actingAs($customer);
        $options = $this->getJson('/api/v1/checkout/receipt-options')->assertOk()->json('data');
        $zone = ShippingZone::create(['code' => 'BILL-THRESHOLD', 'name' => 'Zona de prueba', 'is_active' => true]);
        ShippingRate::create([
            'shipping_zone_id' => $zone->id,
            'department' => 'Lima',
            'province' => 'Lima',
            'district' => 'Surquillo',
            'amount' => '8.00',
            'is_active' => true,
        ]);
        $address = UserAddress::create([
            'user_id' => $customer->id,
            'recipient_name' => $customer->name,
            'phone' => '999111222',
            'address' => 'Dirección sintética',
            'department' => 'Lima',
            'province' => 'Lima',
            'district' => 'Surquillo',
            'is_active' => true,
        ]);
        $product = $this->product(347.50);
        $payload = $this->payload($product, $options, 2);
        $payload['delivery_type'] = 'delivery';
        $payload['address_id'] = $address->id;
        unset($payload['pickup_branch_id']);

        $this->postJson('/api/v1/orders', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('receipt_request.identity_number');
        $this->assertSame(0, Order::count());

        $payload['receipt_request']['identity_type'] = 'DNI';
        $payload['receipt_request']['identity_number'] = '12345678';
        $payload['receipt_request']['recipient_name'] = 'Comprador de prueba';
        $this->postJson('/api/v1/orders', $payload)->assertCreated()
            ->assertJsonPath('total', '703.00');
        $this->assertDatabaseHas('orders', [
            'requested_identity_required' => 1,
            'requested_identity_type' => 'DNI',
            'requested_identity_number' => '12345678',
        ]);
    }

    public function test_invoice_requires_ruc_name_and_billing_address_and_keeps_delivery_separate(): void
    {
        BusinessProfile::create(['singleton_key' => 'primary', 'country_code' => 'PE', 'boleta_enabled' => true, 'factura_enabled' => true, 'default_receipt_type' => 'factura']);
        $customer = $this->customer();
        Sanctum::actingAs($customer);
        $options = $this->getJson('/api/v1/checkout/receipt-options')->assertOk()->json('data');
        $product = $this->product(40);
        $payload = $this->payload($product, $options, 1);
        $payload['receipt_request'] = ['type' => 'factura', 'identity_type' => 'RUC', 'identity_number' => '20123456789', 'recipient_name' => 'Empresa receptora', 'billing_address' => 'Dirección fiscal separada', 'email' => 'factura@example.test'];

        $id = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('id');
        $order = Order::findOrFail($id);
        $this->assertSame('factura', $order->requested_receipt_type);
        $this->assertSame('RUC', $order->requested_identity_type);
        $this->assertSame('Dirección fiscal separada', $order->requested_billing_address);
        $this->assertNotSame($order->requested_billing_address, data_get($order->shipping_info, 'address'));

        $payload['checkout_token'] = (string) Str::uuid();
        $payload['receipt_request']['billing_address'] = '';
        $this->postJson('/api/v1/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('receipt_request.billing_address');
    }

    public function test_boleta_accepts_the_other_configured_identity_document_formats_as_text(): void
    {
        $customer = $this->customer();
        Sanctum::actingAs($customer);
        $options = $this->getJson('/api/v1/checkout/receipt-options')->assertOk()->json('data');
        $product = $this->product(10);

        foreach ([['CE', 'ABC-00123'], ['PASSPORT', 'AB123456']] as [$type, $number]) {
            $payload = $this->payload($product, $options, 1);
            $payload['receipt_request'] = [
                'type' => 'boleta',
                'identity_type' => $type,
                'identity_number' => $number,
                'recipient_name' => 'Titular de prueba',
                'billing_address' => '',
                'email' => 'buyer@example.test',
            ];

            $orderId = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('id');
            $this->assertDatabaseHas('orders', ['id' => $orderId, 'requested_identity_type' => $type, 'requested_identity_number' => $number]);
        }
    }

    public function test_settings_change_returns_controlled_conflict_without_creating_an_order(): void
    {
        $customer = $this->customer();
        Sanctum::actingAs($customer);
        $old = $this->getJson('/api/v1/checkout/receipt-options')->assertOk()->json('data');
        BusinessProfile::create(['singleton_key' => 'primary', 'country_code' => 'PE', 'boleta_enabled' => true, 'factura_enabled' => true, 'default_receipt_type' => 'boleta']);
        $product = $this->product(20);
        $payload = $this->payload($product, $old, 1);

        $this->postJson('/api/v1/orders', $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'receipt_settings_changed')
            ->assertJsonPath('receipt_options.enabled_types.1.value', 'factura');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_disabled_receipt_type_is_rejected_and_historical_order_has_no_snapshot(): void
    {
        BusinessProfile::create(['singleton_key' => 'primary', 'country_code' => 'PE', 'boleta_enabled' => true, 'factura_enabled' => false, 'default_receipt_type' => 'boleta']);
        $customer = $this->customer();
        Sanctum::actingAs($customer);
        $options = $this->getJson('/api/v1/checkout/receipt-options')->assertOk()->json('data');
        $payload = $this->payload($this->product(20), $options, 1);
        $payload['receipt_request']['type'] = 'factura';
        $this->postJson('/api/v1/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('receipt_request.type');

        $legacy = Order::create(['user_id' => $customer->id, 'status' => 'pending', 'total' => 20, 'shipping_info' => [], 'payment_method' => 'transferencia', 'delivery_type' => 'pickup', 'tracking_status' => 'pending']);
        $this->getJson('/api/v1/orders/'.$legacy->id.'/tracking')->assertOk()->assertJsonPath('order.receipt_request', null);
    }

    public function test_only_active_billing_and_admin_users_can_read_paginated_billing_orders(): void
    {
        $customer = $this->customer();
        $order = Order::create(['user_id' => $customer->id, 'status' => 'pending', 'total' => 600, 'shipping_info' => [], 'payment_method' => 'transferencia', 'payment_status' => 'pending', 'delivery_type' => 'pickup', 'tracking_status' => 'pending', 'requested_receipt_type' => 'boleta', 'requested_receipt_email' => 'buyer@example.test']);

        foreach (['billing', 'admin'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/v1/billing/orders')->assertOk()->assertJsonPath('data.0.id', $order->id);
            $this->getJson('/api/v1/billing/orders/'.$order->id)->assertOk()
                ->assertJsonPath('data.receipt_status', 'requested_not_issued')
                ->assertJsonPath('data.payment_status', 'pending')
                ->assertJsonPath('data.total', '600.00');
        }

        foreach ([['role' => 'customer', 'is_active' => true], ['role' => 'treasury', 'is_active' => true], ['role' => 'billing', 'is_active' => false]] as $attributes) {
            Sanctum::actingAs(User::factory()->create($attributes));
            $this->getJson('/api/v1/billing/orders')->assertForbidden();
            $this->getJson('/api/v1/billing/orders/'.$order->id)->assertForbidden();
        }
    }

    public function test_billing_inbox_distinguishes_customer_request_from_each_electronic_document_state(): void
    {
        $customer = $this->customer();
        $order = Order::create([
            'user_id' => $customer->id, 'status' => 'pending', 'total' => 600, 'shipping_info' => [],
            'payment_method' => 'transferencia', 'payment_status' => 'pending', 'delivery_type' => 'pickup', 'tracking_status' => 'pending',
            'requested_receipt_type' => 'factura', 'requested_identity_type' => 'RUC', 'requested_identity_number' => '20987654321',
            'requested_identity_required' => true, 'requested_recipient_name' => 'Receptor sintético', 'requested_receipt_email' => 'buyer@example.test',
        ]);
        $expectedLabels = [
            'draft' => 'Borrador; no emitido', 'queued' => 'Pendiente de procesamiento', 'processing' => 'Procesando',
            'submitted' => 'Enviado; resultado pendiente', 'uncertain' => 'Resultado incierto; requiere revisión manual',
            'accepted' => 'Aceptado en ambiente de pruebas', 'accepted_observed' => 'Aceptado con observaciones en ambiente de pruebas',
            'rejected' => 'Rechazado por SUNAT pruebas', 'sunat_exception' => 'Excepción SOAP de SUNAT; numeración conservada',
        ];
        foreach ($expectedLabels as $status => $label) {
            ElectronicDocument::query()->create([
                'order_id' => $order->id, 'draft_reference' => (string) Str::uuid(), 'preparation_key' => (string) Str::uuid(),
                'document_type' => 'factura', 'status' => $status, 'environment' => 'testing', 'connection_type' => 'direct_sunat',
                'seller_snapshot' => [], 'recipient_snapshot' => [], 'lines_snapshot' => [], 'amounts_snapshot' => ['total' => '600.00'],
                'currency' => 'PEN', 'tax_calculation_status' => 'pending_definition', 'version' => 1, 'prepared_at' => now(),
            ]);
        }

        Sanctum::actingAs(User::factory()->create(['role' => 'billing', 'is_active' => true]));
        $list = $this->getJson('/api/v1/billing/orders')->assertOk()
            ->assertJsonPath('data.0.receipt_status', 'requested_not_issued')
            ->assertJsonPath('data.0.requested_receipt_type', 'factura');
        $documents = collect($list->json('data.0.electronic_documents'))->keyBy('status');
        $this->assertCount(count($expectedLabels), $documents);
        foreach ($expectedLabels as $status => $label) {
            $this->assertSame($label, $documents[$status]['status_label']);
        }

        $detail = $this->getJson('/api/v1/billing/orders/'.$order->id)->assertOk();
        $this->assertCount(count($expectedLabels), $detail->json('data.electronic_documents'));
    }

    public function test_billing_role_can_only_be_assigned_by_admin_user_management(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->postJson('/api/v1/admin/users', [
            'name' => 'Operador de facturación', 'email' => 'billing-qa@example.test', 'phone' => '',
            'password' => 'StrongPassword#2026', 'password_confirmation' => 'StrongPassword#2026',
            'role' => 'billing', 'can_deliver' => true, 'is_active' => true,
        ])->assertCreated()->assertJsonPath('data.role', 'billing')->assertJsonPath('data.role_label', 'Facturación')->assertJsonPath('data.can_deliver', false);
        $this->assertSame('billing', User::findOrFail($response->json('data.id'))->role);

        Sanctum::actingAs($this->customer());
        $this->postJson('/api/v1/admin/users', [])->assertForbidden();
    }

    public function test_tracking_exposes_receipt_request_only_to_the_owner_and_never_marks_it_emitted(): void
    {
        $owner = $this->customer();
        $order = Order::create([
            'user_id' => $owner->id, 'status' => 'pending', 'total' => 600, 'shipping_info' => [], 'payment_method' => 'transferencia',
            'payment_status' => 'pending', 'delivery_type' => 'pickup', 'tracking_status' => 'pending',
            'requested_receipt_type' => 'boleta', 'requested_identity_type' => 'DNI',
            'requested_identity_number' => '12345678', 'requested_identity_required' => false,
            'requested_recipient_name' => 'Cliente dueño', 'requested_receipt_email' => 'owner@example.test',
        ]);
        $this->getJson('/api/v1/orders/'.$order->id.'/tracking')->assertUnauthorized();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/orders/'.$order->id.'/tracking')->assertOk()
            ->assertJsonPath('order.receipt_request.type', 'boleta')
            ->assertJsonPath('order.receipt_request.status', 'requested_not_issued')
            ->assertJsonPath('order.receipt_request.identity_number', '12345678');
        Sanctum::actingAs($this->customer());
        $this->getJson('/api/v1/orders/'.$order->id.'/tracking')->assertNotFound();
    }

    private function payload(Product $product, array $options, int $quantity): array
    {
        $branch = Branch::create(['code' => 'BILL-'.Str::upper(Str::random(8)), 'name' => 'Sede de prueba', 'address' => 'Av. Central 100', 'allows_pickup' => true, 'serves_public' => true, 'is_active' => true]);

        return [
            'checkout_token' => (string) Str::uuid(),
            'receipt_settings_version' => $options['version'],
            'receipt_request' => ['type' => $options['default_type'], 'identity_type' => null, 'identity_number' => null, 'recipient_name' => null, 'billing_address' => null, 'email' => 'buyer@example.test'],
            'delivery_type' => 'pickup', 'pickup_branch_id' => $branch->id, 'payment_method' => 'transferencia',
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        ];
    }

    private function product(float $price): Product
    {
        $product = Product::create(['name' => 'Producto fiscal sintético', 'slug' => 'fiscal-'.Str::uuid(), 'sku' => 'FISCAL-'.Str::upper(Str::random(8)), 'price' => $price, 'is_active' => true]);
        app(InventoryService::class)->initializeProduct($product, 100, app(InventoryService::class)->defaultWarehouse());

        return $product;
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => 'customer', 'email_verified_at' => now()]);
    }
}
