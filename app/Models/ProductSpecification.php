<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSpecification extends Model
{
    protected $fillable = ['name', 'value', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
    ];
}
