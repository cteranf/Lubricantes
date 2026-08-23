<?php

namespace Tests\Concerns;

use App\Models\Department;
use App\Models\District;
use App\Models\Province;

trait CreatesTerritoryCatalog
{
    protected function territory(string $suffix = ''): array
    {
        $suffix = strtoupper($suffix);
        $department = Department::firstOrCreate(['code' => 'DEP'.$suffix], ['name' => 'Departamento '.$suffix, 'is_active' => true]);
        $province = Province::firstOrCreate(['department_id' => $department->id, 'code' => 'PRO'.$suffix], ['name' => 'Provincia '.$suffix, 'is_active' => true]);
        $district = District::firstOrCreate(['province_id' => $province->id, 'code' => 'DIS'.$suffix], ['name' => 'Distrito '.$suffix, 'is_active' => true]);

        return compact('department', 'province', 'district');
    }

    protected function territoryIds(string $suffix = ''): array
    {
        $territory = $this->territory($suffix);

        return ['department_id' => $territory['department']->id, 'province_id' => $territory['province']->id, 'district_id' => $territory['district']->id];
    }
}
