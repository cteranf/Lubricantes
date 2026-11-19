<?php

namespace App\Http\Resources;

use App\Models\BusinessProfile;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AdminBusinessProfileResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var BusinessProfile|null $profile */
        $profile = $this->resource;
        $pending = [];
        foreach ([
            'commercial_name' => 'Nombre comercial',
            'legal_name' => 'Razón social o nombre legal',
            'tax_id_type' => 'Tipo de identificación tributaria',
            'tax_id_number' => 'Número de identificación tributaria',
            'fiscal_address' => 'Dirección fiscal',
            'contact_email' => 'Correo de contacto',
            'contact_phone' => 'Teléfono de contacto',
        ] as $field => $label) {
            if (! $profile || blank($profile->{$field})) {
                $pending[] = ['field' => $field, 'label' => $label];
            }
        }

        $disk = Storage::disk('public');
        $logoPath = $profile?->logo_path;
        $logoUrl = is_string($logoPath)
            && preg_match('#\Abusiness/logos/[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(?:webp|png|jpg)\z#iD', $logoPath) === 1
            && $disk->exists($logoPath)
            ? $disk->url($logoPath)
            : null;

        return [
            'profile' => $profile ? [
                'id' => (int) $profile->id,
                'commercial_name' => $profile->commercial_name,
                'legal_name' => $profile->legal_name,
                'tax_id_type' => $profile->tax_id_type,
                'tax_id_number' => $profile->tax_id_number,
                'country_code' => $profile->country_code,
                'fiscal_address' => $profile->fiscal_address,
                'contact_email' => $profile->contact_email,
                'contact_phone' => $profile->contact_phone,
                'logo_url' => $logoUrl,
                'created_at' => $profile->created_at?->toISOString(),
                'updated_at' => $profile->updated_at?->toISOString(),
            ] : null,
            'defaults' => ['country_code' => 'PE', 'tax_id_type' => 'RUC'],
            'receipt_settings' => [
                'boleta_enabled' => (bool) ($profile?->boleta_enabled ?? true),
                'factura_enabled' => (bool) ($profile?->factura_enabled ?? false),
                'default_receipt_type' => $profile?->default_receipt_type ?? 'boleta',
            ],
            'pending_fields' => $pending,
            'operational_settings' => [
                'currency' => (string) config('payment.currency', 'PEN'),
                'currency_source' => 'config/payment.php',
                'timezone' => (string) config('app.timezone', 'UTC'),
                'timezone_source' => 'config/app.php',
            ],
            'logo_limits' => [
                'max_kilobytes' => (int) config('brand_images.max_kilobytes', 2048),
                'max_width' => (int) config('brand_images.max_width', 4000),
                'max_height' => (int) config('brand_images.max_height', 2500),
            ],
        ];
    }
}
