<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Order;
use App\Models\ShippingZone;
use App\Models\User;
use App\Models\UserAddress;
use App\Services\ShippingRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTerritoryCatalog;
use Tests\TestCase;

class TerritoryCatalogTest extends TestCase
{
    use CreatesTerritoryCatalog, RefreshDatabase;

    public function test_admin_creates_catalog_and_normalized_duplicates_are_rejected_by_scope(): void
    {
        Sanctum::actingAs($this->admin());
        $departmentId = $this->postJson('/api/v1/admin/departments', ['code' => 'SM', 'name' => 'San Martín', 'is_active' => true])->assertCreated()->json('id');
        $this->postJson('/api/v1/admin/departments', ['code' => 'SM2', 'name' => ' san  martin ', 'is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('name');

        $provinceId = $this->postJson('/api/v1/admin/provinces', ['department_id' => $departmentId, 'code' => 'MAY', 'name' => 'Moyobamba', 'is_active' => true])->assertCreated()->json('id');
        $this->postJson('/api/v1/admin/provinces', ['department_id' => $departmentId, 'code' => 'MAY2', 'name' => ' MOYOBAMBA ', 'is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('name');

        $districtId = $this->postJson('/api/v1/admin/districts', ['department_id' => $departmentId, 'province_id' => $provinceId, 'code' => 'MOY', 'name' => 'Moyobamba', 'ubigeo' => null, 'is_active' => true])->assertCreated()->json('id');
        $this->postJson('/api/v1/admin/districts', ['department_id' => $departmentId, 'province_id' => $provinceId, 'code' => 'MOY2', 'name' => '  moyobamba ', 'is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertNotNull($districtId);

        $other = Department::create(['code' => 'OTRO', 'name' => 'Otro departamento', 'is_active' => true]);
        $this->postJson('/api/v1/admin/provinces', ['department_id' => $other->id, 'code' => 'MAY', 'name' => 'Moyobamba', 'is_active' => true])->assertCreated();
    }

    public function test_parent_activity_rules_options_and_customer_permissions_are_enforced(): void
    {
        ['department' => $department, 'province' => $province, 'district' => $district] = $this->territory('RULES');
        Sanctum::actingAs($this->admin());
        $this->putJson("/api/v1/admin/departments/{$department->id}", ['code' => $department->code, 'name' => $department->name, 'is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->putJson("/api/v1/admin/provinces/{$province->id}", ['department_id' => $department->id, 'code' => $province->code, 'name' => $province->name, 'is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->patchJson("/api/v1/admin/provinces/{$province->id}/status", ['is_active' => false])->assertUnprocessable();
        $district->update(['is_active' => false]);
        $this->patchJson("/api/v1/admin/provinces/{$province->id}/status", ['is_active' => false])->assertOk();
        $this->patchJson("/api/v1/admin/districts/{$district->id}/status", ['is_active' => true])->assertUnprocessable();
        $this->patchJson("/api/v1/admin/departments/{$department->id}/status", ['is_active' => false])->assertOk();
        $this->patchJson("/api/v1/admin/provinces/{$province->id}/status", ['is_active' => true])->assertUnprocessable();

        $this->getJson('/api/v1/location/departments')->assertOk()->assertJsonMissing(['id' => $department->id]);
        $this->getJson('/api/v1/location/provinces')->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->getJson('/api/v1/location/districts')->assertUnprocessable()->assertJsonValidationErrors('province_id');

        Sanctum::actingAs($this->customer());
        $this->postJson('/api/v1/admin/departments', ['code' => 'NO', 'name' => 'Prohibido', 'is_active' => true])->assertForbidden();
    }

    public function test_address_requires_active_consistent_territory_and_cannot_update_another_users_address(): void
    {
        $first = $this->territory('ONE');
        $second = $this->territory('TWO');
        $owner = $this->customer();
        Sanctum::actingAs($owner);
        $payload = $this->addressPayload($first);
        $id = $this->postJson('/api/v1/addresses', $payload)->assertCreated()
            ->assertJsonPath('department', $first['department']->name)
            ->assertJsonPath('district_id', $first['district']->id)->json('id');

        $this->postJson('/api/v1/addresses', array_merge($payload, ['province_id' => $second['province']->id]))->assertUnprocessable()->assertJsonValidationErrors('province_id');
        $this->postJson('/api/v1/addresses', array_merge($payload, ['district_id' => $second['district']->id]))->assertUnprocessable()->assertJsonValidationErrors('district_id');
        $first['district']->update(['is_active' => false]);
        $this->postJson('/api/v1/addresses', $payload)->assertUnprocessable()->assertJsonValidationErrors('district_id');

        Sanctum::actingAs($this->customer());
        $this->putJson("/api/v1/addresses/{$id}", $this->addressPayload($second))->assertNotFound();
    }

    public function test_branch_validates_new_territory_while_historical_pickup_branch_remains_visible(): void
    {
        $first = $this->territory('BR1');
        $second = $this->territory('BR2');
        Sanctum::actingAs($this->admin());
        $payload = $this->branchPayload($first, 'CATALOGO');
        $this->postJson('/api/v1/admin/branches', $payload)->assertCreated()->assertJsonPath('district', $first['district']->name);
        $this->postJson('/api/v1/admin/branches', array_merge($payload, ['code' => 'INCONSISTENTE', 'province_id' => $second['province']->id]))->assertUnprocessable()->assertJsonValidationErrors('province_id');

        $historical = Branch::create(['code' => 'HISTORICA', 'name' => 'Sede histórica', 'address' => 'Av. Antigua', 'department' => 'Lima', 'province' => 'Lima', 'district' => 'Centro', 'allows_pickup' => true, 'serves_public' => true, 'is_active' => true]);
        Sanctum::actingAs($this->customer());
        $this->getJson('/api/v1/checkout/pickup-branches')->assertOk()->assertJsonFragment(['id' => $historical->id, 'name' => 'Sede histórica']);
    }

    public function test_rate_uses_district_id_is_unique_globally_and_service_prefers_catalog_identity(): void
    {
        $territory = $this->territory('RATE');
        $zoneA = ShippingZone::create(['code' => 'ZA', 'name' => 'Zona A', 'is_active' => true]);
        $zoneB = ShippingZone::create(['code' => 'ZB', 'name' => 'Zona B', 'is_active' => true]);
        Sanctum::actingAs($this->admin());
        $payload = ['shipping_zone_id' => $zoneA->id, 'district_id' => $territory['district']->id, 'amount' => '11.50', 'is_active' => true];
        $rateId = $this->postJson('/api/v1/admin/shipping-rates', $payload)->assertCreated()->assertJsonPath('district', $territory['district']->name)->json('id');
        $this->postJson('/api/v1/admin/shipping-rates', array_merge($payload, ['shipping_zone_id' => $zoneB->id]))->assertUnprocessable()->assertJsonValidationErrors('district_id');

        $user = $this->customer();
        $address = UserAddress::create(['user_id' => $user->id] + $this->addressPayload($territory) + ['department' => $territory['department']->name, 'province' => $territory['province']->name, 'district' => $territory['district']->name, 'is_active' => true]);
        $quote = app(ShippingRateService::class)->quote($address);
        $this->assertTrue($quote['has_coverage']);
        $this->assertSame($rateId, $quote['rate']->id);

        $inactive = $this->territory('INACTIVE-RATE');
        $inactive['district']->update(['is_active' => false]);
        $this->postJson('/api/v1/admin/shipping-rates', array_merge($payload, ['district_id' => $inactive['district']->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('district_id');
    }

    public function test_catalog_renames_do_not_change_order_snapshots(): void
    {
        $territory = $this->territory('SNAPSHOT');
        $user = $this->customer();
        $order = Order::create([
            'user_id' => $user->id, 'status' => 'pending', 'total' => '10.00',
            'shipping_info' => ['address' => 'Av. Histórica'], 'payment_method' => 'transferencia',
            'delivery_type' => 'delivery', 'tracking_status' => 'pending',
            'shipping_department_snapshot' => $territory['department']->name,
            'shipping_province_snapshot' => $territory['province']->name,
            'shipping_district_snapshot' => $territory['district']->name,
        ]);
        $snapshot = $order->only(['shipping_department_snapshot', 'shipping_province_snapshot', 'shipping_district_snapshot']);
        $territory['department']->update(['name' => 'Departamento renombrado']);
        $territory['province']->update(['name' => 'Provincia renombrada']);
        $territory['district']->update(['name' => 'Distrito renombrado']);

        $this->assertSame($snapshot, $order->refresh()->only(array_keys($snapshot)));
    }

    public function test_used_territories_cannot_be_deleted_and_legacy_records_are_not_backfilled(): void
    {
        $territory = $this->territory('USED');
        $user = $this->customer();
        UserAddress::create(['user_id' => $user->id] + $this->addressPayload($territory) + ['department' => $territory['department']->name, 'province' => $territory['province']->name, 'district' => $territory['district']->name, 'is_active' => true]);
        Sanctum::actingAs($this->admin());
        $this->deleteJson("/api/v1/admin/districts/{$territory['district']->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/admin/provinces/{$territory['province']->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/admin/departments/{$territory['department']->id}")->assertUnprocessable();

        $legacy = UserAddress::create(['user_id' => $user->id, 'recipient_name' => 'Histórico', 'phone' => '999111222', 'address' => 'Av. Antigua', 'department' => 'Texto', 'province' => 'Texto', 'district' => 'Texto', 'is_active' => true]);
        $this->assertNull($legacy->department_id);
        $this->assertNull($legacy->province_id);
        $this->assertNull($legacy->district_id);
    }

    private function addressPayload(array $territory): array
    {
        return ['recipient_name' => 'Cliente', 'phone' => '999111222', 'address' => 'Av. Prueba 123'] + [
            'department_id' => $territory['department']->id,
            'province_id' => $territory['province']->id,
            'district_id' => $territory['district']->id,
        ];
    }

    private function branchPayload(array $territory, string $code): array
    {
        return ['code' => $code, 'name' => 'Sede '.$code, 'address' => 'Av. Sede'] + [
            'department_id' => $territory['department']->id, 'province_id' => $territory['province']->id, 'district_id' => $territory['district']->id,
            'allows_pickup' => true, 'serves_public' => true, 'is_active' => true,
        ];
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
