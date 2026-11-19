<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessTaxConfiguration extends Model
{
    public const SINGLETON_KEY = 'primary';

    protected $fillable = [
        'singleton_key',
        'igv_rate_basis_points',
        'ipm_rate_basis_points',
        'effective_from',
        'prices_include_tax',
        'shipping_treatment',
        'is_configured',
        'version',
    ];

    protected $casts = [
        'igv_rate_basis_points' => 'integer',
        'ipm_rate_basis_points' => 'integer',
        'effective_from' => 'date:Y-m-d',
        'prices_include_tax' => 'boolean',
        'is_configured' => 'boolean',
        'version' => 'integer',
    ];
}
