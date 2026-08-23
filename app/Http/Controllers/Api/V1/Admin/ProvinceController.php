<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Province;
use App\Services\TerritoryNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProvinceController extends Controller
{
    public function index(Request $request)
    {
        return Province::with('department:id,code,name,is_active')->withCount('districts')
            ->when($request->department_id, fn ($query, $id) => $query->where('department_id', $id))
            ->when($request->search, fn ($query, $search) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')->paginate(20);
    }

    public function store(Request $request, TerritoryNormalizer $normalizer)
    {
        return response()->json($this->persist($request, $normalizer), 201);
    }

    public function update(Request $request, Province $province, TerritoryNormalizer $normalizer)
    {
        return $this->persist($request, $normalizer, $province);
    }

    public function status(Request $request, Province $province)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);
        if ($data['is_active'] && ! $province->department()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['is_active' => ['No se puede activar la provincia porque su departamento está inactivo.']]);
        }
        if (! $data['is_active'] && $province->districts()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['is_active' => ['No se puede desactivar esta provincia mientras tenga distritos activos.']]);
        }
        $province->update($data);

        return $province->load('department:id,code,name,is_active')->loadCount('districts');
    }

    public function destroy(Province $province)
    {
        if ($province->districts()->exists()
            || DB::table('user_addresses')->where('province_id', $province->id)->exists()
            || DB::table('branches')->where('province_id', $province->id)->exists()) {
            throw ValidationException::withMessages(['province' => ['No se puede eliminar porque está siendo utilizada por distritos, direcciones o sedes.']]);
        }
        $province->delete();

        return response()->noContent();
    }

    private function persist(Request $request, TerritoryNormalizer $normalizer, ?Province $province = null): Province
    {
        $data = $request->validate(['department_id' => 'required|integer', 'code' => 'required|string|max:50', 'name' => 'required|string|max:100', 'is_active' => 'required|boolean']);
        $department = Department::find($data['department_id']);
        if (! $department || ($data['is_active'] && ! $department->is_active)) {
            throw ValidationException::withMessages(['department_id' => ['Seleccione un departamento activo.']]);
        }
        if ($province && $province->department_id !== $department->id && $province->districts()->exists()) {
            throw ValidationException::withMessages(['department_id' => ['No se puede cambiar el departamento de una provincia que ya tiene distritos.']]);
        }
        if ($province && ! $data['is_active'] && $province->districts()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['is_active' => ['No se puede desactivar esta provincia mientras tenga distritos activos.']]);
        }
        $normalized = $normalizer->normalize($data['name']);
        $duplicate = Province::where('department_id', $department->id)->where(fn ($query) => $query
            ->where('normalized_name', $normalized)->orWhere('code', strtoupper(trim($data['code']))))
            ->when($province, fn ($query) => $query->whereKeyNot($province->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => ['Ya existe una provincia con ese código o nombre dentro del departamento.']]);
        }

        try {
            $saved = $province ?: new Province;
            $saved->fill($data)->save();

            return $saved->load('department:id,code,name,is_active')->loadCount('districts');
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages(['name' => ['Ya existe una provincia con ese código o nombre dentro del departamento.']]);
            }
            throw $exception;
        }
    }
}
