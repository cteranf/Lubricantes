<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderHandlingIncident extends Model
{
    use HasFactory;

    public const TYPES = ['missing', 'damaged', 'quantity_mismatch', 'wrong_product', 'other'];
    public const OPEN = 'open';
    public const RESOLVED = 'resolved';
    public const CANCELED = 'canceled';
    public const STATUSES = [self::OPEN, self::RESOLVED, self::CANCELED];

    protected $fillable = [
        'order_id', 'order_item_id', 'type', 'affected_quantity', 'description', 'status',
        'reported_by', 'reported_at', 'resolved_by', 'resolved_at', 'resolution_observation',
        'idempotency_key',
    ];

    protected $casts = [
        'affected_quantity'=>'integer', 'reported_at'=>'datetime', 'resolved_at'=>'datetime',
    ];

    public function order() { return $this->belongsTo(Order::class); }
    public function orderItem() { return $this->belongsTo(OrderItem::class); }
    public function reporter() { return $this->belongsTo(User::class, 'reported_by'); }
    public function resolver() { return $this->belongsTo(User::class, 'resolved_by'); }
}
