<?php

namespace App\Models;

use App\Services\TerritoryNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class District extends Model
{
    use HasFactory;

    protected $fillable = ['province_id', 'code', 'ubigeo', 'name', 'is_active'];

    protected $hidden = ['normalized_name'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (self $district) {
            $district->code = strtoupper(trim($district->code));
            $district->ubigeo = trim((string) $district->ubigeo) ?: null;
            $district->name = trim(preg_replace('/\s+/u', ' ', $district->name));
            $district->normalized_name = app(TerritoryNormalizer::class)->normalize($district->name);
        });
    }

    public function province()
    {
        return $this->belongsTo(Province::class);
    }

    public function shippingRates()
    {
        return $this->hasMany(ShippingRate::class);
    }
}
