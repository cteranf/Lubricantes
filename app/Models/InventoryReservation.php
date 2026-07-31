<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryReservation extends Model
{
    use HasFactory;

    public const ACTIVE = 'active';
    public const CONSUMED = 'consumed';
    public const RELEASED = 'released';
    public const EXPIRED = 'expired';
    public const STATUSES = [self::ACTIVE, self::CONSUMED, self::RELEASED, self::EXPIRED];

    protected $fillable = ['order_id', 'order_item_id', 'product_id', 'warehouse_id', 'quantity', 'status', 'expires_at', 'consumed_at', 'released_at', 'idempotency_key', 'metadata'];
    protected $casts = ['quantity'=>'integer', 'expires_at'=>'datetime', 'consumed_at'=>'datetime', 'released_at'=>'datetime', 'metadata'=>'array'];

    public function order() { return $this->belongsTo(Order::class); }
    public function orderItem() { return $this->belongsTo(OrderItem::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
}
