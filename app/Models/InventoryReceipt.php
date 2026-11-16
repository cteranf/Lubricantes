<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryReceipt extends Model
{
    public const DRAFT = 'draft';

    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::CONFIRMED, self::CANCELLED];

    protected $guarded = ['*'];

    protected $casts = [
        'version' => 'integer',
        'confirmed_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryReceiptLine::class)->orderBy('sort_order')->orderBy('id');
    }
}
