<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductReviewSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $fillable = ['show_reviews_publicly', 'accept_new_reviews', 'require_moderation'];

    protected $casts = [
        'show_reviews_publicly' => 'boolean',
        'accept_new_reviews' => 'boolean',
        'require_moderation' => 'boolean',
    ];
}
