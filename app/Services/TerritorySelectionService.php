<?php

namespace App\Services;

use App\Models\Department;
use App\Models\District;
use App\Models\Province;
use Illuminate\Validation\ValidationException;

class TerritorySelectionService
{
    public function resolve(int $departmentId, int $provinceId, int $districtId): array
    {
        $department = Department::whereKey($departmentId)->where('is_active', true)->first();
        if (! $department) {
            throw ValidationException::withMessages(['department_id' => ['Seleccione un departamento activo.']]);
        }

        $province = Province::whereKey($provinceId)->where('department_id', $department->id)->where('is_active', true)->first();
        if (! $province) {
            throw ValidationException::withMessages(['province_id' => ['La provincia seleccionada no pertenece al departamento o está inactiva.']]);
        }

        $district = District::whereKey($districtId)->where('province_id', $province->id)->where('is_active', true)->first();
        if (! $district) {
            throw ValidationException::withMessages(['district_id' => ['El distrito seleccionado no pertenece a la provincia o está inactivo.']]);
        }

        return compact('department', 'province', 'district');
    }

    public function snapshots(array $territory): array
    {
        return [
            'department_id' => $territory['department']->id,
            'province_id' => $territory['province']->id,
            'district_id' => $territory['district']->id,
            'department' => $territory['department']->name,
            'province' => $territory['province']->name,
            'district' => $territory['district']->name,
            'ubigeo' => $territory['district']->ubigeo,
        ];
    }

    public function resolveDistrict(int $districtId): array
    {
        $district = District::with('province.department')->whereKey($districtId)->where('is_active', true)->first();
        if (! $district || ! $district->province?->is_active || ! $district->province->department?->is_active) {
            throw ValidationException::withMessages(['district_id' => ['Seleccione un distrito activo cuya provincia y departamento estén activos.']]);
        }

        return ['department' => $district->province->department, 'province' => $district->province, 'district' => $district];
    }
}
