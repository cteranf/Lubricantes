<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class PaymentReceivingAccount extends Model
{
    public const CHANNELS = ['yape', 'plin', 'bank_transfer'];

    protected $fillable = ['code', 'channel', 'display_name', 'holder_name', 'currency', 'phone', 'bank_name', 'account_number', 'cci', 'qr_path', 'qr_disk', 'is_active', 'is_default', 'sort_order', 'created_by', 'updated_by'];

    protected $hidden = ['phone', 'account_number', 'cci', 'qr_path', 'qr_disk', 'active_default_channel'];

    protected $casts = ['phone' => 'encrypted', 'account_number' => 'encrypted', 'cci' => 'encrypted', 'is_active' => 'boolean', 'is_default' => 'boolean', 'sort_order' => 'integer'];

    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            $account->active_default_channel = $account->is_active && $account->is_default ? $account->channel : null;
        });
        static::updating(function (self $account): void {
            if ($account->isDirty('code')) {
                throw new InvalidArgumentException('El código de cuenta receptora es inmutable.');
            }
            if ($account->isDirty('channel')) {
                throw new InvalidArgumentException('El canal de cuenta receptora es inmutable.');
            }
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
