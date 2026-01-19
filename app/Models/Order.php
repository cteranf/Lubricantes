<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
        'total',
        'shipping_info',
        'payment_method',
        'payment_id',
        'payment_status',
        'payment_data',
        'shipping_method',
        'notes',
        'delivery_type',
        'tracking_status',
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
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get tracking timeline based on delivery type and current status
     */
    public function getTrackingTimeline()
    {
        $statuses = $this->delivery_type === 'delivery'
            ? ['pending', 'confirmed', 'processing', 'shipped', 'delivered']
            : ['pending', 'confirmed', 'ready_for_pickup', 'picked_up'];

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
