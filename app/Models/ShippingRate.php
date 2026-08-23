<?php

namespace App\Models;

use App\Services\TerritoryNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipping_zone_id', 'district_id', 'department', 'province', 'district', 'ubigeo', 'amount',
        'estimated_days_min', 'estimated_days_max', 'is_active',
    ];

    protected $hidden = ['normalized_department', 'normalized_province', 'normalized_district', 'active_district_key', 'active_ubigeo_key', 'active_district_id'];

    protected $casts = [
        'amount' => 'decimal:2', 'is_active' => 'boolean',
        'estimated_days_min' => 'integer', 'estimated_days_max' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $rate) {
            if ($rate->district_id) {
                $district = District::with('province.department')->findOrFail($rate->district_id);
                $rate->department = $district->province->department->name;
                $rate->province = $district->province->name;
                $rate->district = $district->name;
                $rate->ubigeo = $district->ubigeo;
            }
            $normalizer = app(TerritoryNormalizer::class);
            $rate->normalized_department = $normalizer->normalize($rate->department);
            $rate->normalized_province = $normalizer->normalize($rate->province);
            $rate->normalized_district = $normalizer->normalize($rate->district);
            $rate->ubigeo = trim((string) $rate->ubigeo) ?: null;
            $rate->active_district_key = $rate->is_active
                ? $normalizer->identityKey($rate->department, $rate->province, $rate->district)
                : null;
            $rate->active_ubigeo_key = $rate->is_active ? $normalizer->ubigeoIdentityKey($rate->ubigeo) : null;
            $rate->active_district_id = $rate->is_active ? $rate->district_id : null;
        });
    }

    public function zone()
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function districtRelation()
    {
        return $this->belongsTo(District::class, 'district_id');
    }
}
