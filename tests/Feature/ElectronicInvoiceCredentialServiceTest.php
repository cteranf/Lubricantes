<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\ElectronicInvoiceSetting;
use App\Models\User;
use App\Services\ElectronicInvoiceCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\SyntheticCertificate;
use Tests\TestCase;

class ElectronicInvoiceCredentialServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_synthetic_pkcs12_is_stored_privately_and_its_secrets_remain_encrypted(): void
    {
        Storage::fake('local');
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        BusinessProfile::create([
            'singleton_key' => 'primary', 'commercial_name' => 'QA', 'legal_name' => 'QA SAC',
            'tax_id_type' => 'RUC', 'tax_id_number' => '20123456789', 'country_code' => 'PE',
            'fiscal_address' => 'Dirección de prueba',
        ]);
        $pkcs12 = $this->pkcs12('20123456789');
        $path = tempnam(sys_get_temp_dir(), 'sunat-cert-');
        $this->assertNotFalse($path);

        try {
            file_put_contents($path, $pkcs12);
            $upload = new UploadedFile($path, 'synthetic.p12', 'application/x-pkcs12', null, true);
            $saved = app(ElectronicInvoiceCredentialService::class)->update([
                'connection_type' => 'direct_sunat', 'environment' => 'testing', 'version' => 1,
                'sunat_username' => 'synthetic-user', 'sunat_password' => 'synthetic-sol-secret',
                'certificate_password' => 'synthetic-cert-secret', 'remove_certificate' => false,
            ], $upload, $actor);

            $this->assertNotSame('', (string) $saved->certificate_path);
            Storage::disk('local')->assertExists($saved->certificate_path);
            $this->assertSame('synthetic-user', $saved->sunat_username_encrypted);
            $this->assertSame('synthetic-sol-secret', $saved->sunat_password_encrypted);
            $this->assertSame('synthetic-cert-secret', $saved->certificate_password_encrypted);
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $saved->certificate_fingerprint);
            $this->assertNotSame('synthetic-sol-secret', $saved->getRawOriginal('sunat_password_encrypted'));
            $this->assertDatabaseCount('electronic_document_attempts', 0);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_wrong_pkcs12_password_is_rejected_without_creating_settings_or_files(): void
    {
        Storage::fake('local');
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        BusinessProfile::create([
            'singleton_key' => 'primary', 'commercial_name' => 'QA', 'legal_name' => 'QA SAC',
            'tax_id_type' => 'RUC', 'tax_id_number' => '20123456789', 'country_code' => 'PE',
            'fiscal_address' => 'Dirección de prueba',
        ]);
        $path = tempnam(sys_get_temp_dir(), 'sunat-cert-');
        $this->assertNotFalse($path);

        try {
            file_put_contents($path, $this->pkcs12('20123456789'));
            $upload = new UploadedFile($path, 'synthetic.p12', 'application/x-pkcs12', null, true);
            try {
                app(ElectronicInvoiceCredentialService::class)->update([
                    'connection_type' => 'direct_sunat', 'environment' => 'testing', 'version' => 1,
                    'certificate_password' => 'incorrect', 'remove_certificate' => false,
                ], $upload, $actor);
                $this->fail('La contraseña incorrecta del PKCS#12 debe rechazarse.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('certificate', $exception->errors());
            }

            $this->assertSame(0, ElectronicInvoiceSetting::query()->count());
            $this->assertSame([], Storage::disk('local')->allFiles('electronic-invoices/private'));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function pkcs12(string $ruc): string
    {
        $certificate = SyntheticCertificate::issue([
            'commonName' => 'LubriStore QA synthetic',
            'organizationalUnitName' => $ruc,
        ], 'synthetic-cert-secret');

        return $certificate['pkcs12'];
    }
}
