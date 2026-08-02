<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OrderDeliveryHistory extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['metadata'=>'array','created_at'=>'datetime'];
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial de despacho es inmutable.'));
        static::deleting(fn () => throw new LogicException('El historial de despacho no se puede eliminar.'));
    }
    public function delivery(){ return $this->belongsTo(OrderDelivery::class, 'order_delivery_id'); }
    public function attempt(){ return $this->belongsTo(OrderDeliveryAttempt::class, 'order_delivery_attempt_id'); }
    public function user(){ return $this->belongsTo(User::class); }
}
