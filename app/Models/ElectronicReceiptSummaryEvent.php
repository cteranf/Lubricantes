<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicReceiptSummaryEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];
}
