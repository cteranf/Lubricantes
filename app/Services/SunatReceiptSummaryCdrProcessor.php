<?php

namespace App\Services;

use App\Models\ElectronicReceiptSummary;
use DOMDocument;
use DOMElement;
use DOMNode;

/** Validates a Beta summary CDR without extracting untrusted archive entries. */
class SunatReceiptSummaryCdrProcessor
{
    private const MAX_ZIP_BYTES = 6 * 1024 * 1024;

    private const MAX_XML_BYTES = 2 * 1024 * 1024;

    private const MAX_ENTRIES = 20;

    private const APP_RESPONSE_NS = 'urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2';

    private const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const CAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const DS_NS = 'http://www.w3.org/2000/09/xmldsig#';

    public function __construct(private SunatSchemaBundle $schemas, private SunatXmlSignatureService $signer) {}

    /** @return array{type:string, code:string, message:string, observations:?string, xml:string} */
    public function process(ElectronicReceiptSummary $summary, string $zipBytes): array
    {
        if ($zipBytes === '' || strlen($zipBytes) > self::MAX_ZIP_BYTES) {
            throw new \DomainException('La CDR no está disponible o supera el tamaño permitido.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'sunat_cdr_');
        if ($tmp === false) {
            throw new \RuntimeException('No se pudo preparar la validación privada de la CDR.');
        }
        try {
            if (file_put_contents($tmp, $zipBytes) !== strlen($zipBytes)) {
                throw new \RuntimeException('No se pudo leer el ZIP de CDR recibido.');
            }
            $zip = new \ZipArchive;
            if ($zip->open($tmp) !== true || $zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
                throw new \DomainException('El ZIP de CDR no tiene una estructura válida.');
            }
            try {
                $xmlEntries = [];
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $name = $zip->getNameIndex($index);
                    $stat = $zip->statIndex($index);
                    if (! is_string($name) || str_contains($name, '..') || str_starts_with($name, '/')
                        || str_contains($name, '\\') || preg_match('/\A[A-Za-z0-9._-]+\.xml\z/iD', $name) !== 1
                        || ! is_array($stat) || ($stat['size'] ?? PHP_INT_MAX) < 1 || ($stat['size'] ?? PHP_INT_MAX) > self::MAX_XML_BYTES) {
                        throw new \DomainException('El ZIP de CDR contiene una ruta o entrada no permitida.');
                    }
                    $xmlEntries[] = $index;
                }
                if (count($xmlEntries) !== 1) {
                    throw new \DomainException('La respuesta no contiene exactamente una CDR XML.');
                }
                $cdrName = $zip->getNameIndex($xmlEntries[0]);
                $base = $summary->issuer_tax_id.'-'.$summary->document_id;
                if (! is_string($cdrName) || strcasecmp($cdrName, 'R-'.$base.'.xml') !== 0) {
                    throw new \DomainException('El nombre de la CDR no corresponde al archivo del resumen enviado.');
                }
                $stream = $zip->getStream($cdrName);
                if (! is_resource($stream)) {
                    throw new \DomainException('No se pudo leer la entrada XML de la CDR.');
                }
                try {
                    $xmlBytes = '';
                    while (! feof($stream) && strlen($xmlBytes) <= self::MAX_XML_BYTES) {
                        $chunk = fread($stream, min(8192, self::MAX_XML_BYTES + 1 - strlen($xmlBytes)));
                        if (! is_string($chunk) || $chunk === '') {
                            break;
                        }
                        $xmlBytes .= $chunk;
                    }
                } finally {
                    fclose($stream);
                }
                if (strlen($xmlBytes) > self::MAX_XML_BYTES) {
                    throw new \DomainException('El XML expandido de la CDR excede el límite permitido.');
                }
            } finally {
                $zip->close();
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }

        if (! is_string($xmlBytes) || $xmlBytes === '' || strlen($xmlBytes) > self::MAX_XML_BYTES
            || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xmlBytes) === 1) {
            throw new \DomainException('La CDR XML está vacía, excede el límite o contiene entidades no permitidas.');
        }
        $xml = new DOMDocument;
        $xml->resolveExternals = false;
        $xml->substituteEntities = false;
        // A signed CDR must be verified against the exact parsed node set.
        if (! $xml->loadXML($xmlBytes, LIBXML_NONET)
            || $xml->documentElement?->namespaceURI !== self::APP_RESPONSE_NS
            || $xml->documentElement?->localName !== 'ApplicationResponse') {
            throw new \DomainException('La respuesta no es una CDR UBL ApplicationResponse válida.');
        }
        $xsd = (string) config('electronic_invoicing.sunat.cdr_xsd');
        if ($xsd === '' || ! $this->schemas->validateWith($xml, $xsd)) {
            throw new \DomainException('La CDR no supera el esquema ApplicationResponse configurado.');
        }
        $certificate = $this->embeddedCertificate($xml);
        $fingerprint = strtoupper(str_replace(':', '', (string) openssl_x509_fingerprint($certificate, 'sha256')));
        $expectedFingerprint = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string) config('electronic_invoicing.receipt_beta.cdr_certificate_sha256')) ?? '');
        if ($expectedFingerprint === '' || ! hash_equals($expectedFingerprint, $fingerprint)) {
            throw new \DomainException('La firma de CDR no coincide con el certificado Beta fijado; el resultado permanece incierto.');
        }
        if (! $this->signer->verifySerializedDocument($xml, $certificate)) {
            throw new \DomainException('La firma digital de la CDR no es válida.');
        }

        $receiverRuc = $this->partyId($xml, 'ReceiverParty');
        if ($receiverRuc !== $summary->issuer_tax_id) {
            throw new \DomainException('La CDR pertenece a otro emisor.');
        }
        $responses = $xml->getElementsByTagNameNS(self::CAC_NS, 'DocumentResponse');
        if ($responses->length !== 1) {
            throw new \DomainException('La CDR no contiene una respuesta única para el resumen enviado.');
        }
        $response = $responses->item(0);
        if (! $response instanceof DOMElement) {
            throw new \DomainException('La estructura de respuesta CDR no es válida.');
        }
        $reference = $this->firstText($response, self::CBC_NS, 'ReferenceID');
        $documentReference = $this->documentReferenceId($response);
        $expectedReference = $summary->document_id;
        if ($reference !== $expectedReference || $documentReference !== $expectedReference) {
            throw new \DomainException('La CDR no corresponde al identificador inmutable del resumen.');
        }
        $code = $this->firstText($response, self::CBC_NS, 'ResponseCode');
        if ($code === '' || preg_match('/\A\d{1,10}\z/D', $code) !== 1) {
            throw new \DomainException('La CDR no contiene un código de resultado válido.');
        }
        $description = $this->safeText($this->firstText($response, self::CBC_NS, 'Description'));
        $notes = [];
        foreach ($xml->getElementsByTagNameNS(self::CBC_NS, 'Note') as $note) {
            $value = $this->safeText(trim($note->textContent));
            if ($value !== '') {
                $notes[] = $value;
            }
        }
        $type = $code !== '0' ? 'rejected' : ($notes !== [] ? 'accepted_observed' : 'accepted');

        return [
            'type' => $type,
            'code' => $code,
            'message' => $description !== '' ? $description : ($type === 'rejected' ? 'SUNAT rechazó el resumen.' : 'SUNAT aceptó el resumen.'),
            'observations' => $notes === [] ? null : implode("\n", $notes),
            'xml' => $xmlBytes,
        ];
    }

    private function embeddedCertificate(DOMDocument $xml): string
    {
        $nodes = $xml->getElementsByTagNameNS(self::DS_NS, 'X509Certificate');
        if ($nodes->length !== 1) {
            throw new \DomainException('La CDR no contiene un certificado de firma único.');
        }
        $der = base64_decode(preg_replace('/\s+/', '', trim($nodes->item(0)->textContent)) ?? '', true);
        if (! is_string($der) || $der === '') {
            throw new \DomainException('El certificado incluido en la CDR no es válido.');
        }

        return "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
    }

    private function partyId(DOMDocument $xml, string $partyName): string
    {
        $parties = $xml->getElementsByTagNameNS(self::CAC_NS, $partyName);
        if ($parties->length !== 1 || ! $parties->item(0) instanceof DOMElement) {
            return '';
        }

        return $this->firstText($parties->item(0), self::CBC_NS, 'ID');
    }

    private function documentReferenceId(DOMElement $response): string
    {
        $references = $response->getElementsByTagNameNS(self::CAC_NS, 'DocumentReference');
        if ($references->length !== 1 || ! $references->item(0) instanceof DOMElement) {
            return '';
        }

        return $this->firstText($references->item(0), self::CBC_NS, 'ID');
    }

    private function firstText(DOMNode $node, string $namespace, string $name): string
    {
        $nodes = $node->getElementsByTagNameNS($namespace, $name);

        return $nodes->length ? trim($nodes->item(0)->textContent) : '';
    }

    private function safeText(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', strip_tags($value)) ?? '';

        return mb_substr(trim($value), 0, 500);
    }
}
