<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryVehicle extends Model
{
    public const TYPES = ['motorcycle', 'car', 'van', 'truck', 'bicycle', 'other'];

    public const OWNERSHIP_TYPES = ['company', 'driver', 'third_party'];

    protected $fillable = ['code', 'plate_number', 'vehicle_type', 'brand', 'model', 'year', 'color', 'load_capacity_kg', 'ownership_type', 'soat_expires_at', 'technical_inspection_expires_at', 'notes', 'is_active', 'is_available'];

    protected $casts = ['year' => 'integer', 'load_capacity_kg' => 'decimal:2', 'soat_expires_at' => 'date', 'technical_inspection_expires_at' => 'date', 'is_active' => 'boolean', 'is_available' => 'boolean'];

    public function deliveries()
    {
        return $this->hasMany(OrderDelivery::class, 'vehicle_id');
    }

    public function getDescriptionAttribute(): string
    {
        return trim(implode(' ', array_filter([$this->brand, $this->model])));
    }

    public function hasExpiredDocuments(): bool
    {
        return ($this->soat_expires_at && $this->soat_expires_at->isPast()) || ($this->technical_inspection_expires_at && $this->technical_inspection_expires_at->isPast());
    }
}
