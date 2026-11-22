<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\ElectronicDocument;
use App\Models\ElectronicDocumentAttempt;
use App\Models\ElectronicDocumentSeries;
use App\Models\ElectronicInvoiceSetting;
use App\Services\ElectronicDocumentService;
use App\Services\ElectronicInvoiceCredentialService;
use App\Services\OrderTaxCalculationService;
use App\Services\SunatSchemaBundle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ElectronicInvoiceSettingsController extends Controller
{
    public function __construct(private ElectronicDocumentService $documents, private OrderTaxCalculationService $taxes, private ElectronicInvoiceCredentialService $credentials) {}

    public function show()
    {
        $settings = $this->documents->settings();
        $profile = BusinessProfile::query()->where('singleton_key', BusinessProfile::SINGLETON_KEY)->first();
        $requirements = [
            'seller_name' => filled($profile?->legal_name) || filled($profile?->commercial_name),
            'seller_tax_id' => filled($profile?->tax_id_number),
            'fiscal_address' => filled($profile?->fiscal_address),
            'provider_adapter' => $settings['connection_type'] !== 'provider_api',
            'tax_rules' => $this->taxes->configuredVersion() !== null,
            'credentials' => $this->credentialsConfigured(),
            'xml_openssl_runtime' => extension_loaded('openssl') && class_exists(\DOMDocument::class),
            'zip_runtime' => class_exists(\ZipArchive::class),
            'durable_queue' => Schema::hasTable('jobs') && Schema::hasTable('electronic_document_outbox'),
            'official_schema' => app(SunatSchemaBundle::class)->isAvailable(),
            'commercial_enabled' => $this->documents->enabled(),
        ];

        return response()->json(['data' => [
            'commercial_enabled' => $this->documents->enabled(),
            'connection_type' => $settings['connection_type'],
            'provider_name' => $settings['provider_name'],
            'environment' => $settings['environment'],
            'version' => $settings['version'],
            'connection_available' => $settings['connection_type'] === 'direct_sunat'
                && $settings['environment'] === 'testing'
                && $requirements['credentials']
                && $requirements['xml_openssl_runtime']
                && $requirements['zip_runtime']
                && $requirements['durable_queue']
                && $requirements['official_schema'],
            'certificate_present' => filled(ElectronicInvoiceSetting::query()->where('singleton_key', 'primary')->value('certificate_path')),
            'certificate_expires_at' => ElectronicInvoiceSetting::query()->where('singleton_key', 'primary')->value('certificate_expires_at'),
            'series' => $this->seriesSummary(),
            'readiness' => $requirements,
            'ready' => ! in_array(false, $requirements, true),
        ]]);
    }

    public function update(Request $request)
    {
        abort_if(array_diff(array_keys($request->all()), [
            'connection_type', 'provider_name', 'environment', 'version', 'sunat_username',
            'sunat_password', 'certificate_password', 'certificate', 'remove_certificate', '_method', '_token',
        ]) !== [], 422, 'La solicitud contiene campos no permitidos.');
        $data = $request->validate([
            'connection_type' => ['required', 'in:direct_sunat,provider_api'],
            'provider_name' => ['nullable', 'string', 'max:80', 'regex:/\A[a-zA-Z0-9._-]+\z/'],
            'environment' => ['required', 'in:testing'],
            'version' => ['required', 'integer', 'min:1'],
            'sunat_username' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sunat_password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'certificate_password' => ['required_with:certificate', 'string', 'max:255'],
            'certificate' => ['sometimes', 'file', 'mimes:p12,pfx', 'max:4096'],
            'remove_certificate' => ['sometimes', 'boolean'],
        ]);
        $settings = $this->documents->settings();
        abort_if((int) $data['version'] !== $settings['version'], 409, 'La configuración cambió. Recarga antes de guardar.');
        if ($data['connection_type'] === 'provider_api') {
            abort_if(blank($data['provider_name'] ?? null), 422, 'Selecciona el identificador del proveedor configurado.');
            $saved = $this->documents->updateSettings(collect($data)->only(['connection_type', 'provider_name', 'environment'])->all(), $request->user());
        } else {
            $saved = $this->credentials->update($data, $request->file('certificate'), $request->user());
        }

        return response()->json(['message' => 'Configuración guardada. No se conectó con SUNAT ni con un proveedor.', 'data' => [
            'commercial_enabled' => $this->documents->enabled(),
            'connection_type' => $saved->connection_type,
            'provider_name' => $saved->provider_name,
            'environment' => $saved->environment,
            'version' => (int) $saved->version,
            'connection_available' => $saved->connection_type === 'direct_sunat'
                && $saved->environment === 'testing'
                && $this->credentialsConfigured()
                && extension_loaded('openssl')
                && class_exists(\DOMDocument::class)
                && class_exists(\ZipArchive::class)
                && Schema::hasTable('jobs')
                && Schema::hasTable('electronic_document_outbox')
                && app(SunatSchemaBundle::class)->isAvailable(),
            'certificate_present' => filled($saved->certificate_path),
            'certificate_expires_at' => $saved->certificate_expires_at?->toISOString(),
        ]]);
    }

    public function updateSeries(Request $request)
    {
        abort_if(array_diff(array_keys($request->all()), ['document_type', 'series', 'next_number', '_token', '_method']) !== [], 422, 'La solicitud contiene campos no permitidos.');
        $data = $request->validate([
            'document_type' => ['required', 'in:factura,boleta'],
            'series' => ['required', 'regex:/\A[A-Z0-9]{4}\z/'],
            'next_number' => ['required', 'integer', 'min:1', 'max:99999999'],
        ]);
        $prefix = $data['document_type'] === 'factura' ? 'F' : 'B';
        abort_unless(str_starts_with($data['series'], $prefix), 422, 'La serie no corresponde al tipo de comprobante.');
        $profile = BusinessProfile::query()->where('singleton_key', BusinessProfile::SINGLETON_KEY)->firstOrFail();
        abort_unless(preg_match('/\A\d{11}\z/', (string) $profile->tax_id_number) === 1, 409, 'Configura el RUC del emisor antes de asignar una serie.');

        $series = DB::transaction(function () use ($data, $profile) {
            $identity = [
                'issuer_tax_id' => $profile->tax_id_number, 'environment' => 'testing',
                'document_type' => $data['document_type'],
            ];
            ElectronicDocumentSeries::query()->insertOrIgnore($identity + [
                'series' => $data['series'], 'next_number' => $data['next_number'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = ElectronicDocumentSeries::query()->where($identity)->lockForUpdate()->firstOrFail();
            if ($row) {
                abort_if(ElectronicDocument::query()->where('environment', 'testing')->where('document_type', $data['document_type'])->exists(), 409, 'La numeración ya tiene documentos y no puede editarse.');
                abort_if(ElectronicDocumentAttempt::query()->whereHas('document', fn ($query) => $query->where('environment', 'testing')->where('document_type', $data['document_type']))->exists(), 409, 'La numeración ya tuvo intentos y no puede editarse.');
                $row->next_number = $data['next_number'];
                $row->series = $data['series'];
                $row->save();
            }

            return $row;
        });

        return response()->json(['data' => [
            'document_type' => $series->document_type, 'series' => $series->series,
            'next_number' => (int) $series->next_number, 'environment' => 'testing',
        ]]);
    }

    private function credentialsConfigured(): bool
    {
        $setting = ElectronicInvoiceSetting::query()->where('singleton_key', 'primary')->first();

        return $setting !== null
            && filled($setting->certificate_path)
            && Storage::disk('local')->exists($setting->certificate_path)
            && $setting->certificate_expires_at?->isFuture() === true;
    }

    private function seriesSummary(): array
    {
        return ElectronicDocumentSeries::query()->where('environment', 'testing')->get()
            ->map(fn ($series): array => [
                'document_type' => $series->document_type,
                'series' => $series->series,
                'next_number' => (int) $series->next_number,
            ])->all();
    }
}
