<?php

namespace App\Services;

use DOMDocument;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

class SunatXmlSignatureService
{
    public function signAndVerify(DOMDocument $document, string $pkcs12, string $password, string $issuerRuc, ?string $opensslConfig = null): string
    {
        $bundle = [];
        if (! openssl_pkcs12_read($pkcs12, $bundle, $password) || empty($bundle['cert']) || empty($bundle['pkey'])) {
            throw new \RuntimeException('Signing certificate is invalid.');
        }
        $parsed = openssl_x509_parse($bundle['cert']);
        $subject = json_encode($parsed['subject'] ?? [], JSON_UNESCAPED_UNICODE) ?: '';
        if (! str_contains($subject, $issuerRuc) || (int) ($parsed['validFrom_time_t'] ?? PHP_INT_MAX) > time() || (int) ($parsed['validTo_time_t'] ?? 0) <= time()) {
            throw new \RuntimeException('Signing certificate identity or validity check failed.');
        }
        $opensslOptions = filled($opensslConfig)
            ? ['config' => $opensslConfig]
            : [];
        if (! openssl_pkey_export($bundle['pkey'], $privatePem, null, $opensslOptions)) {
            throw new \RuntimeException('Signing key could not be loaded.');
        }
        $extension = $document->getElementsByTagNameNS('urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2', 'ExtensionContent')->item(0);
        if (! $extension) {
            throw new \RuntimeException('UBL signature extension is missing.');
        }
        $signature = new XMLSecurityDSig;
        $signature->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $signature->addReference($document, XMLSecurityDSig::SHA256, [XMLSecurityDSig::ENVELOPED]);
        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($privatePem, false);
        $signature->sign($key);
        $signature->add509Cert($bundle['cert']);
        $signature->appendSignature($extension);

        $verifyKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'public']);
        $verifyKey->loadKey($bundle['cert'], false, true);
        // xmlseclibs 4.x returns the verified reference nodes on success and
        // throws on a failed signature; it does not return integer 1 here.
        $verifiedReferences = $signature->verifyDocument($verifyKey, $document);
        if (! is_array($verifiedReferences) || $verifiedReferences === []) {
            throw new \RuntimeException('Generated XML signature did not verify against the uploaded certificate.');
        }

        $xml = $document->saveXML();
        if (! is_string($xml) || $xml === '') {
            throw new \RuntimeException('Signed XML could not be serialized.');
        }

        $serialized = new DOMDocument;
        $serialized->resolveExternals = false;
        $serialized->substituteEntities = false;
        // Preserve whitespace from the signed serialization. NOBLANKS changes
        // canonicalized reference data and can invalidate the signature.
        if (! $serialized->loadXML($xml, LIBXML_NONET)
            || ! $this->verifySerializedDocument($serialized, $bundle['cert'])) {
            throw new \RuntimeException('Generated XML signature did not verify after final serialization.');
        }

        return $xml;
    }

    public function verifySerializedDocument(DOMDocument $document, string $certificate): bool
    {
        $signature = new XMLSecurityDSig;
        if (! $signature->locateSignature($document)) {
            return false;
        }

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'public']);
        $key->loadKey($certificate, false, true);
        $references = $signature->verifyDocument($key, $document);

        return is_array($references) && $references !== [];
    }
}
