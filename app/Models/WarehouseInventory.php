<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class WarehouseInventory extends Model
{
    use HasFactory;
    protected $fillable = ['warehouse_id','product_id','quantity'];
    protected $casts = ['quantity'=>'integer'];
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function getAvailableQuantityAttribute(): int { return $this->quantity; }
}
