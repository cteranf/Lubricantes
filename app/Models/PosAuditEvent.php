<?php

namespace App\Models;

use App\Models\Builders\ImmutablePosBuilder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class PosAuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['register_id', 'session_id', 'assignment_id', 'actor_user_id', 'event', 'before_values', 'after_values', 'created_at'];

    protected $casts = ['before_values' => 'array', 'after_values' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial POS es inmutable.'));
        static::deleting(fn () => throw new LogicException('El historial POS no se elimina.'));
    }

    public function newEloquentBuilder($query): ImmutablePosBuilder
    {
        return new ImmutablePosBuilder($query);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
