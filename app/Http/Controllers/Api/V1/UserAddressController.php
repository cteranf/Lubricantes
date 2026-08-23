<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserAddress;
use App\Services\TerritorySelectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserAddressController extends Controller
{
    public function index(Request $request)
    {
        $addresses = $request->user()->savedAddresses()->where('is_active', true)->orderByDesc('is_default')->latest()->get();
        $allowedLegacyFields = array_flip([
            'label', 'recipient_name', 'phone', 'address', 'reference',
            'department', 'province', 'district', 'ubigeo',
        ]);
        $legacySource = $request->user()->addresses ?? [];
        if (is_array($legacySource) && array_key_exists('address', $legacySource)) {
            $legacySource = [$legacySource];
        }
        $legacyCandidates = collect(is_array($legacySource) ? $legacySource : []);
        $legacyAddresses = $legacyCandidates
            ->filter(fn ($address) => is_array($address))
            ->map(fn (array $address) => array_filter(
                array_intersect_key($address, $allowedLegacyFields),
                fn ($value) => is_string($value) && trim($value) !== '',
            ))
            ->filter()
            ->values();

        return response()->json([
            'data' => $addresses,
            'legacy_addresses' => $legacyAddresses,
            'legacy_confirmation_required' => $legacyCandidates->isNotEmpty(),
            'legacy_unrecognized_count' => $legacyCandidates->count() - $legacyAddresses->count(),
        ]);
    }

    public function store(Request $request, TerritorySelectionService $territories)
    {
        $data = $this->validated($request, $territories);

        $address = DB::transaction(function () use ($request, $data) {
            $hasAddresses = $request->user()->savedAddresses()->lockForUpdate()->exists();
            if (($data['is_default'] ?? false) || ! $hasAddresses) {
                $request->user()->savedAddresses()->update(['is_default' => false]);
                $data['is_default'] = true;
            }

            return $request->user()->savedAddresses()->create($data + ['is_active' => true]);
        });

        return response()->json($address, 201);
    }

    public function update(Request $request, UserAddress $address, TerritorySelectionService $territories)
    {
        abort_unless($address->user_id === $request->user()->id, 404);
        $data = $this->validated($request, $territories);
        DB::transaction(function () use ($request, $address, $data) {
            $locked = $request->user()->savedAddresses()->whereKey($address->id)->lockForUpdate()->firstOrFail();
            if ($data['is_default'] ?? false) {
                $request->user()->savedAddresses()->whereKeyNot($locked->id)->update(['is_default' => false]);
            }
            $locked->update($data);
        });

        return $address->refresh();
    }

    private function validated(Request $request, TerritorySelectionService $territories): array
    {
        $data = $request->validate([
            'label' => 'nullable|string|max:100',
            'recipient_name' => 'required|string|max:255',
            'phone' => 'required|string|max:30',
            'address' => 'required|string|max:255',
            'reference' => 'nullable|string|max:500',
            'department_id' => 'required|integer',
            'province_id' => 'required|integer',
            'district_id' => 'required|integer',
            'is_default' => 'sometimes|boolean',
        ]);

        return $data + $territories->snapshots($territories->resolve(
            $data['department_id'], $data['province_id'], $data['district_id'],
        ));
    }
}
