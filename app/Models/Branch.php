<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'description', 'address', 'district', 'province', 'department',
        'reference', 'phone', 'email', 'business_hours', 'pickup_instructions',
        'allows_pickup', 'serves_public', 'is_main', 'is_active',
    ];

    protected $hidden = ['main_guard'];

    protected $casts = [
        'allows_pickup' => 'boolean', 'serves_public' => 'boolean',
        'is_main' => 'boolean', 'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Branch $branch) {
            $branch->main_guard = $branch->is_main ? 1 : null;
        });
    }

    public function warehouses()
    {
        return $this->hasMany(Warehouse::class);
    }
}
