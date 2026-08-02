<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderDeliveryAttempt extends Model
{
    public const SCHEDULED='scheduled', IN_PROGRESS='in_progress', DELIVERED='delivered', FAILED='failed', CANCELED='canceled';
    protected $guarded = [];
    protected $casts = ['scheduled_at'=>'datetime','started_at'=>'datetime','finished_at'=>'datetime','reported_at'=>'datetime','metadata'=>'array'];
    public function delivery(){ return $this->belongsTo(OrderDelivery::class, 'order_delivery_id'); }
    public function responsible(){ return $this->belongsTo(User::class, 'responsible_user_id'); }
    public function reporter(){ return $this->belongsTo(User::class, 'reported_by'); }
}
