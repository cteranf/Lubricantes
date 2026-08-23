<?php

namespace Tests\Concerns;

use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Models\UserAddress;

trait CreatesShippingCoverage
{
    protected function withShippingCoverage(array $payload, ?User $user = null): array
    {
        $user ??= auth()->user();
        $zone = ShippingZone::firstOrCreate(
            ['code' => 'TEST-COVERAGE'],
            ['name' => 'Cobertura de pruebas', 'estimated_days_min' => 1, 'estimated_days_max' => 2, 'is_active' => true],
        );
        ShippingRate::firstOrCreate(
            ['active_district_key' => app(\App\Services\TerritoryNormalizer::class)->identityKey('Lima', 'Lima', 'Distrito de pruebas')],
            ['shipping_zone_id' => $zone->id, 'department' => 'Lima', 'province' => 'Lima', 'district' => 'Distrito de pruebas', 'amount' => '0.00', 'is_active' => true],
        );
        $address = UserAddress::firstOrCreate(
            ['user_id' => $user->id, 'address' => $payload['shipping_info']['address'] ?? 'Av. de pruebas 123'],
            ['recipient_name' => $user->name, 'phone' => '999999999', 'department' => 'Lima', 'province' => 'Lima', 'district' => 'Distrito de pruebas', 'is_active' => true],
        );
        $payload['address_id'] = $address->id;

        return $payload;
    }
}
