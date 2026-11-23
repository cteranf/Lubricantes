<?php

namespace App\Services;

use App\Contracts\ElectronicReceiptSummaryTransport;
use App\Models\ElectronicInvoiceSetting;
use App\Models\ElectronicReceiptSummary;
use App\Models\ElectronicReceiptSummaryAttempt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\ResponseInterface;

/** Explicitly enabled, Beta-only SOAP 1.1 transport for daily receipt summaries. */
class SunatBetaElectronicReceiptSummaryTransport implements ElectronicReceiptSummaryTransport
{
    private const SERVICE_NS = 'http://service.sunat.gob.pe';

    private const SOAP_NS = 'http://schemas.xmlsoap.org/soap/envelope/';

    private const WSSE_NS = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

    private const MAX_REQUEST_ZIP_BYTES = 10 * 1024 * 1024;

    private const MAX_RESPONSE_BYTES = 6 * 1024 * 1024;

    public function __construct(
        private SunatSchemaBundle $schemas,
        private SunatXmlSignatureService $signer,
    ) {}

    public function sendSummary(ElectronicReceiptSummary $summary, string $zipBytes, ?callable $onTransmissionStart = null): array
    {
        $settings = $this->assertEnabled($summary);
        $payload = $this->validateStoredPackage($summary, $zipBytes, $settings);
        $response = $this->request($settings, 'sendSummary', [
            'fileName' => $payload['file_name'],
            'contentFile' => base64_encode($zipBytes),
        ], $onTransmissionStart);
        $requestEvidence = [
            'request_endpoint' => $settings['endpoint'],
            'request_action' => 'urn:sendSummary',
            'request_content_type' => 'text/xml; charset=utf-8',
            'request_file_name' => $payload['file_name'],
            'request_zip_bytes' => strlen($zipBytes),
            'request_zip_sha256' => hash('sha256', $zipBytes),
            'request_content_file_base64_bytes' => strlen(base64_encode($zipBytes)),
        ];
        if (isset($response['type'])) {
            return [...$response, ...$requestEvidence];
        }

        $ticket = trim($this->text($response['xml'], 'ticket'));
        if ($ticket === '' || strlen($ticket) > 120 || preg_match('/\A[0-9A-Za-z._-]+\z/D', $ticket) !== 1
            || str_starts_with($ticket, 'LOCAL-SIM-')) {
            return $this->result('uncertain', 'external_ticket_invalid', 'SUNAT no devolvió un ticket externo interpretable.', $response['raw']);
        }

        return $this->result('ticket', 'external_ticket_received', 'SUNAT Beta devolvió un ticket externo; el procesamiento sigue pendiente.', $response['raw'], [
            'external_ticket' => $ticket,
            ...$requestEvidence,
        ]);
    }

    public function query(string $identifier, int $queryNumber): array
    {
        abort_if(str_starts_with($identifier, 'LOCAL-SIM-'), 409, 'Un identificador LOCAL-SIM nunca se envía a SUNAT.');
        abort_unless($queryNumber > 0 && preg_match('/\A[0-9A-Za-z._-]{1,120}\z/D', $identifier) === 1, 409, 'No hay un ticket externo válido para consultar.');
        $attempt = ElectronicReceiptSummaryAttempt::query()->with('summary')->where('external_ticket', $identifier)->first();
        abort_unless($attempt?->summary !== null && $attempt->summary->environment === 'testing', 404, 'El ticket externo no corresponde a un resumen Beta persistido.');
        abort_unless(hash_equals((string) $attempt->summary->external_ticket, $identifier), 409, 'El ticket del intento no coincide con el ticket externo del resumen.');
        $settings = $this->assertEnabled($attempt->summary);
        $response = $this->request($settings, 'getStatus', ['ticket' => $identifier]);
        if (isset($response['type'])) {
            return $response;
        }

        $status = trim($this->text($response['xml'], 'statusCode'));
        if ($status === '98') {
            return $this->result('pending', 'sunat_processing_pending', 'SUNAT informa que el resumen continúa en proceso.', $response['raw'], ['external_ticket' => $identifier]);
        }
        if (! in_array($status, ['0', '99'], true)) {
            return $this->result('uncertain', 'sunat_status_unrecognized', 'SUNAT devolvió un estado de procesamiento no reconocido; conserve el ticket y la numeración.', $response['raw'], ['external_ticket' => $identifier]);
        }

        $content = $this->text($response['xml'], 'content');
        $cdrZip = base64_decode(preg_replace('/\s+/', '', $content) ?? '', true);
        if (! is_string($cdrZip) || $cdrZip === '' || strlen($cdrZip) > self::MAX_RESPONSE_BYTES) {
            return $this->result('uncertain', 'sunat_cdr_missing_or_excessive', 'La consulta terminó sin una CDR acotada e interpretable; el resultado permanece incierto.', $response['raw'], ['external_ticket' => $identifier, 'sunat_status_code' => $status]);
        }

        return $this->result('cdr', 'sunat_cdr_received', 'SUNAT devolvió un paquete CDR pendiente de validación y correspondencia.', $response['raw'], [
            'external_ticket' => $identifier,
            'sunat_status_code' => $status,
            'cdr_zip' => $cdrZip,
        ]);
    }

