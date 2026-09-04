<?php

namespace Tests\Feature;

use App\Models\DeliveryDriver;
use App\Models\DeliveryVehicle;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderDeliveryHistory;
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

    public function test_driver_and_vehicle_occupancy_are_each_enforced_independently(): void
    {
        Sanctum::actingAs($this->admin());
        $occupiedDriver = DeliveryDriver::create($this->driverData(['code' => 'DRV-OCCUPIED', 'document_number' => '44444444']));
        $availableDriver = DeliveryDriver::create($this->driverData(['code' => 'DRV-AVAILABLE', 'document_number' => '55555555']));
        $occupiedVehicle = DeliveryVehicle::create($this->vehicleData(['code' => 'VH-OCCUPIED', 'plate_number' => 'OCC111']));
        $availableVehicle = DeliveryVehicle::create($this->vehicleData(['code' => 'VH-AVAILABLE', 'plate_number' => 'AVA111']));

        $assignedOrder = $this->readyOrder();
        $driverConflictOrder = $this->readyOrder();
        $vehicleConflictOrder = $this->readyOrder();
        foreach ([$assignedOrder, $driverConflictOrder, $vehicleConflictOrder] as $order) {
            $this->initializeOwnDelivery($order);
        }
        $this->postJson($this->assignUrl($assignedOrder), ['driver_id' => $occupiedDriver->id, 'vehicle_id' => $occupiedVehicle->id])->assertOk();

        $this->postJson($this->assignUrl($driverConflictOrder), ['driver_id' => $occupiedDriver->id, 'vehicle_id' => $availableVehicle->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.delivery.0', 'El repartidor ya tiene una entrega activa.');
        $this->postJson($this->assignUrl($vehicleConflictOrder), ['driver_id' => $availableDriver->id, 'vehicle_id' => $occupiedVehicle->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.delivery.0', 'El vehículo ya tiene una entrega activa.');
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

    public function test_inactive_driver_cannot_be_assigned(): void
    {
        [$admin, $order] = [$this->admin(), $this->readyOrder()];
        $driver = DeliveryDriver::create($this->driverData(['is_active' => false, 'is_available' => false]));
        $vehicle = DeliveryVehicle::create($this->vehicleData());
        Sanctum::actingAs($admin);
        $this->initializeOwnDelivery($order);

        $this->postJson($this->assignUrl($order), ['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id])
            ->assertUnprocessable();
    }

    public function test_unavailable_driver_cannot_be_assigned(): void
    {
        [$admin, $order] = [$this->admin(), $this->readyOrder()];
        $driver = DeliveryDriver::create($this->driverData(['is_available' => false]));
        $vehicle = DeliveryVehicle::create($this->vehicleData());
        Sanctum::actingAs($admin);
        $this->initializeOwnDelivery($order);

        $this->postJson($this->assignUrl($order), ['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id])
            ->assertUnprocessable();
    }

    public function test_motorized_assignment_requires_present_unexpired_license(): void
    {
        Sanctum::actingAs($this->admin());
        $vehicle = DeliveryVehicle::create($this->vehicleData());

        $validOrder = $this->readyOrder();
        $this->initializeOwnDelivery($validOrder);
        $valid = DeliveryDriver::create($this->driverData(['code' => 'DRV-VALID', 'document_number' => '11111111']));
        $this->postJson($this->assignUrl($validOrder), ['driver_id' => $valid->id, 'vehicle_id' => $vehicle->id])->assertOk();

        $expiredOrder = $this->readyOrder();
        $this->initializeOwnDelivery($expiredOrder);
        $expired = DeliveryDriver::create($this->driverData(['code' => 'DRV-EXPIRED', 'document_number' => '22222222', 'license_expires_at' => today()->subDay()->toDateString()]));
        $this->postJson($this->assignUrl($expiredOrder), ['driver_id' => $expired->id, 'vehicle_id' => DeliveryVehicle::create($this->vehicleData(['code' => 'VH-EXPIRED', 'plate_number' => 'EXP111']))->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.delivery.0', 'El repartidor tiene la licencia vencida.');

        $missingOrder = $this->readyOrder();
        $this->initializeOwnDelivery($missingOrder);
        $missing = DeliveryDriver::create($this->driverData(['code' => 'DRV-MISSING', 'document_number' => '33333333', 'license_number' => null, 'license_expires_at' => null]));
        $this->postJson($this->assignUrl($missingOrder), ['driver_id' => $missing->id, 'vehicle_id' => DeliveryVehicle::create($this->vehicleData(['code' => 'VH-MISSING', 'plate_number' => 'MIS111']))->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.delivery.0', 'El repartidor no tiene una licencia vigente para este vehículo.');
    }

    public function test_license_and_vehicle_documents_expiring_today_remain_valid(): void
    {
        Sanctum::actingAs($this->admin());
        $order = $this->readyOrder();
        $this->initializeOwnDelivery($order);
        $driver = DeliveryDriver::create($this->driverData(['license_expires_at' => today()->toDateString()]));
        $vehicle = DeliveryVehicle::create($this->vehicleData([
            'soat_expires_at' => today()->toDateString(),
            'technical_inspection_expires_at' => today()->toDateString(),
        ]));

        $this->postJson($this->assignUrl($order), ['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id])->assertOk();
        $this->postJson($this->dispatchUrl($order))->assertOk()->assertJsonPath('delivery.status', OrderDelivery::DISPATCHED);
    }

    public function test_bicycle_assignment_does_not_require_a_license(): void
    {
        Sanctum::actingAs($this->admin());
        $order = $this->readyOrder();
        $this->initializeOwnDelivery($order);
        $driver = DeliveryDriver::create($this->driverData(['license_number' => null, 'license_expires_at' => null]));
        $bicycle = DeliveryVehicle::create($this->vehicleData(['vehicle_type' => 'bicycle', 'plate_number' => null]));

        $this->postJson($this->assignUrl($order), ['driver_id' => $driver->id, 'vehicle_id' => $bicycle->id])->assertOk();
    }

    public function test_license_expired_after_assignment_blocks_dispatch_without_side_effects(): void
    {
        Sanctum::actingAs($this->admin());
        $order = $this->readyOrder();
        $this->initializeOwnDelivery($order);
        $driver = DeliveryDriver::create($this->driverData());
        $vehicle = DeliveryVehicle::create($this->vehicleData());
        $this->postJson($this->assignUrl($order), ['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id])->assertOk();
        $delivery = $order->delivery()->firstOrFail();
        $snapshot = $delivery->only(['driver_code_snapshot', 'driver_name_snapshot', 'vehicle_code_snapshot', 'vehicle_plate_snapshot']);
        $historyCount = OrderDeliveryHistory::count();
        $movementCount = InventoryMovement::count();
        $reservationCount = InventoryReservation::count();
        $driver->update(['license_expires_at' => today()->subDay()]);

        $this->postJson($this->dispatchUrl($order))
            ->assertUnprocessable()
            ->assertJsonPath('errors.delivery.0', 'El repartidor tiene la licencia vencida.');

        $delivery->refresh();
        $this->assertSame(OrderDelivery::ASSIGNED, $delivery->status);
        $this->assertNull($delivery->dispatched_at);
        $this->assertSame($snapshot, $delivery->only(array_keys($snapshot)));
        $this->assertSame($historyCount, OrderDeliveryHistory::count());
        $this->assertSame($movementCount, InventoryMovement::count());
        $this->assertSame($reservationCount, InventoryReservation::count());
    }

    /**
     * @dataProvider invalidVehicleAtDispatchProvider
     */
    public function test_vehicle_revalidation_blocks_dispatch_without_history(string $field, mixed $value): void
    {
        Sanctum::actingAs($this->admin());
        $order = $this->readyOrder();
        $this->initializeOwnDelivery($order);
        $driver = DeliveryDriver::create($this->driverData());
        $vehicle = DeliveryVehicle::create($this->vehicleData());
        $this->postJson($this->assignUrl($order), ['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id])->assertOk();
        $historyCount = OrderDeliveryHistory::count();
        $vehicle->update([$field => $value === 'yesterday' ? today()->subDay() : $value]);

        $this->postJson($this->dispatchUrl($order))->assertUnprocessable();

        $delivery = $order->delivery()->firstOrFail();
        $this->assertSame(OrderDelivery::ASSIGNED, $delivery->status);
        $this->assertNull($delivery->dispatched_at);
        $this->assertSame($historyCount, OrderDeliveryHistory::count());
    }

    public static function invalidVehicleAtDispatchProvider(): array
    {
        return [
            'inactive vehicle' => ['is_active', false],
            'expired SOAT' => ['soat_expires_at', 'yesterday'],
            'expired technical inspection' => ['technical_inspection_expires_at', 'yesterday'],
        ];
    }

    private function driverData(array $extra = []): array
    {
        return array_merge(['code' => 'DRV-001', 'first_name' => 'Ana', 'last_name' => 'Reparto', 'document_type' => 'DNI', 'document_number' => '12345678', 'phone' => '999111222', 'license_number' => 'Q12345678', 'license_expires_at' => today()->addYear()->toDateString(), 'is_active' => true, 'is_available' => true], $extra);
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

    private function initializeOwnDelivery(Order $order): void
    {
        $this->postJson("/api/v1/admin/orders/{$order->id}/delivery/initialize")->assertCreated();
        $this->patchJson("/api/v1/admin/orders/{$order->id}/delivery/method", ['method' => OrderDelivery::OWN_DELIVERY])->assertOk();
    }

    private function assignUrl(Order $order): string
    {
        return "/api/v1/admin/orders/{$order->id}/delivery/assign-driver";
    }

    private function dispatchUrl(Order $order): string
    {
        return "/api/v1/admin/orders/{$order->id}/delivery/dispatch";
    }
}
