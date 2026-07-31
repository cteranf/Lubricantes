<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class WarehouseInventory extends Model
{
    use HasFactory;
    protected $fillable = ['warehouse_id','product_id','quantity','reserved_quantity'];
    protected $casts = ['quantity'=>'integer','reserved_quantity'=>'integer'];
    protected $appends = ['available_quantity'];
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function getAvailableQuantityAttribute(): int { return max(0, $this->quantity - $this->reserved_quantity); }
}
