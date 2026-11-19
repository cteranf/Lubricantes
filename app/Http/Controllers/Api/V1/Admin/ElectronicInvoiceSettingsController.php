<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Services\ElectronicDocumentService;
use App\Services\OrderTaxCalculationService;
use Illuminate\Http\Request;

class ElectronicInvoiceSettingsController extends Controller
{
    public function __construct(private ElectronicDocumentService $documents, private OrderTaxCalculationService $taxes) {}

    public function show()
    {
        $settings = $this->documents->settings();
        $profile = BusinessProfile::query()->where('singleton_key', BusinessProfile::SINGLETON_KEY)->first();
        $requirements = [
            'seller_name' => filled($profile?->legal_name) || filled($profile?->commercial_name),
            'seller_tax_id' => filled($profile?->tax_id_number),
            'fiscal_address' => filled($profile?->fiscal_address),
            'provider_adapter' => false,
            'tax_rules' => $this->taxes->configuredVersion() !== null,
            'credentials' => false,
        ];

        return response()->json(['data' => [
            'commercial_enabled' => $this->documents->enabled(),
            'connection_type' => $settings['connection_type'],
            'provider_name' => $settings['provider_name'],
            'environment' => $settings['environment'],
            'version' => $settings['version'],
            'connection_available' => false,
            'readiness' => $requirements,
            'ready' => ! in_array(false, $requirements, true),
        ]]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'connection_type' => ['required', 'in:direct_sunat,provider_api'],
            'provider_name' => ['nullable', 'string', 'max:80', 'regex:/\A[a-zA-Z0-9._-]+\z/'],
            'environment' => ['required', 'in:testing,production'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        abort_if($data['connection_type'] === 'provider_api' && blank($data['provider_name']), 422, 'Selecciona el identificador del proveedor configurado.');
        if ($data['connection_type'] === 'direct_sunat') {
            $data['provider_name'] = null;
        }
        $settings = $this->documents->settings();
        abort_if((int) $data['version'] !== $settings['version'], 409, 'La configuración cambió. Recarga antes de guardar.');
        $saved = $this->documents->updateSettings(collect($data)->except('version')->all(), $request->user());

        return response()->json(['message' => 'Configuración guardada. No se conectó con SUNAT ni con un proveedor.', 'data' => [
            'commercial_enabled' => $this->documents->enabled(),
            'connection_type' => $saved->connection_type,
            'provider_name' => $saved->provider_name,
            'environment' => $saved->environment,
            'version' => (int) $saved->version,
            'connection_available' => false,
        ]]);
    }
}
