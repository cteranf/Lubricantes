<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentPreference extends Model
{
    public const CREATING = 'creating';

    public const ACTIVE = 'active';

    public const SUPERSEDED = 'superseded';

    public const FAILED = 'failed';

    public const ORPHANED = 'orphaned';

    protected $fillable = ['order_id', 'gateway', 'provider_preference_id', 'external_reference', 'idempotency_key', 'state', 'init_point', 'sandbox_init_point', 'error_code', 'error_message', 'creation_started_at', 'activated_at', 'superseded_at', 'failed_at'];

    protected $casts = ['creation_started_at' => 'datetime', 'activated_at' => 'datetime', 'superseded_at' => 'datetime', 'failed_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function transactions()
    {
        return $this->hasMany(PaymentTransaction::class);
    }
}
