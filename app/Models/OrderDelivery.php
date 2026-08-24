<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderDelivery extends Model
{
    public const STORE_PICKUP = 'store_pickup';

    public const OWN_DELIVERY = 'own_delivery';

    public const EXTERNAL_COURIER = 'external_courier';

    public const METHODS = [self::STORE_PICKUP, self::OWN_DELIVERY, self::EXTERNAL_COURIER];

    public const PENDING = 'pending';

    public const SCHEDULED = 'scheduled';

    public const ASSIGNED = 'assigned';

    public const DISPATCHED = 'dispatched';

    public const OUT_FOR_DELIVERY = 'out_for_delivery';

    public const AWAITING_PICKUP = 'awaiting_pickup';

    public const DELIVERED = 'delivered';

    public const FAILED_ATTEMPT = 'failed_attempt';

    public const RESCHEDULED = 'rescheduled';

    public const CANCELED = 'canceled';

    protected $guarded = [];

    protected $casts = ['scheduled_at' => 'datetime', 'started_at' => 'datetime', 'dispatched_at' => 'datetime', 'out_for_delivery_at' => 'datetime', 'delivered_at' => 'datetime', 'canceled_at' => 'datetime', 'picked_up_at' => 'datetime', 'assigned_at' => 'datetime', 'handed_to_courier_at' => 'datetime', 'last_synced_at' => 'datetime', 'confirmed_at' => 'datetime', 'destination_metadata' => 'array', 'provider_metadata' => 'array', 'courier_cost' => 'decimal:2'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function pickupWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'pickup_warehouse_id');
    }

    public function deliveryUser()
    {
        return $this->belongsTo(User::class, 'delivery_user_id');
    }

    public function driver()
    {
        return $this->belongsTo(DeliveryDriver::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(DeliveryVehicle::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function attempts()
    {
        return $this->hasMany(OrderDeliveryAttempt::class)->orderBy('attempt_number');
    }

    public function history()
    {
        return $this->hasMany(OrderDeliveryHistory::class)->orderBy('created_at');
    }
}
