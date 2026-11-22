<?php

namespace Tests\Support;

use RuntimeException;

final class SyntheticCertificate
{
    /** @return array{pkcs12: string, certificate_pem: string, openssl_config: string} */
    public static function issue(array $subject, string $password): array
    {
        $configPath = tempnam(sys_get_temp_dir(), 'lubristore-openssl-test-');
        if ($configPath === false) {
            throw new RuntimeException('No se pudo crear la configuración temporal de OpenSSL para pruebas.');
        }

        $configuration = <<<'OPENSSL'
openssl_conf = openssl_init

[openssl_init]
providers = provider_sect

[provider_sect]
default = default_sect

[default_sect]
activate = 1

[req]
default_bits = 2048
default_md = sha256
distinguished_name = req_distinguished_name
prompt = no

[req_distinguished_name]
CN = LubriStore Synthetic QA Certificate
OPENSSL;

        if (file_put_contents($configPath, $configuration) === false) {
            throw new RuntimeException('No se pudo escribir la configuración temporal de OpenSSL para pruebas.');
        }
        @chmod($configPath, 0600);
        register_shutdown_function(static function () use ($configPath): void {
            if (is_file($configPath)) {
                unlink($configPath);
            }
        });

        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
            'config' => $configPath,
        ]);
        if ($privateKey === false) {
            throw new RuntimeException('No se pudo crear la clave sintética de prueba.');
        }

        $request = openssl_csr_new($subject, $privateKey, ['digest_alg' => 'sha256', 'config' => $configPath]);
        if ($request === false) {
            throw new RuntimeException('No se pudo crear la solicitud sintética de certificado.');
        }

        $certificate = openssl_csr_sign($request, null, $privateKey, 2, ['digest_alg' => 'sha256', 'config' => $configPath]);
        if ($certificate === false) {
            throw new RuntimeException('No se pudo firmar el certificado sintético de prueba.');
        }

        $pkcs12 = '';
        $certificatePem = '';
        if (! openssl_pkcs12_export($certificate, $pkcs12, $privateKey, $password)
            || ! openssl_x509_export($certificate, $certificatePem)) {
            throw new RuntimeException('No se pudo exportar el certificado sintético de prueba.');
        }

        return ['pkcs12' => $pkcs12, 'certificate_pem' => $certificatePem, 'openssl_config' => $configPath];
    }
}
