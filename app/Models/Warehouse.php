<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    use HasFactory;

    public const INITIAL_CODE = 'ALM-PRINCIPAL';

    protected $fillable = ['branch_id', 'code', 'name', 'description', 'address', 'is_default', 'is_active'];

    protected $hidden = ['default_guard'];

    protected $casts = ['is_default' => 'boolean', 'is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (Warehouse $warehouse) {
            $warehouse->default_guard = $warehouse->is_default ? 1 : null;
        });
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function inventories()
    {
        return $this->hasMany(WarehouseInventory::class);
    }

    public function movements()
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
