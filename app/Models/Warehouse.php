<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class Warehouse extends Model
{
    use HasFactory;
    public const INITIAL_CODE = 'ALM-PRINCIPAL';
    protected $fillable = ['branch_id','code','name','description','address','is_default','is_active'];
    protected $casts = ['is_default'=>'boolean','is_active'=>'boolean'];
    public function branch() { return $this->belongsTo(Branch::class); }
    public function inventories() { return $this->hasMany(WarehouseInventory::class); }
    public function movements() { return $this->hasMany(InventoryMovement::class); }
}
