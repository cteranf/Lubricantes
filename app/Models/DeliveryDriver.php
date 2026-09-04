<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryDriver extends Model
{
    protected $fillable = ['code', 'user_id', 'first_name', 'last_name', 'document_type', 'document_number', 'phone', 'email', 'license_number', 'license_category', 'license_expires_at', 'emergency_contact_name', 'emergency_contact_phone', 'notes', 'is_active', 'is_available'];

    protected $casts = ['license_expires_at' => 'date', 'is_active' => 'boolean', 'is_available' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries()
    {
        return $this->hasMany(OrderDelivery::class, 'driver_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function hasExpiredLicense(): bool
    {
        return $this->license_expires_at !== null
            && $this->license_expires_at->isBefore(today());
    }

    public function hasLicenseCredentials(): bool
    {
        return filled($this->license_number) && $this->license_expires_at !== null;
    }
}
