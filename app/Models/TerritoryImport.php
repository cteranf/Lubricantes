<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TerritoryImport extends Model
{
    use HasFactory;

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'user_id', 'original_filename', 'checksum', 'status', 'total_rows',
        'created_departments', 'created_provinces', 'created_districts',
        'unchanged_rows', 'conflict_rows', 'error_rows', 'summary',
        'started_at', 'completed_at',
    ];

    protected $hidden = ['checksum'];

    protected $casts = ['summary' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
