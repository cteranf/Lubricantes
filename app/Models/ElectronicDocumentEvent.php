<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicDocumentEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['changed_fields' => 'array', 'created_at' => 'datetime'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
