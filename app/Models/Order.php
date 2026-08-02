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
        'tracking_status',
        'fulfillment_status',
        'delivery_flow_version',
        'preparing_at',
        'ready_at',
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
        'estimated_delivery_date' => 'date',
        'delivered_at' => 'datetime',
        'reserved_until' => 'datetime',
        'paid_at' => 'datetime',
        'preparing_at' => 'datetime',
        'ready_at' => 'datetime',
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

    public function fulfillmentHistory() { return $this->hasMany(OrderFulfillmentHistory::class)->orderBy('created_at'); }
    public function handlingProcess() { return $this->hasOne(OrderHandlingProcess::class); }
    public function handlingIncidents() { return $this->hasMany(OrderHandlingIncident::class); }
    public function handlingHistory() { return $this->hasMany(OrderHandlingHistory::class)->orderBy('created_at'); }
    public function delivery() { return $this->hasOne(OrderDelivery::class); }
    public function paymentTransactions() { return $this->hasMany(PaymentTransaction::class); }
    public function preparedBy() { return $this->belongsTo(User::class, 'prepared_by'); }
    public function readyBy() { return $this->belongsTo(User::class, 'ready_by'); }
    public function deliveredBy() { return $this->belongsTo(User::class, 'delivered_by'); }

    public function effectiveFulfillmentStatus(): string
    {
        if ($this->fulfillment_status) return $this->fulfillment_status;
        if (in_array($this->status, ['canceled','rejected'], true)) return self::FULFILLMENT_CANCELED;
        if ($this->status === 'delivered') return self::FULFILLMENT_DELIVERED;
        return self::FULFILLMENT_RESERVED;
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
        return $this->delivery_type === 'delivery'
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
