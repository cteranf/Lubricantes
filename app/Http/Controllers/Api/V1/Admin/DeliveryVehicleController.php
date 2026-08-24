<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryVehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DeliveryVehicleController extends Controller
{
    public function index(Request $request)
    {
        return DeliveryVehicle::withCount(['deliveries as active_deliveries_count' => fn ($query) => $query->whereIn('status', ['assigned', 'dispatched', 'out_for_delivery', 'in_transit']), 'deliveries as deliveries_count'])
            ->when($request->search, fn ($query, $value) => $query->where(fn ($search) => $search->where('code', 'like', "%{$value}%")->orWhere('plate_number', 'like', "%{$value}%")->orWhere('brand', 'like', "%{$value}%")->orWhere('model', 'like', "%{$value}%")))
            ->when($request->type, fn ($query, $value) => $query->where('vehicle_type', $value))
            ->orderBy('code')->paginate(20);
    }

    public function store(Request $request)
    {
        $request->merge(['plate_number' => $this->plate($request->plate_number)]);
        $data = $this->validated($request);
        $data['code'] = strtoupper(trim($data['code']));
        $data['plate_number'] = $this->plate($data['plate_number'] ?? null);

        return response()->json(DeliveryVehicle::create($data), 201);
    }

    public function update(Request $request, DeliveryVehicle $deliveryVehicle)
    {
        $request->merge(['plate_number' => $this->plate($request->plate_number)]);
        $data = $this->validated($request, $deliveryVehicle);
        $data['code'] = strtoupper(trim($data['code']));
        $data['plate_number'] = $this->plate($data['plate_number'] ?? null);
        DB::transaction(function () use ($deliveryVehicle, $data) {
            DeliveryVehicle::whereKey($deliveryVehicle->id)->lockForUpdate()->firstOrFail()->update($data);
        });

        return response()->json($deliveryVehicle->refresh());
    }

    public function status(Request $request, DeliveryVehicle $deliveryVehicle)
    {
        $data = $request->validate(['is_active' => 'required|boolean', 'is_available' => 'sometimes|boolean']);
        $updated = DB::transaction(function () use ($deliveryVehicle, $data) {
            $locked = DeliveryVehicle::whereKey($deliveryVehicle->id)->lockForUpdate()->firstOrFail();
            if (! $data['is_active'] && $locked->deliveries()->whereIn('status', ['assigned', 'dispatched', 'out_for_delivery', 'in_transit'])->exists()) {
                abort(422, 'No se puede desactivar un vehículo con una entrega activa.');
            }
            $locked->update(['is_active' => $data['is_active'], 'is_available' => $data['is_active'] ? ($data['is_available'] ?? $locked->is_available) : false]);

            return $locked;
        });

        return response()->json($updated);
    }

    public function availability(Request $request, DeliveryVehicle $deliveryVehicle)
    {
        $data = $request->validate(['is_available' => 'required|boolean']);
        abort_unless($deliveryVehicle->is_active, 422, 'Un vehículo inactivo no puede marcarse disponible.');
        $deliveryVehicle->update($data);

        return response()->json($deliveryVehicle->refresh());
    }

    public function show(DeliveryVehicle $deliveryVehicle)
    {
        return response()->json($deliveryVehicle->load(['deliveries.order:id', 'deliveries.driver:id,code,first_name,last_name']));
    }

    private function validated(Request $request, ?DeliveryVehicle $vehicle = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('delivery_vehicles', 'code')->ignore($vehicle)],
            'plate_number' => ['nullable', 'string', 'max:40', Rule::unique('delivery_vehicles', 'plate_number')->ignore($vehicle)],
            'vehicle_type' => ['required', Rule::in(DeliveryVehicle::TYPES)], 'brand' => 'nullable|string|max:255', 'model' => 'nullable|string|max:255',
            'year' => 'nullable|integer|min:1900|max:2100', 'color' => 'nullable|string|max:50', 'load_capacity_kg' => 'nullable|numeric|min:0',
            'ownership_type' => ['required', Rule::in(DeliveryVehicle::OWNERSHIP_TYPES)], 'soat_expires_at' => 'nullable|date', 'technical_inspection_expires_at' => 'nullable|date',
            'notes' => 'nullable|string', 'is_active' => 'sometimes|boolean', 'is_available' => 'sometimes|boolean',
        ]);
    }

    private function plate(?string $plate): ?string
    {
        return $plate === null || trim($plate) === '' ? null : strtoupper(preg_replace('/[\s-]+/', '', trim($plate)));
    }
}
