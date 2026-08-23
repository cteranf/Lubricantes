<?php

namespace App\Models;

use App\Services\TerritoryNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Province extends Model
{
    use HasFactory;

    protected $fillable = ['department_id', 'code', 'name', 'is_active'];

    protected $hidden = ['normalized_name'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (self $province) {
            $province->code = strtoupper(trim($province->code));
            $province->name = trim(preg_replace('/\s+/u', ' ', $province->name));
            $province->normalized_name = app(TerritoryNormalizer::class)->normalize($province->name);
        });
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function districts()
    {
        return $this->hasMany(District::class);
    }
}
