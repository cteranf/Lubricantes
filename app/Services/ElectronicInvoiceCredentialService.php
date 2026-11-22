<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\ElectronicInvoiceSetting;
use App\Models\User;
use App\Support\AuditEventContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ElectronicInvoiceCredentialService
{
    public function __construct(private AuditService $audit) {}

    public function update(array $data, ?UploadedFile $certificate, User $actor): ElectronicInvoiceSetting
    {
        $newPath = null;
        $oldPath = null;
        $bundle = [];
        $expiry = null;
        if ($certificate) {
            $password = (string) ($data['certificate_password'] ?? '');
            $contents = file_get_contents($certificate->getRealPath());
            if (! is_string($contents) || ! openssl_pkcs12_read($contents, $bundle, $password)
                || empty($bundle['cert']) || empty($bundle['pkey'])) {
                throw ValidationException::withMessages(['certificate' => ['El certificado PKCS#12 no es válido o la contraseña no coincide.']]);
            }
            $parsed = openssl_x509_parse($bundle['cert']);
            $expiry = (int) ($parsed['validTo_time_t'] ?? 0);
            if ($expiry <= time() || (int) ($parsed['validFrom_time_t'] ?? PHP_INT_MAX) > time()) {
                throw ValidationException::withMessages(['certificate' => ['El certificado no está vigente.']]);
            }
            $profile = BusinessProfile::query()->where('singleton_key', BusinessProfile::SINGLETON_KEY)->first();
            $subject = json_encode($parsed['subject'] ?? [], JSON_UNESCAPED_UNICODE) ?: '';
            if (! $profile || ! preg_match('/\A\d{11}\z/', (string) $profile->tax_id_number)
                || ! str_contains($subject, (string) $profile->tax_id_number)) {
                throw ValidationException::withMessages(['certificate' => ['La identidad del certificado no coincide con el RUC configurado.']]);
            }
            $private = openssl_pkey_get_private($bundle['pkey'], $password);
            $public = openssl_pkey_get_public($bundle['cert']);
            $privateDetails = $private ? openssl_pkey_get_details($private) : false;
            $publicDetails = $public ? openssl_pkey_get_details($public) : false;
            if (! $privateDetails || ! $publicDetails || ! hash_equals($publicDetails['key'], $privateDetails['key'])) {
                throw ValidationException::withMessages(['certificate' => ['El certificado y su clave privada no corresponden.']]);
            }
        }

        try {
            $saved = DB::transaction(function () use ($data, $certificate, $bundle, $expiry, $actor, &$newPath, &$oldPath): ElectronicInvoiceSetting {
                ElectronicInvoiceSetting::query()->insertOrIgnore([
                    'singleton_key' => 'primary', 'connection_type' => 'direct_sunat', 'environment' => 'testing',
                    'version' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $setting = ElectronicInvoiceSetting::query()->where('singleton_key', 'primary')->lockForUpdate()->firstOrFail();
                abort_if((int) $setting->version !== (int) $data['version'], 409, 'La configuración cambió. Recarga antes de guardar.');
                abort_if(ElectronicInvoiceSetting::query()->whereExists(function ($query): void {
                    $query->selectRaw('1')->from('electronic_document_attempts')->whereIn('electronic_document_attempts.status', ['queued', 'processing', 'submitted', 'uncertain']);
                })->exists(), 409, 'No se pueden cambiar credenciales o certificado mientras haya un intento por resolver.');
                abort_if(($setting->connection_type !== $data['connection_type'] || $setting->environment !== $data['environment'])
                    && ElectronicInvoiceSetting::query()->where('singleton_key', 'primary')->whereExists(function ($query): void {
                        $query->selectRaw('1')->from('electronic_document_attempts')->whereColumn('electronic_document_attempts.status', '!=', 'accepted');
                    })->exists(), 409, 'No se puede cambiar el entorno mientras existan intentos sin resolver.');
                abort_if($data['environment'] !== 'testing', 422, 'La Fase 3 solo habilita el ambiente de pruebas.');
                abort_if($data['connection_type'] === 'provider_api', 422, 'El adaptador de proveedor todavía no está implementado.');

                $oldPath = $setting->certificate_path;
                $changedFields = [];
                foreach (['connection_type', 'provider_name', 'environment'] as $field) {
                    if (($setting->{$field} ?? null) !== ($data[$field] ?? null)) {
                        $changedFields[] = $field;
                    }
                }
                $setting->connection_type = 'direct_sunat';
                $setting->provider_name = null;
                $setting->environment = 'testing';
                if (filled($data['sunat_username'] ?? null)) {
                    $setting->sunat_username_encrypted = trim($data['sunat_username']);
                    $changedFields[] = 'sunat_credentials';
                }
                if (filled($data['sunat_password'] ?? null)) {
                    $setting->sunat_password_encrypted = $data['sunat_password'];
                    $changedFields[] = 'sunat_credentials';
                }
                if ($certificate) {
                    $newPath = $certificate->store('electronic-invoices/private', 'local');
                    if (! $newPath) {
                        throw new \RuntimeException('private certificate storage unavailable');
                    }
                    $setting->certificate_path = $newPath;
                    $setting->certificate_password_encrypted = $data['certificate_password'];
                    $setting->certificate_fingerprint = openssl_x509_fingerprint($bundle['cert'], 'sha256');
                    $setting->certificate_expires_at = date('Y-m-d H:i:s', $expiry);
                    $changedFields[] = 'signing_certificate';
                } elseif (filter_var($data['remove_certificate'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $setting->certificate_path = null;
                    $setting->certificate_password_encrypted = null;
                    $setting->certificate_fingerprint = null;
                    $setting->certificate_expires_at = null;
                    $changedFields[] = 'signing_certificate_removed';
                }
                $setting->version++;
                $setting->save();
                $this->audit->recordUser(
                    AuditEventContract::ELECTRONIC_INVOICE_SETTINGS_UPDATED,
                    $actor,
                    AuditEventContract::RESULT_SUCCEEDED,
                    auditableType: 'electronic_invoice_settings',
                    auditableId: 'primary',
                    metadata: ['changed_fields' => implode(',', array_unique($changedFields)) ?: 'connection_type']
                );

                return $setting;
            });
        } catch (\Throwable $exception) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $exception;
        }

        if ($oldPath && ($saved->certificate_path !== $oldPath)) {
            Storage::disk('local')->delete($oldPath);
        }

        return $saved;
    }
}
