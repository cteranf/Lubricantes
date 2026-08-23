<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\TerritoryNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
{
    public function index(Request $request)
    {
        return Department::withCount(['provinces', 'districts'])
            ->when($request->search, fn ($query, $search) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')->paginate(20);
    }

    public function store(Request $request, TerritoryNormalizer $normalizer)
    {
        return response()->json($this->persist($request, $normalizer), 201);
    }

    public function update(Request $request, Department $department, TerritoryNormalizer $normalizer)
    {
        return $this->persist($request, $normalizer, $department);
    }

    public function status(Request $request, Department $department)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);
        if (! $data['is_active'] && ($department->provinces()->where('is_active', true)->exists() || $department->districts()->where('districts.is_active', true)->exists())) {
            throw ValidationException::withMessages(['is_active' => ['No se puede desactivar este departamento mientras tenga provincias o distritos activos.']]);
        }
        $department->update($data);

        return $department->loadCount(['provinces', 'districts']);
    }

    public function destroy(Department $department)
    {
        if ($department->provinces()->exists()
            || DB::table('user_addresses')->where('department_id', $department->id)->exists()
            || DB::table('branches')->where('department_id', $department->id)->exists()) {
            throw ValidationException::withMessages(['department' => ['No se puede eliminar porque está siendo utilizado por provincias, direcciones o sedes.']]);
        }
        $department->delete();

        return response()->noContent();
    }

    private function persist(Request $request, TerritoryNormalizer $normalizer, ?Department $department = null): Department
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('departments')->ignore($department)],
            'name' => 'required|string|max:100',
            'is_active' => 'required|boolean',
        ]);
        $normalized = $normalizer->normalize($data['name']);
        if (Department::where('normalized_name', $normalized)->when($department, fn ($query) => $query->whereKeyNot($department->id))->exists()) {
            throw ValidationException::withMessages(['name' => ['Ya existe un departamento con este nombre.']]);
        }
        if ($department && ! $data['is_active'] && ($department->provinces()->where('is_active', true)->exists() || $department->districts()->where('districts.is_active', true)->exists())) {
            throw ValidationException::withMessages(['is_active' => ['No se puede desactivar este departamento mientras tenga provincias o distritos activos.']]);
        }

        try {
            return DB::transaction(function () use ($data, $department) {
                if (! $department) {
                    return Department::create($data);
                }
                $locked = Department::whereKey($department->id)->lockForUpdate()->firstOrFail();
                $locked->update($data);

                return $locked->refresh()->loadCount(['provinces', 'districts']);
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages(['name' => ['Ya existe un departamento con ese código o nombre.']]);
            }
            throw $exception;
        }
    }
}
