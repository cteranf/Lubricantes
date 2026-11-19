<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BusinessTaxConfiguration;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesShippingCoverage;
use Tests\TestCase;

class OrderTaxCheckoutTest extends TestCase
{
    use CreatesShippingCoverage, RefreshDatabase;

    public function test_checkout_quote_and_order_snapshot_detect_material_tax_change_without_changing_total(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        Sanctum::actingAs($customer);
        $product = $this->product('Aceite tributario QA', 'TAX-CHECKOUT-1', '118.00', '10');
        $inventory = app(InventoryService::class);
        $inventory->initializeProduct($product, 8, $inventory->defaultWarehouse());
        $branch = $this->branch();
        $input = ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'shipping_amount' => '0.00'];
        $quote = $this->postJson('/api/v1/cart', $input)->assertOk()
            ->assertJsonPath('tax_preview.status', 'complete')
            ->assertJsonPath('tax_preview.lines.0.base', '200.00')
            ->assertJsonPath('tax_preview.lines.0.tax', '36.00');

        $payload = [
            'checkout_token' => (string) Str::uuid(),
            'delivery_type' => 'pickup', 'pickup_branch_id' => $branch->id,
            'payment_method' => 'transferencia', 'expected_total' => '236.00',
            'tax_quote_token' => $quote->json('tax_quote_token'),
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ];
        $product->update(['tax_affectation' => '20']);

        $changed = $this->postJson('/api/v1/orders', $payload)->assertStatus(409)
            ->assertJsonPath('code', 'tax_calculation_changed')
            ->assertJsonPath('total', '236.00')
            ->assertJsonPath('tax_preview.lines.0.tax_affectation.code', '20');
        $this->assertDatabaseCount('orders', 0);
        $payload['tax_quote_token'] = $changed->json('tax_quote_token');

        $response = $this->postJson('/api/v1/orders', $payload)->assertCreated()
            ->assertJsonPath('total', '236.00')
            ->assertJsonPath('tax_calculation_status', 'complete')
            ->assertJsonPath('tax_snapshot.lines.0.tax_affectation.code', '20');
        $order = Order::query()->with('items')->findOrFail($response->json('id'));
        $this->assertSame('complete', $order->tax_calculation_status);
        $this->assertSame('20', $order->items->first()->tax_snapshot['tax_affectation']['code']);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame(2, (int) $order->items->first()->quantity);
        $this->assertSame(8, (int) $product->fresh()->stock);
        $this->assertDatabaseHas('inventory_reservations', ['order_item_id' => $order->items->first()->id, 'status' => 'active']);

