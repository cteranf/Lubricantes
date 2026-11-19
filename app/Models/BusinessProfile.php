<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessProfile extends Model
{
    public const SINGLETON_KEY = 'primary';

    protected $fillable = [
        'commercial_name', 'legal_name', 'tax_id_type', 'tax_id_number',
        'country_code', 'fiscal_address', 'contact_email', 'contact_phone',
        'boleta_enabled', 'factura_enabled', 'default_receipt_type',
    ];

    protected $hidden = ['singleton_key', 'logo_path'];

    protected $casts = [
        'boleta_enabled' => 'boolean',
        'factura_enabled' => 'boolean',
    ];
}
