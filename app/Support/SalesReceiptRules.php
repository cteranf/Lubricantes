<?php

namespace App\Support;

use App\Models\BusinessProfile;
use Illuminate\Validation\ValidationException;

class SalesReceiptRules
{
    public const BOLETA_IDENTIFICATION_THRESHOLD_MINOR = 70000;

    public const IDENTIFICATION_TYPES = [
        'DNI' => 'DNI',
        'CE' => 'Carné de extranjería',
        'RUC' => 'RUC',
        'PASSPORT' => 'Pasaporte',
    ];

    public function settings(bool $lock = false): array
    {
        $query = BusinessProfile::query()->where('singleton_key', BusinessProfile::SINGLETON_KEY);
        if ($lock) {
            $query->lockForUpdate();
        }
        $profile = $query->first();
        $boleta = (bool) ($profile?->boleta_enabled ?? true);
        $factura = (bool) ($profile?->factura_enabled ?? false);
        $default = $profile?->default_receipt_type ?? 'boleta';
        if (! in_array($default, ['boleta', 'factura'], true) || ! (($default === 'boleta' && $boleta) || ($default === 'factura' && $factura))) {
            $default = $boleta ? 'boleta' : 'factura';
        }

        return [
            'enabled_types' => array_values(array_filter([
                $boleta ? ['value' => 'boleta', 'label' => 'Boleta de venta'] : null,
                $factura ? ['value' => 'factura', 'label' => 'Factura'] : null,
            ])),
            'default_type' => $default,
            'boleta_identification_threshold' => '700.00',
            'boleta_identification_required_above_threshold' => true,
            'identification_types' => collect(self::IDENTIFICATION_TYPES)
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values()->all(),
            'version' => hash('sha256', implode('|', ['receipt-settings-v1', (int) $boleta, (int) $factura, $default])),
        ];
    }

    /** @return array<string, ?string> */
    public function validateRequest(array $input, int $totalMinor, array $settings): array
    {
        $enabled = array_column($settings['enabled_types'], 'value');
        $type = $input['type'] ?? null;
        if (! is_string($type) || ! in_array($type, $enabled, true)) {
            throw ValidationException::withMessages(['receipt_request.type' => ['El tipo de comprobante cambió o no está habilitado. Actualiza las opciones y vuelve a revisar.']]);
        }

        $identityType = $input['identity_type'] ?? null;
        $identityNumber = isset($input['identity_number']) ? trim((string) $input['identity_number']) : null;
        $recipientName = isset($input['recipient_name']) ? trim((string) $input['recipient_name']) : null;
        $billingAddress = isset($input['billing_address']) ? trim((string) $input['billing_address']) : null;
        $email = isset($input['email']) ? trim((string) $input['email']) : null;
        $identityRequired = $type === 'factura' || ($type === 'boleta' && $totalMinor > self::BOLETA_IDENTIFICATION_THRESHOLD_MINOR);

        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            throw ValidationException::withMessages(['receipt_request.email' => ['Ingresa un correo válido para recibir el comprobante solicitado.']]);
        }

        if ($type === 'factura') {
            if ($identityType !== 'RUC' || ! is_string($identityNumber) || ! preg_match('/\A[0-9]{11}\z/D', $identityNumber)) {
                throw ValidationException::withMessages(['receipt_request.identity_number' => ['La factura requiere un RUC de 11 dígitos.']]);
            }
            if (! $recipientName || mb_strlen($recipientName) > 255) {
                throw ValidationException::withMessages(['receipt_request.recipient_name' => ['Ingresa la razón social o nombre legal del receptor.']]);
            }
            if (! $billingAddress || mb_strlen($billingAddress) > 255) {
                throw ValidationException::withMessages(['receipt_request.billing_address' => ['Ingresa la dirección de facturación.']]);
            }
        } elseif ($identityNumber !== null && $identityNumber !== '') {
            if (! isset(self::IDENTIFICATION_TYPES[$identityType])) {
                throw ValidationException::withMessages(['receipt_request.identity_type' => ['Selecciona un tipo de identificación admitido.']]);
            }
            $valid = match ($identityType) {
                'DNI' => (bool) preg_match('/\A[0-9]{8}\z/D', $identityNumber),
                'RUC' => (bool) preg_match('/\A[0-9]{11}\z/D', $identityNumber),
                'CE' => (bool) preg_match('/\A[A-Za-z0-9-]{5,20}\z/D', $identityNumber),
                'PASSPORT' => (bool) preg_match('/\A[A-Za-z0-9-]{5,20}\z/D', $identityNumber),
            };
            if (! $valid) {
                throw ValidationException::withMessages(['receipt_request.identity_number' => ['El formato o longitud del documento no es válido. Esta comprobación es local y no consulta SUNAT ni RENIEC.']]);
            }
            if (! $recipientName || mb_strlen($recipientName) > 255) {
                throw ValidationException::withMessages(['receipt_request.recipient_name' => ['Ingresa el nombre del titular del documento.']]);
            }
        } elseif ($type === 'boleta' && $totalMinor > self::BOLETA_IDENTIFICATION_THRESHOLD_MINOR) {
            throw ValidationException::withMessages(['receipt_request.identity_number' => ['Para este importe, la boleta requiere identificación del comprador.']]);
        }

        return [
            'requested_receipt_type' => $type,
            'requested_identity_type' => $identityNumber !== null && $identityNumber !== '' ? $identityType : null,
            'requested_identity_number' => $identityNumber !== '' ? $identityNumber : null,
            'requested_identity_required' => $identityRequired,
            'requested_recipient_name' => $recipientName !== '' ? $recipientName : null,
            'requested_billing_address' => $type === 'factura' ? $billingAddress : null,
            'requested_receipt_email' => $email,
        ];
    }
}