        $savedSnapshot = $order->tax_snapshot;
        $product->update(['tax_affectation' => '30']);
        $this->configureRates(1600, 200, 3);
        $this->assertSame($savedSnapshot, $order->fresh()->tax_snapshot);
        $this->assertSame('236.00', $order->fresh()->total);
    }

    public function test_order_creation_without_tax_configuration_keeps_checkout_and_marks_tax_pending(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        Sanctum::actingAs($customer);
        $product = $this->product('Aceite no clasificado', 'TAX-PENDING-1', '25.00', null);
        BusinessTaxConfiguration::query()->delete();
        $inventory = app(InventoryService::class);
        $inventory->initializeProduct($product, 5, $inventory->defaultWarehouse());

        $response = $this->postJson('/api/v1/orders', [
            'checkout_token' => (string) Str::uuid(), 'delivery_type' => 'pickup', 'pickup_branch_id' => $this->branch()->id,
            'payment_method' => 'transferencia', 'expected_total' => '25.00',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('total', '25.00')->assertJsonPath('tax_calculation_status', 'pending_configuration');

        $order = Order::findOrFail($response->json('id'));
        $this->assertSame('25.00', $order->total);
        $this->assertSame('pending_configuration', $order->tax_snapshot['status']);
        $this->assertDatabaseCount('inventory_reservations', 1);
    }

    public function test_admin_tax_configuration_is_versioned_and_effective_date_is_explicit(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $this->getJson('/api/v1/admin/electronic-invoicing/tax-configuration')->assertOk()
            ->assertJsonPath('data.configured', false)->assertJsonPath('data.version', 0)
            ->assertJsonPath('data.business_timezone', config('app.timezone'));
        $response = $this->putJson('/api/v1/admin/electronic-invoicing/tax-configuration', [
            'igv_rate' => '15.50', 'ipm_rate' => '2.50', 'effective_from' => now()->toDateString(),
            'prices_include_tax' => true, 'version' => 0,
        ])->assertOk()->assertJsonPath('data.configured', true)->assertJsonPath('data.total_rate', '18.00')
            ->assertJsonPath('data.version', 1);

        $this->putJson('/api/v1/admin/electronic-invoicing/tax-configuration', [
            'igv_rate' => '15.50', 'ipm_rate' => '2.50', 'effective_from' => now()->toDateString(),
            'prices_include_tax' => true, 'version' => 0,
        ])->assertStatus(409);
    }

    public function test_product_tax_affectation_is_optional_admin_only_and_not_in_public_resource(): void
    {
        $product = $this->product('Producto clasificable', 'TAX-ADMIN-1', '40.00', null);
        Sanctum::actingAs(User::factory()->create(['role' => 'customer', 'is_active' => true]));
        $this->putJson('/api/v1/admin/products/'.$product->id, ['tax_affectation' => '10'])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $this->putJson('/api/v1/admin/products/'.$product->id, ['tax_affectation' => '10'])->assertOk();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'tax_affectation' => '10']);
        $this->putJson('/api/v1/admin/products/'.$product->id, ['tax_affectation' => null])->assertOk();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'tax_affectation' => null]);

        $public = $this->getJson('/api/v1/products/'.$product->slug)->assertOk();
        $this->assertArrayNotHasKey('tax_affectation', $public->json());
    }

    public function test_mixed_paid_shipping_preserves_checkout_and_blocks_electronic_draft_issue(): void
    {
        config(['electronic_invoicing.enabled' => true]);
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        Sanctum::actingAs($customer);
        $taxable = $this->product('Producto gravado para envío QA', 'TAX-MIXED-1', '118.00', '10');
        $exempt = Product::create(['name' => 'Producto exonerado para envío QA', 'slug' => 'producto-exonerado-envio-qa', 'sku' => 'TAX-MIXED-2', 'price' => '20.00', 'tax_affectation' => '20', 'is_active' => true]);
        $inventory = app(InventoryService::class);
        $inventory->initializeProduct($taxable, 5, $inventory->defaultWarehouse());
        $inventory->initializeProduct($exempt, 5, $inventory->defaultWarehouse());
        $payload = $this->withShippingCoverage([
            'checkout_token' => (string) Str::uuid(), 'delivery_type' => 'delivery', 'shipping_info' => ['address' => 'Av. QA tributaria 123'],
            'payment_method' => 'transferencia', 'expected_total' => '153.00',
            'items' => [['product_id' => $taxable->id, 'quantity' => 1], ['product_id' => $exempt->id, 'quantity' => 1]],
        ], $customer);
        \App\Models\ShippingRate::query()->where('active_district_key', app(\App\Services\TerritoryNormalizer::class)->identityKey('Lima', 'Lima', 'Distrito de pruebas'))->update(['amount' => '15.00']);

        $cartQuote = $this->postJson('/api/v1/cart', [
            'items' => $payload['items'], 'shipping_amount' => '15.00',
        ])->assertOk()->assertJsonPath('tax_preview.status', 'review_required')
            ->assertJsonPath('tax_preview.shipping.gross', '15.00');
        $payload['tax_quote_token'] = $cartQuote->json('tax_quote_token');
        $orderResponse = $this->postJson('/api/v1/orders', $payload)->assertCreated()
            ->assertJsonPath('total', '153.00')->assertJsonPath('shipping_amount', '15.00')
            ->assertJsonPath('tax_calculation_status', 'review_required')
            ->assertJsonPath('tax_snapshot.shipping.tax', null)
            ->assertJsonPath('tax_snapshot.missing_fields.0', 'shipping_mixed_affectations');
        $order = Order::findOrFail($orderResponse->json('id'));
        $this->assertSame('153.00', $order->total);
        $this->assertSame(2, $order->items()->count());
        $this->assertSame(2, $order->reservations()->where('status', 'active')->count());

        Sanctum::actingAs(User::factory()->create(['role' => 'billing', 'is_active' => true]));
        $draft = $this->postJson('/api/v1/billing/orders/'.$order->id.'/electronic-documents', ['preparation_key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.tax_calculation_status', 'review_required')
            ->assertJsonPath('data.amounts.tax_snapshot.missing_fields.0', 'shipping_mixed_affectations');
        $this->postJson('/api/v1/billing/electronic-documents/'.$draft->json('data.id').'/issue')->assertStatus(409);
        $this->assertDatabaseCount('electronic_document_attempts', 0);
        $this->assertSame('153.00', $order->fresh()->total);
    }

    private function configureRates(int $igv = 1550, int $ipm = 250, int $version = 2): void
    {
        BusinessTaxConfiguration::query()->updateOrCreate(
            ['singleton_key' => BusinessTaxConfiguration::SINGLETON_KEY],
            ['igv_rate_basis_points' => $igv, 'ipm_rate_basis_points' => $ipm, 'effective_from' => now()->toDateString(), 'prices_include_tax' => true, 'shipping_treatment' => 'ancillary_to_goods', 'is_configured' => true, 'version' => $version]
        );
    }

    private function product(string $name, string $sku, string $price, ?string $affectation): Product
    {
        $this->configureRates();

        return Product::create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)), 'sku' => $sku, 'price' => $price, 'tax_affectation' => $affectation, 'is_active' => true]);
    }

    private function branch(): Branch
    {
        return Branch::create([
            'code' => 'TAX-'.Str::upper(Str::random(5)), 'name' => 'Sede tributaria QA', 'address' => 'Av. Prueba 100',
            'district' => 'Lima', 'province' => 'Lima', 'department' => 'Lima', 'business_hours' => 'Horario QA',
            'pickup_instructions' => 'Prueba sintética', 'allows_pickup' => true, 'serves_public' => true, 'is_active' => true,
        ]);
    }
}
