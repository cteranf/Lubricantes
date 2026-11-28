<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PosRegister extends Model
{
    protected $fillable = ['warehouse_id', 'code', 'name', 'is_active', 'created_by'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Las cajas POS no se eliminan; deben desactivarse.'));
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function assignments()
    {
        return $this->hasMany(PosCashierAssignment::class, 'register_id');
    }

    public function sessions()
    {
        return $this->hasMany(PosCashSession::class, 'register_id');
    }

    public function auditEvents()
    {
        return $this->hasMany(PosAuditEvent::class, 'register_id');
    }
}
