<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderDelivery extends Model
{
    public const STORE_PICKUP='store_pickup', OWN_DELIVERY='own_delivery', EXTERNAL_COURIER='external_courier';
    public const METHODS=[self::STORE_PICKUP,self::OWN_DELIVERY,self::EXTERNAL_COURIER];
    public const PENDING='pending', SCHEDULED='scheduled', ASSIGNED='assigned', DISPATCHED='dispatched', OUT_FOR_DELIVERY='out_for_delivery', AWAITING_PICKUP='awaiting_pickup', DELIVERED='delivered', FAILED_ATTEMPT='failed_attempt', RESCHEDULED='rescheduled', CANCELED='canceled';

    protected $guarded=[];
    protected $casts=['scheduled_at'=>'datetime','started_at'=>'datetime','dispatched_at'=>'datetime','out_for_delivery_at'=>'datetime','delivered_at'=>'datetime','canceled_at'=>'datetime','picked_up_at'=>'datetime','assigned_at'=>'datetime','handed_to_courier_at'=>'datetime','last_synced_at'=>'datetime','confirmed_at'=>'datetime','destination_metadata'=>'array','provider_metadata'=>'array','courier_cost'=>'decimal:2'];

    public function order(){return $this->belongsTo(Order::class);}
    public function pickupWarehouse(){return $this->belongsTo(Warehouse::class,'pickup_warehouse_id');}
    public function deliveryUser(){return $this->belongsTo(User::class,'delivery_user_id');}
    public function creator(){return $this->belongsTo(User::class,'created_by');}
    public function updater(){return $this->belongsTo(User::class,'updated_by');}
    public function assigner(){return $this->belongsTo(User::class,'assigned_by');}
    public function confirmer(){return $this->belongsTo(User::class,'confirmed_by');}
    public function attempts(){return $this->hasMany(OrderDeliveryAttempt::class)->orderBy('attempt_number');}
    public function history(){return $this->hasMany(OrderDeliveryHistory::class)->orderBy('created_at');}
}
