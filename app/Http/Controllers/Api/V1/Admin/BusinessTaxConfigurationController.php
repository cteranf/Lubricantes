<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessTaxConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessTaxConfigurationController extends Controller
{
    public function show(): array
    {
        return ['data' => $this->present(BusinessTaxConfiguration::query()->where('singleton_key', BusinessTaxConfiguration::SINGLETON_KEY)->first())];
    }

    public function update(Request $request): array
    {
        $data = $request->validate([
            'igv_rate' => ['required', 'regex:/\A(?:\d{1,3})(?:\.\d{1,2})?\z/'],
            'ipm_rate' => ['required', 'regex:/\A(?:\d{1,3})(?:\.\d{1,2})?\z/'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'prices_include_tax' => ['required', 'accepted'],
            'version' => ['required', 'integer', 'min:0'],
        ]);
        $igv = $this->basisPoints($data['igv_rate']);
        $ipm = $this->basisPoints($data['ipm_rate']);
        if ($igv < 0 || $ipm < 0 || $igv + $ipm <= 0 || $igv + $ipm > 10000) {
            throw ValidationException::withMessages(['igv_rate' => ['La suma de las tasas debe ser mayor que 0% y no superar 100%.']]);
        }

        $saved = DB::transaction(function () use ($data, $igv, $ipm): BusinessTaxConfiguration {
            BusinessTaxConfiguration::query()->insertOrIgnore([
                'singleton_key' => BusinessTaxConfiguration::SINGLETON_KEY,
                'prices_include_tax' => true,
                'shipping_treatment' => 'ancillary_to_goods',
                'is_configured' => false,
                'version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $setting = BusinessTaxConfiguration::query()->where('singleton_key', BusinessTaxConfiguration::SINGLETON_KEY)->lockForUpdate()->firstOrFail();
            abort_if((int) $data['version'] !== (int) $setting->version, 409, 'La configuración tributaria cambió. Recárgala antes de guardar.');
            $setting->fill([
                'igv_rate_basis_points' => $igv,
                'ipm_rate_basis_points' => $ipm,
                'effective_from' => $data['effective_from'],
                'prices_include_tax' => true,
                'shipping_treatment' => 'ancillary_to_goods',
                'is_configured' => true,
                'version' => $setting->version + 1,
            ])->save();

            return $setting;
        });

        return ['message' => 'Configuración tributaria guardada. Los productos conservan su afectación individual.', 'data' => $this->present($saved)];
    }

    private function present(?BusinessTaxConfiguration $configuration): array
    {
        $effective = $configuration?->is_configured
            && $configuration->effective_from?->startOfDay()->lessThanOrEqualTo(now()->startOfDay());

        return [
            'configured' => (bool) $effective,
            'scheduled' => (bool) ($configuration?->is_configured && ! $effective),
            'igv_rate' => $configuration?->igv_rate_basis_points === null ? null : $this->rateString($configuration->igv_rate_basis_points),
            'ipm_rate' => $configuration?->ipm_rate_basis_points === null ? null : $this->rateString($configuration->ipm_rate_basis_points),
            'total_rate' => $configuration?->igv_rate_basis_points === null || $configuration?->ipm_rate_basis_points === null ? null : $this->rateString($configuration->igv_rate_basis_points + $configuration->ipm_rate_basis_points),
            'effective_from' => $configuration?->effective_from?->format('Y-m-d'),
            'prices_include_tax' => (bool) ($configuration?->prices_include_tax ?? true),
            'shipping_treatment' => $configuration?->shipping_treatment ?? 'ancillary_to_goods',
            'version' => (int) ($configuration?->version ?? 0),
            'business_timezone' => (string) config('app.timezone'),
            'missing_fields' => $effective ? [] : ['igv_rate', 'ipm_rate', 'effective_from'],
        ];
    }

    private function basisPoints(string $rate): int
    {
        [$whole, $fraction] = array_pad(explode('.', $rate, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function rateString(int $basisPoints): string
    {
        return intdiv($basisPoints, 100).'.'.str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT);
    }
}
