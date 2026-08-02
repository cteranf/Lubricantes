<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderHandlingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_handling_process_id', 'order_item_id', 'product_id', 'warehouse_id',
        'product_name', 'product_sku', 'product_presentation', 'warehouse_name',
        'ordered_quantity', 'picked_quantity', 'packed_quantity', 'confirmation_method',
        'scanned_code', 'confirmation_metadata', 'last_operated_by', 'last_operated_at', 'observation',
    ];

    protected $casts = [
        'ordered_quantity'=>'integer', 'picked_quantity'=>'integer', 'packed_quantity'=>'integer',
        'confirmation_metadata'=>'array', 'last_operated_at'=>'datetime',
    ];

    public function process() { return $this->belongsTo(OrderHandlingProcess::class, 'order_handling_process_id'); }
    public function orderItem() { return $this->belongsTo(OrderItem::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function lastOperatedBy() { return $this->belongsTo(User::class, 'last_operated_by'); }
}
