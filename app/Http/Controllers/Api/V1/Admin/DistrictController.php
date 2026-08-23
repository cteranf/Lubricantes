<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\Province;
use App\Services\TerritoryNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DistrictController extends Controller
{
    public function index(Request $request)
    {
        return District::with('province:id,department_id,code,name,is_active', 'province.department:id,code,name,is_active')
            ->when($request->province_id, fn ($query, $id) => $query->where('province_id', $id))
            ->when($request->department_id, fn ($query, $id) => $query->whereHas('province', fn ($province) => $province->where('department_id', $id)))
            ->when($request->search, fn ($query, $search) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")->orWhere('ubigeo', 'like', "%{$search}%")))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')->paginate(20);
    }

    public function store(Request $request, TerritoryNormalizer $normalizer)
    {
        return response()->json($this->persist($request, $normalizer), 201);
    }

    public function update(Request $request, District $district, TerritoryNormalizer $normalizer)
    {
        return $this->persist($request, $normalizer, $district);
    }

    public function status(Request $request, District $district)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);
        if ($data['is_active'] && ! $district->province()->where('is_active', true)
            ->whereHas('department', fn ($query) => $query->where('is_active', true))->exists()) {
            throw ValidationException::withMessages(['is_active' => ['No se puede activar el distrito porque su provincia o departamento está inactivo.']]);
        }
        $district->update($data);

        return $district->load('province.department');
    }

    public function destroy(District $district)
    {
        if (DB::table('user_addresses')->where('district_id', $district->id)->exists()
            || DB::table('branches')->where('district_id', $district->id)->exists()
            || DB::table('shipping_rates')->where('district_id', $district->id)->exists()) {
            throw ValidationException::withMessages(['district' => ['No se puede eliminar porque está siendo utilizado por direcciones, sedes o tarifas.']]);
        }
        $district->delete();

        return response()->noContent();
    }

    private function persist(Request $request, TerritoryNormalizer $normalizer, ?District $district = null): District
    {
        $data = $request->validate([
            'department_id' => 'required|integer', 'province_id' => 'required|integer', 'code' => 'required|string|max:50',
            'ubigeo' => ['nullable', 'string', 'max:20', Rule::unique('districts')->ignore($district)],
            'name' => 'required|string|max:100', 'is_active' => 'required|boolean',
        ]);
        $province = Province::with('department')->find($data['province_id']);
        if (! $province || $province->department_id !== (int) $data['department_id']) {
            throw ValidationException::withMessages(['province_id' => ['La provincia seleccionada no pertenece al departamento indicado.']]);
        }
        if ($data['is_active'] && (! $province->is_active || ! $province->department?->is_active)) {
            throw ValidationException::withMessages(['province_id' => ['Seleccione una provincia activa cuyo departamento también esté activo.']]);
        }
        if ($district && $district->province_id !== $province->id && $this->isUsed($district)) {
            throw ValidationException::withMessages(['province_id' => ['No se puede cambiar la provincia de un distrito utilizado.']]);
        }
        $normalized = $normalizer->normalize($data['name']);
        $duplicate = District::where('province_id', $province->id)->where(fn ($query) => $query
            ->where('normalized_name', $normalized)->orWhere('code', strtoupper(trim($data['code']))))
            ->when($district, fn ($query) => $query->whereKeyNot($district->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => ['Ya existe un distrito con ese código o nombre dentro de la provincia seleccionada.']]);
        }

        try {
            unset($data['department_id']);
            $saved = $district ?: new District;
            $saved->fill($data)->save();

            return $saved->load('province.department');
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages(['name' => ['Ya existe un distrito con ese código, nombre o ubigeo.']]);
            }
            throw $exception;
        }
    }

    private function isUsed(District $district): bool
    {
        return DB::table('user_addresses')->where('district_id', $district->id)->exists()
            || DB::table('branches')->where('district_id', $district->id)->exists()
            || DB::table('shipping_rates')->where('district_id', $district->id)->exists();
    }
}
