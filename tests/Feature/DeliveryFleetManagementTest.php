<?php

namespace Tests\Feature;

use App\Models\DeliveryDriver;
use App\Models\DeliveryVehicle;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeliveryFleetManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_driver_and_customer_cannot_manage_drivers(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        Sanctum::actingAs($customer);
        $this->postJson('/api/v1/admin/delivery-drivers', $this->driverData())->assertForbidden();

        Sanctum::actingAs($this->admin());
        $response = $this->postJson('/api/v1/admin/delivery-drivers', $this->driverData())->assertCreated();
        $this->assertSame('DRV-001', $response->json('code'));
        $this->assertDatabaseHas('delivery_drivers', ['code' => 'DRV-001', 'is_active' => 1]);
    }

    public function test_driver_code_and_document_are_unique(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/delivery-drivers', $this->driverData())->assertCreated();
        $this->postJson('/api/v1/admin/delivery-drivers', $this->driverData())->assertUnprocessable();
    }

    public function test_vehicle_plate_is_normalized_and_unique(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/delivery-vehicles', $this->vehicleData(['plate_number' => ' ab- 123 ']))->assertCreated();
        $this->assertDatabaseHas('delivery_vehicles', ['plate_number' => 'AB123']);
        $this->postJson('/api/v1/admin/delivery-vehicles', $this->vehicleData(['code' => 'VH-002', 'plate_number' => 'AB123']))->assertUnprocessable();
        $this->postJson('/api/v1/admin/delivery-vehicles', $this->vehicleData(['code' => 'VH-003', 'plate_number' => 'ab-123']))->assertUnprocessable();
    }

    public function test_options_exclude_unavailable_driver_and_vehicle(): void
    {
        Sanctum::actingAs($this->admin());
        $driver = DeliveryDriver::create($this->driverData(['code' => 'DRV-002', 'is_available' => false]));
        $vehicle = DeliveryVehicle::create($this->vehicleData(['code' => 'VH-002', 'is_available' => false]));
        $options = $this->getJson('/api/v1/admin/delivery/options')->assertOk()->json();
        $this->assertNotContains($driver->id, array_column($options['drivers'], 'id'));
        $this->assertNotContains($vehicle->id, array_column($options['vehicles'], 'id'));
    }

    public function test_assignment_saves_driver_vehicle_snapshots_and_blocks_duplicate_active_assignment(): void
    {
        $admin = $this->admin();
        $driver = DeliveryDriver::create($this->driverData(['code' => 'DRV-003']));
        $vehicle = DeliveryVehicle::create($this->vehicleData(['code' => 'VH-003', 'plate_number' => 'XY-999']));
        $first = $this->readyOrder();
        $second = $this->readyOrder();
        Sanctum::actingAs($admin);
        foreach ([$first, $second] as $order) {
            $this->postJson("/api/v1/admin/orders/{$order->id}/delivery/initialize")->assertCreated();
            $this->patchJson("/api/v1/admin/orders/{$order->id}/delivery/method", ['method' => 'own_delivery'])->assertOk();
        }
        $this->postJson("/api/v1/admin/orders/{$first->id}/delivery/assign-driver", ['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id, 'scheduled_at' => now()->addHour()->toIso8601String()])->assertOk();
        $this->assertDatabaseHas('order_deliveries', ['order_id' => $first->id, 'driver_id' => $driver->id, 'vehicle_id' => $vehicle->id, 'driver_code_snapshot' => 'DRV-003', 'vehicle_plate_snapshot' => 'XY-999']);
        $this->postJson("/api/v1/admin/orders/{$second->id}/delivery/assign-driver", ['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id, 'scheduled_at' => now()->addHour()->toIso8601String()])->assertUnprocessable();
    }

    public function test_new_assignment_dispatches_without_legacy_delivery_fields_and_allows_same_order_occupied_resources(): void
    {
        $admin = $this->admin();
        $driver = DeliveryDriver::create($this->driverData(['code' => 'DRV-004']));
        $vehicle = DeliveryVehicle::create($this->vehicleData(['code' => 'VH-004', 'plate_number' => 'ZZ-444']));
        $order = $this->readyOrder();
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/orders/{$order->id}/delivery/initialize")->assertCreated();
        $this->patchJson("/api/v1/admin/orders/{$order->id}/delivery/method", ['method' => 'own_delivery'])->assertOk();
        $this->postJson("/api/v1/admin/orders/{$order->id}/delivery/assign-driver", ['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id, 'scheduled_at' => now()->addHour()->toIso8601String()])->assertOk();
        $driver->update(['is_available' => false]);
        $vehicle->update(['is_available' => false]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/delivery/dispatch")
            ->assertOk()->assertJsonPath('delivery.status', OrderDelivery::DISPATCHED);
        $this->assertNotNull($order->delivery()->value('dispatched_at'));
        $this->assertSame(1, $order->delivery()->first()->history()->where('event_type', 'dispatched')->count());
    }

    private function driverData(array $extra = []): array
    {
        return array_merge(['code' => 'DRV-001', 'first_name' => 'Ana', 'last_name' => 'Reparto', 'document_type' => 'DNI', 'document_number' => '12345678', 'phone' => '999111222', 'is_active' => true, 'is_available' => true], $extra);
    }

    private function vehicleData(array $extra = []): array
    {
        return array_merge(['code' => 'VH-001', 'plate_number' => 'AB-123', 'vehicle_type' => 'motorcycle', 'ownership_type' => 'company', 'is_active' => true, 'is_available' => true], $extra);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function readyOrder(): Order
    {
        $customer = User::factory()->create(['role' => 'customer']);

        return Order::create(['user_id' => $customer->id, 'status' => 'shipped', 'total' => '20.00', 'shipping_info' => ['address' => 'Av. Reparto 123', 'phone' => '999'], 'payment_method' => 'card', 'payment_status' => 'approved', 'paid_at' => now(), 'delivery_type' => 'delivery', 'tracking_status' => 'shipped', 'fulfillment_status' => 'ready', 'delivery_flow_version' => 1, 'ready_at' => now()]);
    }
}
