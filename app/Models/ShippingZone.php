<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingZone extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'description', 'estimated_days_min', 'estimated_days_max', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'estimated_days_min' => 'integer', 'estimated_days_max' => 'integer'];

    public function rates()
    {
        return $this->hasMany(ShippingRate::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
