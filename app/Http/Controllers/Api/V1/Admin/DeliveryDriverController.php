<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DeliveryDriverController extends Controller
{
    public function index(Request $request)
    {
        return DeliveryDriver::withCount(['deliveries as active_deliveries_count' => fn ($query) => $query->whereIn('status', ['assigned', 'dispatched', 'out_for_delivery', 'in_transit']), 'deliveries as deliveries_count'])
            ->with('deliveries:id,driver_id,order_id,status')
            ->when($request->search, fn ($query, $value) => $query->where(fn ($search) => $search->where('code', 'like', "%{$value}%")->orWhere('first_name', 'like', "%{$value}%")->orWhere('last_name', 'like', "%{$value}%")->orWhere('document_number', 'like', "%{$value}%")))
            ->when($request->status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($request->status === 'available', fn ($query) => $query->where('is_active', true)->where('is_available', true))
            ->when($request->status === 'unavailable', fn ($query) => $query->where('is_active', true)->where('is_available', false))
            ->orderBy('last_name')->orderBy('first_name')->paginate(20);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['code'] = strtoupper(trim($data['code']));
        $driver = DeliveryDriver::create($data);

        return response()->json($driver, 201);
    }

    public function update(Request $request, DeliveryDriver $deliveryDriver)
    {
        $data = $this->validated($request, $deliveryDriver);
        $data['code'] = strtoupper(trim($data['code']));
        DB::transaction(function () use ($deliveryDriver, $data) {
            $locked = DeliveryDriver::whereKey($deliveryDriver->id)->lockForUpdate()->firstOrFail();
            $locked->update($data);
        });

        return response()->json($deliveryDriver->refresh());
    }

    public function status(Request $request, DeliveryDriver $deliveryDriver)
    {
        $data = $request->validate(['is_active' => 'required|boolean', 'is_available' => 'sometimes|boolean']);
        $updated = DB::transaction(function () use ($deliveryDriver, $data) {
            $locked = DeliveryDriver::whereKey($deliveryDriver->id)->lockForUpdate()->firstOrFail();
            if (! $data['is_active'] && $locked->deliveries()->whereIn('status', ['assigned', 'dispatched', 'out_for_delivery', 'in_transit'])->exists()) {
                abort(422, 'No se puede desactivar un repartidor con una entrega activa.');
            }
            $locked->update(['is_active' => $data['is_active'], 'is_available' => $data['is_active'] ? ($data['is_available'] ?? $locked->is_available) : false]);

            return $locked;
        });

        return response()->json($updated);
    }

    public function availability(Request $request, DeliveryDriver $deliveryDriver)
    {
        $data = $request->validate(['is_available' => 'required|boolean']);
        abort_unless($deliveryDriver->is_active, 422, 'Un repartidor inactivo no puede marcarse disponible.');
        $deliveryDriver->update($data);

        return response()->json($deliveryDriver->refresh());
    }

    public function show(DeliveryDriver $deliveryDriver)
    {
        return response()->json($deliveryDriver->load(['deliveries.order:id', 'deliveries.vehicle:id,code,plate_number,brand,model']));
    }

    private function validated(Request $request, ?DeliveryDriver $driver = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('delivery_drivers', 'code')->ignore($driver)],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'first_name' => 'required|string|max:120', 'last_name' => 'required|string|max:120',
            'document_type' => 'nullable|string|max:30', 'document_number' => ['nullable', 'string', 'max:50', Rule::unique('delivery_drivers', 'document_number')->ignore($driver)],
            'phone' => 'required|string|max:40', 'email' => 'nullable|email|max:255',
            'license_number' => 'nullable|string|max:80', 'license_category' => 'nullable|string|max:30', 'license_expires_at' => 'nullable|date',
            'emergency_contact_name' => 'nullable|string|max:255', 'emergency_contact_phone' => 'nullable|string|max:40', 'notes' => 'nullable|string',
            'is_active' => 'sometimes|boolean', 'is_available' => 'sometimes|boolean',
        ]);
    }
}
