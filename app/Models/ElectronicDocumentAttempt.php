<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicDocumentAttempt extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'request_snapshot' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
