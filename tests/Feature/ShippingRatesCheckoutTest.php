<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Department;
use App\Models\District;
use App\Models\Order;
use App\Models\Product;
use App\Models\Province;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShippingRatesCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_zone_and_validates_code_and_day_range(): void
    {
        Sanctum::actingAs($this->admin());
        $payload = ['code' => 'lima-centro', 'name' => 'Lima Centro', 'estimated_days_min' => 1, 'estimated_days_max' => 3, 'is_active' => true];
        $this->postJson('/api/v1/admin/shipping-zones', $payload)->assertCreated()->assertJsonPath('code', 'LIMA-CENTRO');
        $this->postJson('/api/v1/admin/shipping-zones', $payload)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/admin/shipping-zones', array_merge($payload, ['code' => 'OTRA', 'estimated_days_min' => 5, 'estimated_days_max' => 2]))->assertUnprocessable()->assertJsonValidationErrors('estimated_days_max');
    }

    public function test_customer_cannot_administer_shipping_configuration(): void
    {
        Sanctum::actingAs($this->customer());
        $this->getJson('/api/v1/admin/shipping-zones')->assertForbidden();
        $this->postJson('/api/v1/admin/shipping-rates', [])->assertForbidden();
    }

    public function test_active_rate_is_unique_after_normalizing_accents_case_and_spaces(): void
    {
        Sanctum::actingAs($this->admin());
        $department = Department::create(['code' => 'LIM', 'name' => 'Líma', 'is_active' => true]);
        $province = Province::create(['department_id' => $department->id, 'code' => 'LIM', 'name' => 'Lima', 'is_active' => true]);
        $district = District::create(['province_id' => $province->id, 'code' => 'SAN-ISI', 'name' => 'San Isidro', 'ubigeo' => '150131', 'is_active' => true]);
        $zone = ShippingZone::create(['code' => 'FIRST-ZONE', 'name' => 'Primera zona', 'is_active' => true]);
        $this->postJson('/api/v1/admin/shipping-rates', ['shipping_zone_id' => $zone->id, 'district_id' => $district->id, 'amount' => '8.50', 'is_active' => true])->assertCreated();
        $otherZone = ShippingZone::create(['code' => 'OTHER-ZONE', 'name' => 'Otra zona', 'is_active' => true]);
        $payload = ['shipping_zone_id' => $otherZone->id, 'district_id' => $district->id, 'amount' => '9.00', 'is_active' => true];
        $this->postJson('/api/v1/admin/shipping-rates', $payload)->assertUnprocessable()->assertJsonValidationErrors('district_id');
        $payload['is_active'] = false;
        $this->postJson('/api/v1/admin/shipping-rates', $payload)->assertCreated();
        $this->assertNotSame($zone->id, $otherZone->id);
    }

    public function test_quote_returns_amount_and_effective_estimate_without_internal_fields(): void
    {
        $customer = $this->customer();
        [$zone] = $this->coverage('Lima', 'Lima', 'Miraflores', '12.40', 2, 4);
        $address = $this->address($customer, 'Lima', 'Lima', 'Miraflores');
        Sanctum::actingAs($customer);
        $response = $this->postJson('/api/v1/checkout/shipping-quote', ['address_id' => $address->id])
            ->assertOk()->assertJsonPath('has_coverage', true)->assertJsonPath('shipping_amount', '12.40')
            ->assertJsonPath('estimated_days_min', 2)->assertJsonPath('estimated_days_max', 4)->assertJsonPath('zone_name', $zone->name);
        $this->assertSame(['has_coverage', 'shipping_amount', 'currency', 'estimated_days_min', 'estimated_days_max', 'zone_name', 'district', 'message'], array_keys($response->json()));
    }

    public function test_inactive_rate_or_zone_and_unknown_district_have_no_coverage(): void
    {
        $customer = $this->customer();
        [$zone, $rate] = $this->coverage('Lima', 'Lima', 'Barranco', '7.00');
        $address = $this->address($customer, 'Lima', 'Lima', 'Barranco');
        Sanctum::actingAs($customer);
        $rate->update(['is_active' => false]);
        $this->postJson('/api/v1/checkout/shipping-quote', ['address_id' => $address->id])->assertOk()->assertJsonPath('has_coverage', false);
        $rate->update(['is_active' => true]);
        $zone->update(['is_active' => false]);
        $this->postJson('/api/v1/checkout/shipping-quote', ['address_id' => $address->id])->assertOk()->assertJsonPath('has_coverage', false);
        $unknown = $this->address($customer, 'Lima', 'Lima', 'Distrito desconocido');
        $this->postJson('/api/v1/checkout/shipping-quote', ['address_id' => $unknown->id])->assertOk()->assertJsonPath('has_coverage', false);
    }

    public function test_customer_cannot_quote_another_users_address(): void
    {
        $owner = $this->customer();
        $address = $this->address($owner, 'Lima', 'Lima', 'Surco');
        Sanctum::actingAs($this->customer());
        $this->postJson('/api/v1/checkout/shipping-quote', ['address_id' => $address->id])->assertNotFound();
    }

    public function test_delivery_recalculates_shipping_and_total_and_saves_immutable_snapshot(): void
    {
        $customer = $this->customer();
        [$zone, $rate] = $this->coverage('Lima', 'Lima', 'Surquillo', '7.50', 1, 2);
        $address = $this->address($customer, 'Lima', 'Lima', 'Surquillo');
        $product = $this->product(5, '25.00');
        Sanctum::actingAs($customer);
        $payload = $this->deliveryPayload($product, $address, 'delivery-token');
        $payload += ['shipping_amount' => '0.01', 'shipping_rate_id' => 999, 'total' => '0.01'];
        $response = $this->postJson('/api/v1/orders', $payload)->assertCreated()
            ->assertJsonPath('shipping_amount', '7.50')->assertJsonPath('total', '57.50')
            ->assertJsonPath('shipping_zone_name_snapshot', $zone->name)->assertJsonPath('shipping_district_snapshot', 'Surquillo');
        $order = Order::findOrFail($response->json('id'));
        $this->assertSame($rate->id, $order->shipping_rate_id);
        $snapshot = $order->only(['total', 'shipping_amount', 'shipping_zone_name_snapshot', 'shipping_district_snapshot']);
        $rate->update(['amount' => '99.00']);
        $zone->update(['name' => 'Zona renombrada']);
        $this->assertSame($snapshot, $order->refresh()->only(array_keys($snapshot)));
        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $order->id, 'warehouse_id' => app(InventoryService::class)->defaultWarehouse()->id, 'status' => 'active']);
    }

    public function test_current_rate_is_used_at_confirmation_and_inactive_rate_rejects_delivery(): void
    {
        $customer = $this->customer();
        [, $rate] = $this->coverage('Lima', 'Lima', 'Pueblo Libre', '5.00');
        $address = $this->address($customer, 'Lima', 'Lima', 'Pueblo Libre');
        $product = $this->product(4, '10.00');
        Sanctum::actingAs($customer);
        $this->postJson('/api/v1/checkout/shipping-quote', ['address_id' => $address->id])->assertJsonPath('shipping_amount', '5.00');
        $rate->update(['amount' => '8.00']);
        $this->postJson('/api/v1/orders', $this->deliveryPayload($product, $address))->assertCreated()->assertJsonPath('shipping_amount', '8.00');
        $rate->update(['is_active' => false]);
        $this->postJson('/api/v1/orders', $this->deliveryPayload($product, $address))->assertUnprocessable()->assertJsonValidationErrors('address_id');
    }

    public function test_zone_deactivated_after_quote_rejects_confirmation(): void
    {
        $customer = $this->customer();
        [$zone] = $this->coverage('Lima', 'Lima', 'Magdalena', '6.00');
        $address = $this->address($customer, 'Lima', 'Lima', 'Magdalena');
        $product = $this->product(3, '10.00');
        Sanctum::actingAs($customer);
        $this->postJson('/api/v1/checkout/shipping-quote', ['address_id' => $address->id])->assertOk()->assertJsonPath('has_coverage', true);
        $zone->update(['is_active' => false]);
        $this->postJson('/api/v1/orders', $this->deliveryPayload($product, $address))->assertUnprocessable()->assertJsonValidationErrors('address_id');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_delivery_requires_address_and_coverage_and_token_is_idempotent_per_user(): void
    {
        $customer = $this->customer();
        $product = $this->product(4, '10.00');
        Sanctum::actingAs($customer);
        $payload = $this->deliveryPayload($product, $this->address($customer, 'Lima', 'Lima', 'Sin cobertura'), 'idempotent-delivery');
        $this->postJson('/api/v1/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('address_id');
        [, $rate] = $this->coverage('Lima', 'Lima', 'Sin cobertura', '3.00');
        $first = $this->postJson('/api/v1/orders', $payload)->assertCreated()->json('id');
        $rate->update(['amount' => '20.00']);
        $second = $this->postJson('/api/v1/orders', $payload)->assertOk()->json('id');
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);
        Sanctum::actingAs($this->customer());
        $this->postJson('/api/v1/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('checkout_token');
    }

    public function test_legacy_json_addresses_are_visible_but_not_migrated_without_confirmation(): void
    {
        $customer = $this->customer();
        $customer->update(['addresses' => [
            ['label' => 'Casa anterior', 'address' => 'Av. Histórica 456'],
            ['address' => 'Dirección incompleta', 'city' => 'No reinterpretar'],
            'dato inválido',
        ]]);
        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/addresses')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonCount(2, 'legacy_addresses')
            ->assertJsonPath('legacy_addresses.0.address', 'Av. Histórica 456')
            ->assertJsonMissingPath('legacy_addresses.1.city')
            ->assertJsonPath('legacy_confirmation_required', true);
        $this->assertDatabaseCount('user_addresses', 0);
        $this->assertSame('Av. Histórica 456', $customer->refresh()->addresses[0]['address']);
    }

    public function test_pickup_does_not_require_a_rate_and_saves_zero_shipping_without_rate_links(): void
    {
        $customer = $this->customer();
        $product = $this->product(3, '10.00');
        $branch = Branch::create([
            'code' => 'PICKUP-NO-RATE', 'name' => 'Sede recojo', 'address' => 'Av. Sede 123',
            'department' => 'Lima', 'province' => 'Lima', 'district' => 'Sin cobertura',
            'allows_pickup' => true, 'serves_public' => true, 'is_active' => true,
        ]);
        Sanctum::actingAs($customer);
        $response = $this->postJson('/api/v1/orders', [
            'checkout_token' => 'pickup-without-rate', 'delivery_type' => 'pickup',
            'pickup_branch_id' => $branch->id, 'shipping_info' => ['phone' => '999111222'],
            'payment_method' => 'transferencia', 'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('shipping_amount', '0.00')
            ->assertJsonPath('shipping_zone_id', null)->assertJsonPath('shipping_rate_id', null);
        $this->assertDatabaseHas('orders', [
            'id' => $response->json('id'), 'shipping_amount' => '0.00',
            'shipping_zone_id' => null, 'shipping_rate_id' => null,
        ]);
        $this->assertDatabaseCount('shipping_rates', 0);
    }

    private function coverage(string $department, string $province, string $district, string $amount, ?int $min = null, ?int $max = null): array
    {
        $zone = ShippingZone::create(['code' => 'Z-'.Str::upper(Str::random(8)), 'name' => 'Zona '.Str::random(5), 'estimated_days_min' => $min, 'estimated_days_max' => $max, 'is_active' => true]);
        $rate = ShippingRate::create($this->ratePayload($zone, $department, $province, $district, $amount));

        return [$zone, $rate];
    }

    private function ratePayload(ShippingZone $zone, string $department, string $province, string $district, string $amount): array
    {
        return ['shipping_zone_id' => $zone->id, 'department' => $department, 'province' => $province, 'district' => $district, 'amount' => $amount, 'is_active' => true];
    }

    private function address(User $user, string $department, string $province, string $district): UserAddress
    {
        return UserAddress::create(['user_id' => $user->id, 'recipient_name' => $user->name, 'phone' => '999111222', 'address' => 'Av. Prueba 123', 'department' => $department, 'province' => $province, 'district' => $district, 'is_active' => true]);
    }

    private function deliveryPayload(Product $product, UserAddress $address, ?string $token = null): array
    {
        return ['checkout_token' => $token ?? (string) Str::uuid(), 'delivery_type' => 'delivery', 'address_id' => $address->id, 'shipping_info' => [], 'payment_method' => 'transferencia', 'items' => [['product_id' => $product->id, 'quantity' => 2]]];
    }

    private function product(int $stock, string $price): Product
    {
        $product = Product::create(['name' => 'Producto '.Str::random(5), 'slug' => 'shipping-'.Str::uuid(), 'sku' => 'SH-'.Str::upper(Str::random(8)), 'price' => $price, 'is_active' => true]);
        app(InventoryService::class)->initializeProduct($product, $stock, app(InventoryService::class)->defaultWarehouse());

        return $product;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => 'customer']);
    }
}
