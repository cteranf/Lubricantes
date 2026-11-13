<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ProductReview extends Model
{
    public const PENDING = 'pending';

    public const PUBLISHED = 'published';

    public const HIDDEN = 'hidden';

    public const REJECTED = 'rejected';

    public const STATUSES = [self::PENDING, self::PUBLISHED, self::HIDDEN, self::REJECTED];

    protected $fillable = ['rating', 'title', 'comment'];

    protected $hidden = ['verified_order_item_id', 'user_id', 'product_id', 'moderated_by', 'rejection_reason'];

    protected $casts = [
        'rating' => 'integer',
        'moderated_at' => 'datetime',
        'verified_purchase_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $review): void {
            if (! in_array($review->status, self::STATUSES, true)
                || (int) $review->rating < 1 || (int) $review->rating > 5
                || trim((string) $review->comment) === ''
                || mb_strlen((string) $review->comment) > 1000) {
                throw new InvalidArgumentException('La opinión no cumple el contrato permitido.');
            }
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function verifiedOrderItem()
    {
        return $this->belongsTo(OrderItem::class, 'verified_order_item_id');
    }

    public function moderator()
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }
}
