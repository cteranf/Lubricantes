<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PosCashierAssignment extends Model
{
    protected $fillable = ['register_id', 'user_id', 'assigned_by', 'is_active', 'assigned_at', 'revoked_at'];

    protected $casts = ['is_active' => 'boolean', 'assigned_at' => 'datetime', 'revoked_at' => 'datetime'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Las asignaciones POS se conservan como historial.'));
    }

    public function register()
    {
        return $this->belongsTo(PosRegister::class, 'register_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
