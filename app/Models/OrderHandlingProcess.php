<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderHandlingProcess extends Model
{
    use HasFactory;

    public const PENDING = 'pending';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED = 'completed';
    public const STATUSES = [self::PENDING, self::IN_PROGRESS, self::COMPLETED];

    protected $fillable = [
        'order_id', 'picking_status', 'picking_started_at', 'picking_completed_at',
        'picking_started_by', 'picking_completed_by', 'picking_observation',
        'packing_status', 'packing_started_at', 'packing_completed_at',
        'packing_started_by', 'packing_completed_by', 'packing_observation', 'canceled_at',
    ];

    protected $casts = [
        'picking_started_at'=>'datetime', 'picking_completed_at'=>'datetime',
        'packing_started_at'=>'datetime', 'packing_completed_at'=>'datetime', 'canceled_at'=>'datetime',
    ];

    public function order() { return $this->belongsTo(Order::class); }
    public function items() { return $this->hasMany(OrderHandlingItem::class); }
    public function pickingStartedBy() { return $this->belongsTo(User::class, 'picking_started_by'); }
    public function pickingCompletedBy() { return $this->belongsTo(User::class, 'picking_completed_by'); }
    public function packingStartedBy() { return $this->belongsTo(User::class, 'packing_started_by'); }
    public function packingCompletedBy() { return $this->belongsTo(User::class, 'packing_completed_by'); }
}
