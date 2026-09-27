<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PaymentResolutionHistory extends Model
{
    public const SAFE_METADATA_KEYS = [
        'resolution_type',
        'currency',
        'amount',
        'reservation_count',
    ];

    public const EVENTS = [
        'opened',
        'funds_confirmed',
        'reassignment_started',
        'reassigned',
        'refund_marked_pending',
        'refund_completed',
        'refund_failed',
    ];

    public const UPDATED_AT = null;

    protected $fillable = [
        'payment_resolution_case_id',
        'event',
        'from_status',
        'to_status',
        'actor_id',
        'reason',
        'safe_metadata',
        'occurred_at',
        'created_at',
    ];

    protected $casts = [
        'safe_metadata' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $history): void {
            if ($history->safe_metadata !== null && ! is_array($history->safe_metadata)) {
                throw new LogicException('La metadata segura debe ser un arreglo.');
            }

            if (is_array($history->safe_metadata)) {
                $history->safe_metadata = array_intersect_key(
                    $history->safe_metadata,
                    array_flip(self::SAFE_METADATA_KEYS)
                );
            }
        });
        static::updating(fn () => throw new LogicException('El historial de resolución es inmutable.'));
        static::deleting(fn () => throw new LogicException('El historial de resolución no se puede eliminar.'));
    }

    public function resolutionCase()
    {
        return $this->belongsTo(PaymentResolutionCase::class, 'payment_resolution_case_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
