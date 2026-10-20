<?php

namespace Tests\Feature;

use App\Models\DeliveryDriver;
use App\Models\DeliveryVehicle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeliveryFleetCivilDatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_actual_frontend_edit_and_save_functions_preserve_civil_dates(): void
    {
        $process = new Process(['node', '--test', base_path('tests/frontend/deliveryCivilDates.test.mjs')], base_path());
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Civil date frontend contracts must pass. '.$process->getOutput().$process->getErrorOutput());
    }

    /** @dataProvider fleetRecords */
    public function test_existing_api_serializes_date_casts_and_round_trips_normalized_form_values(string $resource, array $fields, array $payload): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        foreach ($fields as $field) {
            $payload[$field] = '2028-02-29';
        }
        $base = '/api/v1/admin/'.$resource;
        $created = $this->postJson($base, $payload)->assertCreated();
        $id = $created->json('id');
        $listed = $this->getJson($base)->assertOk()->json('data.0');
        $detail = $this->getJson($base.'/'.$id)->assertOk()->json();
        foreach ($fields as $field) {
            foreach ([$created->json(), $listed, $detail] as $record) {
                $this->assertMatchesRegularExpression('/^2028-02-29T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $record[$field]);
            }
        }
        $this->putJson($base.'/'.$id, $payload)->assertOk();
        $table = str_replace('-', '_', $resource);
        $model = $resource === 'delivery-vehicles' ? DeliveryVehicle::class : DeliveryDriver::class;
        $persisted = $model::findOrFail($id);
        foreach ($fields as $field) {
            $this->assertSame('2028-02-29', $persisted->$field->toDateString());
        }
        $payload[$fields[0]] = '2030-12-31';
        $payload['code'] = 'DATE-EDITED';
        $this->putJson($base.'/'.$id, $payload)->assertOk();
        $this->assertDatabaseHas($table, ['id' => $id, 'code' => 'DATE-EDITED']);
        $this->assertSame('2030-12-31', $model::findOrFail($id)->{$fields[0]}->toDateString());
        foreach ($fields as $field) {
            $payload[$field] = '';
        }
        $this->putJson($base.'/'.$id, $payload)->assertOk();
        $this->assertDatabaseHas($table, ['id' => $id, ...array_fill_keys($fields, null)]);
        $nullRecord = $this->getJson($base.'/'.$id)->assertOk()->json();
        foreach ($fields as $field) {
            $this->assertNull($nullRecord[$field]);
        }
        $payload[$fields[0]] = 'not-a-date';
        $this->putJson($base.'/'.$id, $payload)->assertUnprocessable()->assertJsonValidationErrors($fields[0]);
    }

    /** @dataProvider fleetRecords */
    public function test_omitting_each_date_on_update_preserves_it_while_other_fields_change(string $resource, array $fields, array $payload): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        foreach ($fields as $field) {
            $payload[$field] = '2028-02-29';
        }
        $base = '/api/v1/admin/'.$resource;
        $id = $this->postJson($base, $payload)->assertCreated()->json('id');
        $model = $resource === 'delivery-vehicles' ? DeliveryVehicle::class : DeliveryDriver::class;
        foreach ($fields as $omitted) {
            $before = $model::findOrFail($id)->$omitted->toDateString();
            $update = $payload;
            unset($update[$omitted]);
            $update['code'] = 'OMITTED-DATE';
            foreach (array_diff($fields, [$omitted]) as $other) {
                $update[$other] = '2030-12-31';
            }
            $this->putJson($base.'/'.$id, $update)->assertOk();
            $persisted = $model::findOrFail($id);
            $this->assertSame($before, $persisted->$omitted->toDateString());
            $this->assertSame('OMITTED-DATE', $persisted->code);
            foreach (array_diff($fields, [$omitted]) as $other) {
                $this->assertSame('2030-12-31', $persisted->$other->toDateString());
            }
        }
    }

    public static function fleetRecords(): array
    {
        return [
            'vehicle dates' => ['delivery-vehicles', ['soat_expires_at', 'technical_inspection_expires_at'], ['code' => 'DATE-VEHICLE', 'vehicle_type' => 'van', 'ownership_type' => 'company']],
            'driver date' => ['delivery-drivers', ['license_expires_at'], ['code' => 'DATE-DRIVER', 'first_name' => 'Fixture', 'last_name' => 'Test', 'phone' => '000000000']],
        ];
    }
}
