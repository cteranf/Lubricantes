<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'warehouse_id',
        'quantity',
        'price',
        'regular_price',
        'promotion_id',
        'subtotal',
        'tax_snapshot',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    protected $casts = [
        'tax_snapshot' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function promotion()
    {
        return $this->belongsTo(ProductPromotion::class, 'promotion_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * All reservation versions for this item, from the original reservation
     * through any future reassignment versions.
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class)
            ->orderBy('reservation_sequence')
            ->orderBy('id');
    }

    /**
     * The reservation that currently represents this item.
     *
     * The id tie-breaker is defensive: the database makes a sequence unique
     * per item, but the relationship remains deterministic for legacy data.
     */
    public function currentReservation(): HasOne
    {
        return $this->hasOne(InventoryReservation::class)->ofMany([
            'reservation_sequence' => 'max',
            'id' => 'max',
        ]);
    }

    /**
     * Backwards-compatible relation name for existing read consumers.
     *
     * It deliberately aliases the current reservation rather than returning
     * an arbitrary historical first row, and remains eager-loadable.
     */
    public function reservation(): HasOne
    {
        return $this->currentReservation();
    }

    public function handlingItem()
    {
        return $this->hasOne(OrderHandlingItem::class);
    }
}
