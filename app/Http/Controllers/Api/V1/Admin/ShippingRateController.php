<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShippingRate;
use App\Services\ShippingRateService;
use App\Services\TerritorySelectionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShippingRateController extends Controller
{
    public function index(Request $request)
    {
        return ShippingRate::with('zone:id,code,name,is_active,estimated_days_min,estimated_days_max', 'districtRelation.province.department')
            ->when($request->shipping_zone_id, fn ($query, $value) => $query->where('shipping_zone_id', $value))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->search, fn ($query, $value) => $query->where(fn ($search) => $search
                ->where('department', 'like', "%{$value}%")->orWhere('province', 'like', "%{$value}%")
                ->orWhere('district', 'like', "%{$value}%")->orWhereHas('zone', fn ($zone) => $zone->where('name', 'like', "%{$value}%"))
                ->orWhereHas('districtRelation', fn ($district) => $district->where('name', 'like', "%{$value}%")
                    ->orWhereHas('province', fn ($province) => $province->where('name', 'like', "%{$value}%")
                        ->orWhereHas('department', fn ($department) => $department->where('name', 'like', "%{$value}%"))))))
            ->orderBy('department')->orderBy('province')->orderBy('district')->paginate(20);
    }

    public function store(Request $request, ShippingRateService $service)
    {
        return response()->json($this->persist($request, $service)->load('zone:id,code,name,is_active', 'districtRelation.province.department'), 201);
    }

    public function update(Request $request, ShippingRate $shippingRate, ShippingRateService $service)
    {
        return $this->persist($request, $service, $shippingRate)->load('zone:id,code,name,is_active', 'districtRelation.province.department');
    }

    public function status(Request $request, ShippingRate $shippingRate, ShippingRateService $service)
    {
        $request->merge(array_merge($shippingRate->only([
            'shipping_zone_id', 'district_id', 'amount',
            'estimated_days_min', 'estimated_days_max',
        ]), $request->validate(['is_active' => 'required|boolean'])));

        return $this->persist($request, $service, $shippingRate)->load('zone:id,code,name,is_active', 'districtRelation.province.department');
    }

    public function destroy(ShippingRate $shippingRate)
    {
        if ($shippingRate->is_active || $shippingRate->orders()->exists()) {
            throw ValidationException::withMessages(['shipping_rate' => 'Solo puede eliminarse una tarifa inactiva que no haya sido usada por pedidos.']);
        }
        $shippingRate->delete();

        return response()->noContent();
    }

    private function persist(Request $request, ShippingRateService $service, ?ShippingRate $rate = null): ShippingRate
    {
        $data = $request->validate([
            'shipping_zone_id' => 'required|integer|exists:shipping_zones,id',
            'district_id' => 'required|integer',
            'amount' => ['required', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'estimated_days_min' => 'nullable|integer|min:0',
            'estimated_days_max' => 'nullable|integer|min:0|gte:estimated_days_min',
            'is_active' => 'required|boolean',
        ]);

        $territory = app(TerritorySelectionService::class)->resolveDistrict($data['district_id']);
        $data += app(TerritorySelectionService::class)->snapshots($territory);

        if ($data['is_active']) {
            if (ShippingRate::where('active_district_id', $data['district_id'])->when($rate, fn ($query) => $query->whereKeyNot($rate->id))->exists()) {
                throw ValidationException::withMessages(['district_id' => 'Ya existe una tarifa activa para este distrito.']);
            }
        }

        try {
            return DB::transaction(function () use ($data, $rate) {
                if ($rate) {
                    $locked = ShippingRate::whereKey($rate->id)->lockForUpdate()->firstOrFail();
                    $locked->update($data);

                    return $locked->refresh();
                }

                return ShippingRate::create($data);
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages(['district_id' => 'Ya existe una tarifa activa para este distrito.']);
            }
            throw $exception;
        }
    }
}