    /** @return array<string, mixed> */
    private function assertEnabled(?ElectronicReceiptSummary $summary = null): array
    {
        abort_unless(app()->environment('testing', 'local'), 409, 'El transporte SUNAT Beta está bloqueado fuera de QA local.');
        abort_unless((bool) config('electronic_invoicing.receipt_beta.enabled'), 409, 'El transporte externo de resúmenes está deshabilitado.');
        $endpoint = (string) config('electronic_invoicing.receipt_beta.endpoint');
        abort_unless($endpoint === 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService', 409, 'El endpoint del transporte de resúmenes no coincide con el endpoint Beta permitido.');

        $setting = ElectronicInvoiceSetting::query()->where('singleton_key', 'primary')->first();
        abort_unless($setting !== null && $setting->environment === 'testing' && $setting->connection_type === 'direct_sunat', 409,
            'El transporte de resúmenes requiere el entorno de prueba y el modo directo configurados.');
        if ($summary !== null) {
            abort_unless($summary->environment === 'testing' && $summary->issuer_tax_id !== '', 409, 'Solo se pueden enviar resúmenes de prueba a Beta.');
        }

        $username = filled($setting->sunat_username_encrypted)
            ? (string) $setting->sunat_username_encrypted
            : (string) ($summary?->issuer_tax_id ?? '').'MODDATOS';
        $password = filled($setting->sunat_password_encrypted) ? (string) $setting->sunat_password_encrypted : 'MODDATOS';
        if ($summary !== null) {
            abort_unless(hash_equals($summary->issuer_tax_id.'MODDATOS', $username), 409, 'El usuario de SUNAT Beta debe corresponder al RUC emisor y al formato MODDATOS.');
        }
        abort_unless($username !== 'MODDATOS' && $password !== '', 409, 'Faltan credenciales Beta configuradas para el emisor.');

        return ['username' => $username, 'password' => $password, 'endpoint' => $endpoint];
    }

    /** @return array{file_name: string} */
    private function validateStoredPackage(ElectronicReceiptSummary $summary, string $zipBytes, array $settings): array
    {
        $expectedBase = $summary->issuer_tax_id.'-'.$summary->document_id;
        $folder = 'electronic-invoices/summaries/'.$summary->id;
        $expectedXmlPath = $folder.'/'.$expectedBase.'.xml';
        $expectedZipPath = $folder.'/'.$expectedBase.'.zip';
        abort_unless($summary->xml_path === $expectedXmlPath && $summary->zip_path === $expectedZipPath, 409, 'El paquete del resumen no usa las rutas privadas esperadas.');
        abort_unless(strlen($zipBytes) > 0 && strlen($zipBytes) <= self::MAX_REQUEST_ZIP_BYTES, 422, 'El ZIP del resumen está vacío o supera el límite permitido.');
        $disk = Storage::disk('local');
        abort_unless($disk->exists($expectedXmlPath) && $disk->exists($expectedZipPath), 409, 'Faltan los archivos privados del resumen firmado.');
        $storedZip = $disk->get($expectedZipPath);
        $xmlBytes = $disk->get($expectedXmlPath);
        abort_unless(hash_equals(hash('sha256', $storedZip), hash('sha256', $zipBytes)), 409, 'El ZIP a transmitir no coincide con la copia privada guardada.');
        abort_unless(is_string($xmlBytes) && $xmlBytes !== '' && strlen($xmlBytes) <= self::MAX_REQUEST_ZIP_BYTES
            && hash_equals((string) $summary->xml_hash, hash('sha256', $xmlBytes)), 409, 'El XML firmado no coincide con su hash persistido.');

        $tmp = tempnam(sys_get_temp_dir(), 'receipt_beta_');
        abort_unless($tmp !== false, 503, 'No se pudo validar el ZIP privado del resumen.');
        try {
            if (file_put_contents($tmp, $zipBytes) !== strlen($zipBytes)) {
                abort(503, 'No se pudo leer el ZIP privado del resumen.');
            }
            $zip = new \ZipArchive;
            abort_unless($zip->open($tmp) === true, 422, 'El ZIP del resumen no es válido.');
            try {
                $xmlIndex = $zip->locateName($expectedBase.'.xml');
                abort_unless($zip->numFiles === 2 && $zip->locateName('dummy/') !== false && $xmlIndex !== false,
                    422, 'El ZIP debe contener dummy/ y el único XML firmado con el nombre esperado.');
                $stat = $zip->statIndex($xmlIndex);
                abort_unless(is_array($stat) && ($stat['size'] ?? PHP_INT_MAX) === strlen($xmlBytes)
                    && ($stat['size'] ?? PHP_INT_MAX) <= self::MAX_REQUEST_ZIP_BYTES, 422, 'El contenido expandido del ZIP no coincide o excede el límite.');
                $archivedXml = $zip->getFromIndex($xmlIndex);
                abort_unless(is_string($archivedXml) && hash_equals(hash('sha256', $xmlBytes), hash('sha256', $archivedXml)), 422, 'El XML dentro del ZIP no coincide con el XML firmado persistido.');
            } finally {
                $zip->close();
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }

        $xml = $this->safeXml($xmlBytes);
        abort_unless($xml->documentElement?->localName === 'SummaryDocuments'
            && $xml->getElementsByTagNameNS('urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2', 'ID')->item(0)?->textContent === $summary->document_id,
            422, 'El XML firmado no corresponde al resumen confirmado.');
        abort_unless($this->schemas->validateWith($xml, (string) config('electronic_invoicing.sunat.summary_xsd')), 422, 'El XML del resumen no supera el XSD configurado.');

        $certificate = $settings['certificate'] ?? null;
        if (! is_string($certificate)) {
            $setting = ElectronicInvoiceSetting::query()->where('singleton_key', 'primary')->firstOrFail();
            abort_unless(filled($setting->certificate_path) && $disk->exists((string) $setting->certificate_path), 409, 'No se encontró el certificado usado para la firma local.');
            $pkcs12 = $disk->get((string) $setting->certificate_path);
            $bundle = [];
            abort_unless(is_string($pkcs12) && openssl_pkcs12_read($pkcs12, $bundle, (string) $setting->certificate_password_encrypted) && isset($bundle['cert']), 409, 'No se pudo verificar el certificado de firma del resumen.');
            $certificate = $bundle['cert'];
        }
        abort_unless($this->signer->verifySerializedDocument($xml, $certificate), 422, 'La firma serializada del resumen no es válida.');

        return ['file_name' => $expectedBase.'.zip'];
    }

    private function safeXml(string $xmlBytes): \DOMDocument
    {
        abort_if(strlen($xmlBytes) > self::MAX_REQUEST_ZIP_BYTES || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xmlBytes) === 1, 422, 'El XML contiene una declaración no permitida o supera el límite.');
        $xml = new \DOMDocument;
        $xml->resolveExternals = false;
        $xml->substituteEntities = false;
        // Preserve signed whitespace: removing blank text nodes changes canonicalization.
        abort_unless($xml->loadXML($xmlBytes, LIBXML_NONET), 422, 'El XML del resumen no es válido.');

        return $xml;
    }

    /** @param array<string, string> $values */
    private function request(array $settings, string $operation, array $values, ?callable $onTransmissionStart = null): array
    {
        $body = '';
        foreach ($values as $name => $value) {
            $body .= '<ser:'.$name.'>'.htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8').'</ser:'.$name.'>';
        }
        $soap = '<soapenv:Envelope xmlns:soapenv="'.self::SOAP_NS.'" xmlns:ser="'.self::SERVICE_NS.'" xmlns:wsse="'.self::WSSE_NS.'">'
            .'<soapenv:Header><wsse:Security soapenv:mustUnderstand="1"><wsse:UsernameToken><wsse:Username>'
            .htmlspecialchars($settings['username'], ENT_XML1 | ENT_COMPAT, 'UTF-8').'</wsse:Username><wsse:Password>'
            .htmlspecialchars($settings['password'], ENT_XML1 | ENT_COMPAT, 'UTF-8').'</wsse:Password></wsse:UsernameToken></wsse:Security></soapenv:Header>'
            .'<soapenv:Body><ser:'.$operation.'>'.$body.'</ser:'.$operation.'></soapenv:Body></soapenv:Envelope>';
        try {
            $pendingRequest = Http::withOptions([
                'allow_redirects' => false,
                'stream' => true,
                'on_headers' => static function (ResponseInterface $response): void {
                    $length = $response->getHeaderLine('Content-Length');
                    if ($length !== '' && (! ctype_digit($length) || (int) $length > self::MAX_RESPONSE_BYTES)) {
                        throw new \LengthException('Response exceeds the allowed size.');
                    }
                },
            ])->connectTimeout(5)->timeout(20)
                ->withHeaders(['SOAPAction' => 'urn:'.$operation])
                ->withBody($soap, 'text/xml; charset=utf-8');
            if ($onTransmissionStart !== null) {
                $onTransmissionStart();
            }
            $response = $pendingRequest->post($settings['endpoint']);
        } catch (\Throwable $exception) {
            return $this->result('uncertain', $exception instanceof \LengthException ? 'response_too_large' : 'transport_timeout_or_error',
                'No se pudo confirmar la respuesta de SUNAT; conserve el ticket o la numeración y no reenvíe automáticamente.', null);
        }

        $psr = $response->toPsrResponse();
        $stream = $psr->getBody();
        $raw = '';
        while (! $stream->eof() && strlen($raw) <= self::MAX_RESPONSE_BYTES) {
            $raw .= $stream->read(min(8192, self::MAX_RESPONSE_BYTES + 1 - strlen($raw)));
        }
        if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
            return $this->result('uncertain', 'response_too_large', 'La respuesta de SUNAT superó el límite; conserve el ticket y revise la evidencia.', null);
        }
        $safeRaw = $this->sanitizeResponseXml($raw, [$settings['username'], $settings['password']]);

        try {
            $xml = $this->safeXml($raw);
        } catch (\Throwable) {
            $httpStatus = $response->status();
            $type = $response->successful() ? 'uncertain' : 'http_error';
            $code = $response->successful() ? 'response_xml_invalid' : 'http_'.$httpStatus;

            return $this->result($type, $code, 'SUNAT devolvió una respuesta no interpretable; conserve la numeración y revise la evidencia.', $safeRaw, ['http_status' => $httpStatus]);
        }
        $fault = $xml->getElementsByTagNameNS(self::SOAP_NS, 'Fault')->item(0);
        if ($fault instanceof \DOMElement) {
            $code = $this->text($fault, 'faultcode');

            return $this->result('soap_fault', $this->safeCode($code), 'SUNAT respondió con un SOAP Fault. No se realizará un reenvío automático.', $safeRaw, ['http_status' => $response->status()]);
        }
        if (! $response->successful()) {
            return $this->result('http_error', 'http_'.$response->status(), 'SUNAT devolvió una respuesta HTTP sin resultado SOAP concluyente.', $safeRaw, ['http_status' => $response->status()]);
        }
        $expectedResponse = $operation.'Response';
        if ($xml->getElementsByTagNameNS(self::SERVICE_NS, $expectedResponse)->length !== 1) {
            return $this->result('uncertain', 'soap_response_unrecognized', 'La respuesta SOAP no coincide con la operación solicitada; conserve el ticket y la numeración.', $safeRaw, ['http_status' => $response->status()]);
        }

        return ['xml' => $xml, 'raw' => $safeRaw, 'http_status' => $response->status()];
    }

