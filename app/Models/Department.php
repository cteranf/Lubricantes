<?php

namespace App\Models;

use App\Services\TerritoryNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'is_active'];

    protected $hidden = ['normalized_name'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (self $department) {
            $department->code = strtoupper(trim($department->code));
            $department->name = trim(preg_replace('/\s+/u', ' ', $department->name));
            $department->normalized_name = app(TerritoryNormalizer::class)->normalize($department->name);
        });
    }

    public function provinces()
    {
        return $this->hasMany(Province::class);
    }

    public function districts()
    {
        return $this->hasManyThrough(District::class, Province::class);
    }
}
