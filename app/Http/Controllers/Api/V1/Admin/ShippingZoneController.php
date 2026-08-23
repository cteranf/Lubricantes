<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShippingZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShippingZoneController extends Controller
{
    public function index(Request $request)
    {
        return ShippingZone::withCount(['rates', 'rates as covered_districts_count' => fn ($query) => $query->where('is_active', true)])
            ->when($request->search, fn ($query, $value) => $query->where(fn ($search) => $search->where('name', 'like', "%{$value}%")->orWhere('code', 'like', "%{$value}%")))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')->paginate(20);
    }

    public function store(Request $request)
    {
        $request->merge(['code' => $this->code((string) $request->code)]);
        $zone = ShippingZone::create($this->validated($request));

        return response()->json($zone->loadCount(['rates', 'rates as covered_districts_count' => fn ($query) => $query->where('is_active', true)]), 201);
    }

    public function update(Request $request, ShippingZone $shippingZone)
    {
        $request->merge(['code' => $this->code((string) $request->code)]);
        $shippingZone->update($this->validated($request, $shippingZone));

        return $shippingZone->refresh()->loadCount(['rates', 'rates as covered_districts_count' => fn ($query) => $query->where('is_active', true)]);
    }

    public function status(Request $request, ShippingZone $shippingZone)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);
        DB::transaction(fn () => ShippingZone::whereKey($shippingZone->id)->lockForUpdate()->firstOrFail()->update($data));

        return $shippingZone->refresh()->loadCount(['rates', 'rates as covered_districts_count' => fn ($query) => $query->where('is_active', true)]);
    }

    public function destroy(ShippingZone $shippingZone)
    {
        if ($shippingZone->is_active || $shippingZone->rates()->exists() || $shippingZone->orders()->exists()) {
            throw ValidationException::withMessages(['shipping_zone' => 'Solo puede eliminarse una zona inactiva, sin tarifas ni pedidos asociados.']);
        }
        $shippingZone->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?ShippingZone $zone = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('shipping_zones')->ignore($zone)],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'estimated_days_min' => 'nullable|integer|min:0',
            'estimated_days_max' => 'nullable|integer|min:0|gte:estimated_days_min',
            'is_active' => 'required|boolean',
        ]);
    }

    private function code(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($code)));
    }
}
