<?php

namespace App\Services;

use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\UserAddress;

class ShippingRateService
{
    public function __construct(private TerritoryNormalizer $normalizer) {}

    public function quote(UserAddress $address, bool $lockForUpdate = false): array
    {
        $query = ShippingRate::query()->where('is_active', true);
        if ($address->district_id) {
            $query->where('district_id', $address->district_id)
                ->whereHas('districtRelation', fn ($district) => $district->where('is_active', true)
                    ->whereHas('province', fn ($province) => $province->where('is_active', true)
                        ->whereHas('department', fn ($department) => $department->where('is_active', true))));
        } elseif ($address->ubigeo) {
            $query->where('ubigeo', $address->ubigeo);
        } else {
            $query->where('normalized_department', $address->normalized_department)
                ->where('normalized_province', $address->normalized_province)
                ->where('normalized_district', $address->normalized_district);
        }
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $rate = $query->first();
        if (! $rate) {
            return $this->withoutCoverage($address);
        }

        $zoneQuery = ShippingZone::whereKey($rate->shipping_zone_id)->where('is_active', true);
        if ($lockForUpdate) {
            $zoneQuery->lockForUpdate();
        }
        $zone = $zoneQuery->first();
        if (! $zone) {
            return $this->withoutCoverage($address);
        }

        $minimum = $rate->estimated_days_min ?? $zone->estimated_days_min;
        $maximum = $rate->estimated_days_max ?? $zone->estimated_days_max;

        return [
            'has_coverage' => true,
            'shipping_amount' => $rate->amount,
            'currency' => 'PEN',
            'estimated_days_min' => $minimum,
            'estimated_days_max' => $maximum,
            'zone_name' => $zone->name,
            'district' => $address->district,
            'message' => 'Envío disponible para la dirección seleccionada.',
            'rate' => $rate,
            'zone' => $zone,
        ];
    }

    public function activeDistrictKey(string $department, string $province, string $district): string
    {
        return $this->normalizer->identityKey($department, $province, $district);
    }

    private function withoutCoverage(UserAddress $address): array
    {
        return [
            'has_coverage' => false,
            'shipping_amount' => null,
            'currency' => 'PEN',
            'estimated_days_min' => null,
            'estimated_days_max' => null,
            'zone_name' => null,
            'district' => $address->district,
            'message' => 'Por el momento no realizamos envíos a este distrito. Puedes elegir otra dirección o recoger tu pedido en una sede disponible.',
            'rate' => null,
            'zone' => null,
        ];
    }
}