    private function text(\DOMNode $node, string $name): string
    {
        if ($node instanceof \DOMDocument) {
            $namespaced = $node->getElementsByTagNameNS(self::SERVICE_NS, $name);
            $unqualified = $node->getElementsByTagNameNS('', $name);
            if ($namespaced->length + $unqualified->length !== 1) {
                return '';
            }

            return trim(($namespaced->length ? $namespaced : $unqualified)->item(0)->textContent);
        }
        $nodes = $node->getElementsByTagNameNS('*', $name);

        return $nodes->length ? trim($nodes->item(0)->textContent) : '';
    }

    private function safeCode(string $code): string
    {
        $code = preg_replace('/[^A-Za-z0-9._-]/', '', $code) ?? '';

        return mb_substr($code !== '' ? $code : 'soap_fault', 0, 80);
    }

    private function result(string $type, string $code, string $message, ?string $raw, array $extra = []): array
    {
        return ['simulated' => false, 'mode' => 'sunat_beta', 'type' => $type, 'code' => $code, 'message' => $message,
            'response_xml' => $this->sanitizeResponseXml($raw), ...$extra];
    }

    private function sanitizeResponseXml(?string $raw, array $secrets = []): ?string
    {
        if ($raw === null || $raw === '') {
            return $raw;
        }
        $xml = new \DOMDocument;
        $xml->resolveExternals = false;
        $xml->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $raw) !== 1 && $xml->loadXML($raw, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return '[respuesta no interpretable omitida; sha256='.hash('sha256', $raw).']';
        }
        foreach (['Username', 'Password', 'Authorization', 'BinarySecurityToken'] as $name) {
            foreach ($xml->getElementsByTagNameNS('*', $name) as $element) {
                $element->textContent = '[dato omitido]';
            }
        }

        $serialized = $xml->saveXML() ?: '[respuesta no interpretable omitida]';
        foreach (array_filter($secrets, static fn ($secret): bool => is_string($secret) && $secret !== '') as $secret) {
            $serialized = str_replace($secret, '[dato omitido]', $serialized);
        }

        return $serialized;
    }
}
