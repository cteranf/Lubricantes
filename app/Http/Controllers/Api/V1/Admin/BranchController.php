<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        return Branch::withCount(['warehouses', 'warehouses as active_warehouses_count' => fn ($query) => $query->where('is_active', true)])
            ->when($request->search, fn ($query, $value) => $query->where(fn ($search) => $search
                ->where('name', 'like', "%{$value}%")
                ->orWhere('code', 'like', "%{$value}%")
                ->orWhere('district', 'like', "%{$value}%")))
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->paginate(20);
    }

    public function options()
    {
        return Branch::where('is_active', true)
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'district', 'is_main']);
    }

    public function store(Request $request)
    {
        $request->merge(['code' => $this->code((string) $request->code)]);
        $data = $this->validateData($request);

        $branch = DB::transaction(function () use ($data) {
            $existing = Branch::orderBy('id')->lockForUpdate()->get(['id', 'is_main']);
            $isFirst = $existing->isEmpty();

            if ($isFirst && ! $data['is_active']) {
                throw ValidationException::withMessages([
                    'is_active' => 'La primera sede debe estar activa porque se convertirá en la sede principal.',
                ]);
            }

            return Branch::create($data + ['is_main' => $isFirst]);
        });

        return response()->json($branch->loadCount(['warehouses', 'warehouses as active_warehouses_count']), 201);
    }

    public function update(Request $request, Branch $branch)
    {
        $request->merge(['code' => $this->code((string) $request->code)]);
        $data = $this->validateData($request, $branch, false);

        DB::transaction(function () use ($branch, $data) {
            $locked = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $locked->update($data);
        });

        return $branch->refresh()->loadCount(['warehouses', 'warehouses as active_warehouses_count']);
    }

    public function status(Request $request, Branch $branch)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);

        $updated = DB::transaction(function () use ($branch, $data) {
            $locked = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();

            if (! $data['is_active'] && $locked->is_main) {
                throw ValidationException::withMessages([
                    'is_active' => 'No se puede desactivar la sede principal. Establezca primero otra sede activa como principal.',
                ]);
            }

            if (! $data['is_active'] && $locked->warehouses()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'is_active' => 'No se puede desactivar la sede porque tiene almacenes activos.',
                ]);
            }

            $locked->update($data);

            return $locked;
        });

        return $updated->loadCount(['warehouses', 'warehouses as active_warehouses_count']);
    }

    public function setMain(Branch $branch)
    {
        $updated = DB::transaction(function () use ($branch) {
            $branches = Branch::orderBy('id')->lockForUpdate()->get();
            $target = $branches->firstWhere('id', $branch->id);

            if (! $target) {
                abort(404);
            }

            if (! $target->is_active) {
                throw ValidationException::withMessages([
                    'branch' => 'Solo una sede activa puede establecerse como principal.',
                ]);
            }

            if ($target->is_main) {
                return $target;
            }

            Branch::where('is_main', true)->update(['is_main' => false, 'main_guard' => null]);
            $target->update(['is_main' => true]);

            return $target->refresh();
        });

        return $updated->loadCount(['warehouses', 'warehouses as active_warehouses_count']);
    }

    public function destroy(Branch $branch)
    {
        DB::transaction(function () use ($branch) {
            $locked = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_main) {
                throw ValidationException::withMessages(['branch' => 'La sede principal no puede eliminarse.']);
            }

            if ($locked->warehouses()->exists()) {
                throw ValidationException::withMessages([
                    'branch' => 'No se puede eliminar la sede porque tiene almacenes asociados, incluso si están inactivos.',
                ]);
            }

            $locked->delete();
        });

        return response()->noContent();
    }

    private function validateData(Request $request, ?Branch $branch = null, bool $includeStatus = true): array
    {
        $rules = [
            'code' => ['required', 'string', 'max:50', Rule::unique('branches')->ignore($branch)],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'address' => 'required|string|max:255',
            'district' => 'required|string|max:100',
            'province' => 'required|string|max:100',
            'department' => 'required|string|max:100',
            'reference' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'business_hours' => 'nullable|string|max:500',
            'pickup_instructions' => 'nullable|string|max:2000',
            'allows_pickup' => 'required|boolean',
            'serves_public' => 'required|boolean',
        ];

        if ($includeStatus) {
            $rules['is_active'] = 'required|boolean';
        }

        return $request->validate($rules);
    }

    private function code(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($code)));
    }
}
