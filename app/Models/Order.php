<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    public const FULFILLMENT_RESERVED = 'reserved';

    public const FULFILLMENT_PREPARING = 'preparing';

    public const FULFILLMENT_READY = 'ready';

    public const FULFILLMENT_DELIVERED = 'delivered';

    public const FULFILLMENT_CANCELED = 'canceled';

    public const DELIVERY_TRACKING_FLOW = [
        'pending',
        'confirmed',
        'processing',
        'shipped',
        'delivered',
    ];

    public const PICKUP_TRACKING_FLOW = [
        'pending',
        'confirmed',
        'ready_for_pickup',
        'picked_up',
    ];

    public const TERMINAL_TRACKING_STATUSES = [
        'delivered',
        'picked_up',
        'canceled',
    ];

    protected $fillable = [
        'user_id',
        'status',
        'total',
        'subtotal',
        'discount_total',
        'shipping_amount',
        'shipping_address_id',
        'shipping_zone_id',
        'shipping_rate_id',
        'shipping_zone_code_snapshot',
        'shipping_zone_name_snapshot',
        'shipping_department_snapshot',
        'shipping_province_snapshot',
        'shipping_district_snapshot',
        'shipping_ubigeo_snapshot',
        'shipping_estimated_days_min_snapshot',
        'shipping_estimated_days_max_snapshot',
        'checkout_token',
        'shipping_info',
        'payment_method',
        'payment_id',
        'payment_status',
        'payment_data',
        'reserved_until',
        'paid_at',
        'shipping_method',
        'notes',
        'delivery_type',
        'pickup_branch_id',
        'pickup_branch_code_snapshot',
        'pickup_branch_name_snapshot',
        'pickup_address_snapshot',
        'pickup_district_snapshot',
        'pickup_business_hours_snapshot',
        'pickup_instructions_snapshot',
        'tracking_status',
        'fulfillment_status',
        'delivery_flow_version',
        'preparing_at',
        'ready_at',
        'ready_for_pickup_at',
        'pickup_deadline_at',
        'picked_up_at',
        'prepared_by',
        'ready_by',
        'delivered_by',
        'tracking_notes',
        'estimated_delivery_date',
        'delivered_at',
    ];

    protected $casts = [
        'shipping_info' => 'array',
        'payment_data' => 'array',
        'total' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'estimated_delivery_date' => 'date',
        'delivered_at' => 'datetime',
        'reserved_until' => 'datetime',
        'paid_at' => 'datetime',
        'preparing_at' => 'datetime',
        'ready_at' => 'datetime',
        'ready_for_pickup_at' => 'datetime',
        'pickup_deadline_at' => 'datetime',
        'picked_up_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function reservations()
    {
        return $this->hasMany(InventoryReservation::class);
    }

    public function fulfillmentHistory()
    {
        return $this->hasMany(OrderFulfillmentHistory::class)->orderBy('created_at');
    }

    public function handlingProcess()
    {
        return $this->hasOne(OrderHandlingProcess::class);
    }

    public function handlingIncidents()
    {
        return $this->hasMany(OrderHandlingIncident::class);
    }

    public function handlingHistory()
    {
        return $this->hasMany(OrderHandlingHistory::class)->orderBy('created_at');
    }

    public function delivery()
    {
        return $this->hasOne(OrderDelivery::class);
    }

    public function paymentTransactions()
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function pickupBranch()
    {
        return $this->belongsTo(Branch::class, 'pickup_branch_id');
    }

    public function shippingAddress()
    {
        return $this->belongsTo(UserAddress::class, 'shipping_address_id');
    }

    public function shippingZone()
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    public function shippingRate()
    {
        return $this->belongsTo(ShippingRate::class, 'shipping_rate_id');
    }

    public function preparedBy()
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function readyBy()
    {
        return $this->belongsTo(User::class, 'ready_by');
    }

    public function deliveredBy()
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function effectiveFulfillmentStatus(): string
    {
        if ($this->fulfillment_status) {
            return $this->fulfillment_status;
        }
        if (in_array($this->status, ['canceled', 'rejected'], true)) {
            return self::FULFILLMENT_CANCELED;
        }
        if ($this->status === 'delivered') {
            return self::FULFILLMENT_DELIVERED;
        }

        return self::FULFILLMENT_RESERVED;
    }

    public function isPickup(): bool
    {
        return $this->delivery_type === 'pickup';
    }

    public function pickupDeadlineStatus(): string
    {
        if (! $this->isPickup()) {
            return 'not_applicable';
        }
        if ($this->picked_up_at || $this->tracking_status === 'picked_up') {
            return 'picked_up';
        }
        if (! $this->ready_for_pickup_at || ! $this->pickup_deadline_at) {
            return 'preparing';
        }

        return $this->pickup_deadline_at->isPast() ? 'expired' : 'within_deadline';
    }

    /**
     * Get tracking timeline based on delivery type and current status
     */
    public function getTrackingTimeline()
    {
        $statuses = $this->trackingFlow();

        $timeline = [];
        $currentIndex = array_search($this->tracking_status, $statuses);

        foreach ($statuses as $index => $status) {
            $timeline[] = [
                'status' => $status,
                'label' => $this->getStatusLabel($status),
                'completed' => $index <= $currentIndex && $this->tracking_status !== 'canceled',
                'active' => $index === $currentIndex,
                'icon' => $this->getStatusIcon($status),
            ];
        }

        return $timeline;
    }

    public function trackingFlow(): array
    {
        return ! $this->isPickup()
            ? self::DELIVERY_TRACKING_FLOW
            : self::PICKUP_TRACKING_FLOW;
    }

    public function isTrackingTerminal(): bool
    {
        return in_array($this->tracking_status, self::TERMINAL_TRACKING_STATUSES, true);
    }

    private function getStatusLabel($status)
    {
        $labels = [
            'pending' => 'Pedido Recibido',
            'confirmed' => 'Pago Confirmado',
            'processing' => 'Preparando Pedido',
            'shipped' => 'En Camino',
            'delivered' => 'Entregado',
            'ready_for_pickup' => 'Listo para Recoger',
            'picked_up' => 'Recogido',
            'canceled' => 'Cancelado',
        ];

        return $labels[$status] ?? $status;
    }

    private function getStatusIcon($status)
    {
        $icons = [
            'pending' => 'pi pi-clock',
            'confirmed' => 'pi pi-check-circle',
            'processing' => 'pi pi-cog',
            'shipped' => 'pi pi-truck',
            'delivered' => 'pi pi-home',
            'ready_for_pickup' => 'pi pi-shopping-bag',
            'picked_up' => 'pi pi-check',
            'canceled' => 'pi pi-times-circle',
        ];

        return $icons[$status] ?? 'pi pi-circle';
    }
}
