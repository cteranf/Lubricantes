<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OrderFulfillmentHistory extends Model
{
    public $timestamps = false;
    protected $fillable = ['order_id','from_status','to_status','user_id','observation','metadata','idempotency_key','created_at'];
    protected $casts = ['metadata'=>'array','created_at'=>'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial operativo es inmutable.'));
        static::deleting(fn () => throw new LogicException('El historial operativo no se puede eliminar.'));
    }

    public function order() { return $this->belongsTo(Order::class); }
    public function user() { return $this->belongsTo(User::class); }
}
