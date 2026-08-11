<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseController extends Controller
{
    public function options()
    {
        return Warehouse::with('branch:id,code,name,district,is_active')
            ->where('is_active', true)
            ->whereHas('branch', fn ($query) => $query->where('is_active', true))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'branch_id', 'code', 'name', 'is_default']);
    }

    public function index(Request $request)
    {
        return Warehouse::with('branch:id,code,name,district,is_active,is_main')
            ->withSum('inventories as total_stock', 'quantity')
            ->withCount('inventories')
            ->when($request->branch_id, fn ($query, $value) => $query->where('branch_id', $value))
            ->when($request->search, fn ($query, $value) => $query->where(fn ($search) => $search
                ->where('name', 'like', "%{$value}%")
                ->orWhere('code', 'like', "%{$value}%")
                ->orWhereHas('branch', fn ($branch) => $branch
                    ->where('name', 'like', "%{$value}%")
                    ->orWhere('code', 'like', "%{$value}%"))))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate(20);
    }

    public function store(Request $request)
    {
        $request->merge(['code' => $this->code((string) $request->code)]);
        $data = $this->validateData($request);
        $this->validateDefaultState($data);

        $warehouse = DB::transaction(function () use ($data) {
            Branch::whereKey($data['branch_id'])->lockForUpdate()->firstOrFail();
            $existing = Warehouse::orderBy('id')->lockForUpdate()->get(['id', 'is_default']);

            if ($existing->isEmpty() && ! $data['is_default']) {
                if (! $data['is_active']) {
                    throw ValidationException::withMessages([
                        'is_active' => 'El primer almacén debe estar activo porque será el principal de venta web.',
                    ]);
                }
                $data['is_default'] = true;
            }

            if ($data['is_default']) {
                Warehouse::where('is_default', true)->update(['is_default' => false, 'default_guard' => null]);
            }

            return Warehouse::create($data);
        });

        return response()->json($warehouse->load('branch:id,code,name,district,is_active,is_main'), 201);
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $request->merge(['code' => $this->code((string) $request->code)]);
        $data = $this->validateData($request, $warehouse);
        $this->validateDefaultState($data);

        DB::transaction(function () use ($data, $warehouse) {
            Branch::whereIn('id', array_unique([$warehouse->branch_id, $data['branch_id']]))
                ->orderBy('id')->lockForUpdate()->get();
            $warehouses = Warehouse::orderBy('id')->lockForUpdate()->get();
            $locked = $warehouses->firstWhere('id', $warehouse->id);

            if (! $locked) {
                abort(404);
            }

            if ($locked->is_default && ! $data['is_default']) {
                throw ValidationException::withMessages([
                    'is_default' => 'Para cambiar el almacén principal, establezca directamente otro almacén como principal.',
                ]);
            }

            if ($data['is_default'] && ! $locked->is_default) {
                Warehouse::where('is_default', true)->update(['is_default' => false, 'default_guard' => null]);
            }

            $locked->update($data);
        });

        return $warehouse->refresh()->load('branch:id,code,name,district,is_active,is_main');
    }

    public function status(Request $request, Warehouse $warehouse)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);

        $updated = DB::transaction(function () use ($warehouse, $data) {
            $locked = Warehouse::whereKey($warehouse->id)->lockForUpdate()->firstOrFail();

            if (! $data['is_active'] && $locked->is_default) {
                throw ValidationException::withMessages([
                    'is_active' => 'No se puede desactivar el almacén principal de venta web. Establezca primero otro almacén activo como principal.',
                ]);
            }

            if (! $data['is_active'] && $locked->inventories()
                ->where(fn ($query) => $query->where('quantity', '>', 0)->orWhere('reserved_quantity', '>', 0))->exists()) {
                throw ValidationException::withMessages([
                    'is_active' => 'No se puede desactivar el almacén porque contiene stock físico o reservado.',
                ]);
            }

            $locked->update($data);

            return $locked;
        });

        return $updated->load('branch:id,code,name,district,is_active,is_main');
    }

    private function validateData(Request $request, ?Warehouse $warehouse = null): array
    {
        return $request->validate([
            'branch_id' => ['required', Rule::exists('branches', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'code' => ['required', 'string', 'max:50', Rule::unique('warehouses')->ignore($warehouse)],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'address' => 'nullable|string|max:255',
            'is_default' => 'required|boolean',
            'is_active' => 'required|boolean',
        ]);
    }

    private function validateDefaultState(array $data): void
    {
        if ($data['is_default'] && ! $data['is_active']) {
            throw ValidationException::withMessages([
                'is_default' => 'El almacén principal de venta web debe permanecer activo.',
            ]);
        }
    }

    private function code(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($code)));
    }
}
