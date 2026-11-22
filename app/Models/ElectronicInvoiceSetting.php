<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicInvoiceSetting extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'sunat_username_encrypted' => 'encrypted',
        'sunat_password_encrypted' => 'encrypted',
        'certificate_password_encrypted' => 'encrypted',
        'certificate_expires_at' => 'datetime',
    ];
}
