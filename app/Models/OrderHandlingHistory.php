<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OrderHandlingHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_id', 'order_item_id', 'incident_id', 'event_type', 'user_id',
        'confirmation_method', 'observation', 'metadata', 'idempotency_key', 'created_at',
    ];

    protected $casts = ['metadata'=>'array', 'created_at'=>'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial de picking y packing es inmutable.'));
        static::deleting(fn () => throw new LogicException('El historial de picking y packing no se puede eliminar.'));
    }

    public function order() { return $this->belongsTo(Order::class); }
    public function orderItem() { return $this->belongsTo(OrderItem::class); }
    public function incident() { return $this->belongsTo(OrderHandlingIncident::class, 'incident_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
