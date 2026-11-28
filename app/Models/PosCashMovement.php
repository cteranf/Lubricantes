<?php

namespace App\Models;

use App\Models\Builders\ImmutablePosBuilder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class PosCashMovement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'session_id', 'actor_user_id', 'order_id', 'payment_transaction_id', 'kind', 'cash_received',
        'change_given', 'net_amount', 'idempotency_key', 'created_at',
    ];

    protected $casts = [
        'cash_received' => 'decimal:2', 'change_given' => 'decimal:2',
        'net_amount' => 'decimal:2', 'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Los movimientos de caja POS son inmutables.'));
        static::deleting(fn () => throw new LogicException('Los movimientos de caja POS no se eliminan.'));
    }

    public function newEloquentBuilder($query): ImmutablePosBuilder
    {
        return new ImmutablePosBuilder($query);
    }

    public function session()
    {
        return $this->belongsTo(PosCashSession::class, 'session_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
