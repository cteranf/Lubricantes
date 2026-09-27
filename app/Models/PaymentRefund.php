<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class PaymentRefund extends Model
{
    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const STATUSES = [self::PENDING, self::COMPLETED, self::FAILED];

    protected $fillable = [
        'payment_resolution_case_id',
        'amount',
        'currency',
        'status',
        'reason',
        'requested_by',
        'completed_by',
        'requested_at',
        'completed_at',
        'failed_at',
    ];

    protected $hidden = ['external_reference', 'idempotency_key'];

    protected $casts = [
        'amount' => 'decimal:2',
        'status' => 'string',
        'currency' => 'string',
        'external_reference' => 'encrypted',
        'requested_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $refund): void {
            $refund->status ??= self::PENDING;
            $refund->currency ??= 'PEN';

            if (! in_array($refund->status, self::STATUSES, true) || $refund->currency !== 'PEN') {
                throw new InvalidArgumentException('El estado o moneda de devolución no es válido.');
            }

            $case = $refund->relationLoaded('resolutionCase')
                ? $refund->resolutionCase
                : PaymentResolutionCase::find($refund->payment_resolution_case_id);

            if ($case && ((string) $refund->amount !== (string) $case->amount || $refund->currency !== $case->currency)) {
                throw new InvalidArgumentException('La devolución debe conservar el importe y moneda íntegros del caso de resolución.');
            }
        });
    }

    public function resolutionCase()
    {
        return $this->belongsTo(PaymentResolutionCase::class, 'payment_resolution_case_id');
    }

    public function refundPaymentTransaction()
    {
        return $this->belongsTo(PaymentTransaction::class, 'refund_payment_transaction_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
