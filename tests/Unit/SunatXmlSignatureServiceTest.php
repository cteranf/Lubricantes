<?php

namespace Tests\Unit;

use App\Services\SunatXmlSignatureService;
use DOMDocument;
use PHPUnit\Framework\TestCase;
use Tests\Support\SyntheticCertificate;

class SunatXmlSignatureServiceTest extends TestCase
{
    public function test_generated_signature_verifies_with_xmlseclibs_4_and_contains_certificate(): void
    {
        $syntheticCertificate = SyntheticCertificate::issue(['commonName' => '20123456789'], 'synthetic-pass');
        $pkcs12 = $syntheticCertificate['pkcs12'];

        $document = new DOMDocument('1.0', 'UTF-8');
        $this->assertTrue($document->loadXML(
            '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2"><ext:UBLExtensions><ext:UBLExtension><ext:ExtensionContent/></ext:UBLExtension></ext:UBLExtensions><ID>F001-00000001</ID></Invoice>'
        ));

        $signed = (new SunatXmlSignatureService)->signAndVerify($document, $pkcs12, 'synthetic-pass', '20123456789', $syntheticCertificate['openssl_config']);

        $this->assertStringContainsString('<ds:Signature', $signed);
        $this->assertStringContainsString('<ds:X509Certificate>', $signed);
        $this->assertSame('F001-00000001', $document->getElementsByTagName('ID')->item(0)?->textContent);
    }
}
