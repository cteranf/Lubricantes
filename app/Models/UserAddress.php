<?php

namespace App\Models;

use App\Services\TerritoryNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'label', 'recipient_name', 'phone', 'address', 'reference',
        'department_id', 'province_id', 'district_id',
        'department', 'province', 'district', 'ubigeo', 'is_default', 'is_active',
    ];

    protected $hidden = ['normalized_department', 'normalized_province', 'normalized_district'];

    protected $casts = ['is_default' => 'boolean', 'is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (self $address) {
            $normalizer = app(TerritoryNormalizer::class);
            $address->normalized_department = $normalizer->normalize($address->department);
            $address->normalized_province = $normalizer->normalize($address->province);
            $address->normalized_district = $normalizer->normalize($address->district);
            $address->ubigeo = trim((string) $address->ubigeo) ?: null;
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function departmentRelation()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function provinceRelation()
    {
        return $this->belongsTo(Province::class, 'province_id');
    }

    public function districtRelation()
    {
        return $this->belongsTo(District::class, 'district_id');
    }
}
