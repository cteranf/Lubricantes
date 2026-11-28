<?php

namespace App\Models;

use App\Models\Builders\ImmutablePosBuilder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class PosCashSession extends Model
{
    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $fillable = [
        'register_id', 'cashier_user_id', 'status', 'opening_cash', 'opened_at', 'opening_idempotency_key',
        'opening_request_hash', 'closed_by', 'closed_at', 'expected_cash', 'counted_cash', 'difference',
        'closing_reason', 'closing_idempotency_key', 'closing_request_hash',
    ];

    protected $casts = [
        'opening_cash' => 'decimal:2', 'expected_cash' => 'decimal:2', 'counted_cash' => 'decimal:2',
        'difference' => 'decimal:2', 'opened_at' => 'datetime', 'closed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (PosCashSession $session): void {
            if ($session->getOriginal('status') === self::CLOSED) {
                throw new LogicException('Una sesión POS cerrada es inmutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Las sesiones POS no se eliminan.'));
    }

    public function newEloquentBuilder($query): ImmutablePosBuilder
    {
        return new ImmutablePosBuilder($query);
    }

    public function register()
    {
        return $this->belongsTo(PosRegister::class, 'register_id');
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function movements()
    {
        return $this->hasMany(PosCashMovement::class, 'session_id');
    }

    public function auditEvents()
    {
        return $this->hasMany(PosAuditEvent::class, 'session_id');
    }
}
