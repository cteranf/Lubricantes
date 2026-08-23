<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\District;
use App\Models\Province;
use Illuminate\Http\Request;

class LocationOptionController extends Controller
{
    public function departments()
    {
        return Department::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
    }

    public function provinces(Request $request)
    {
        $data = $request->validate(['department_id' => 'required|integer']);

        return Province::where('department_id', $data['department_id'])
            ->where('is_active', true)
            ->whereHas('department', fn ($query) => $query->where('is_active', true))
            ->orderBy('name')->get(['id', 'department_id', 'code', 'name']);
    }

    public function districts(Request $request)
    {
        $data = $request->validate(['province_id' => 'required|integer']);

        return District::where('province_id', $data['province_id'])
            ->where('is_active', true)
            ->whereHas('province', fn ($query) => $query->where('is_active', true)
                ->whereHas('department', fn ($department) => $department->where('is_active', true)))
            ->orderBy('name')->get(['id', 'province_id', 'code', 'ubigeo', 'name']);
    }
}
