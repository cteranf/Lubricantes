<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PaymentSubmissionHistory extends Model
{
    public const EVENTS = ['submitted', 'observed', 'corrected', 'approved', 'rejected', 'expired', 'canceled'];

    public const UPDATED_AT = null;

    protected $fillable = ['payment_submission_id', 'event', 'from_status', 'to_status', 'actor_id', 'reason', 'safe_metadata', 'occurred_at', 'created_at'];

    protected $casts = ['safe_metadata' => 'array', 'occurred_at' => 'datetime', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial de Tesorería es inmutable.'));
        static::deleting(fn () => throw new LogicException('El historial de Tesorería no se puede eliminar.'));
    }

    public function submission()
    {
        return $this->belongsTo(PaymentSubmission::class, 'payment_submission_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
