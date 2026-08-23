<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;

class CheckoutPickupBranchController extends Controller
{
    public function index()
    {
        return Branch::query()
            ->where('is_active', true)
            ->where('allows_pickup', true)
            ->where('serves_public', true)
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get([
                'id', 'code', 'name', 'address', 'district', 'province', 'department',
                'reference', 'phone', 'business_hours', 'pickup_instructions',
            ]);
    }
}
