<?php

namespace App\Services;

use App\Contracts\ElectronicInvoiceGateway;
use App\Models\ElectronicDocument;
use App\Models\ElectronicDocumentAttempt;
use App\Models\ElectronicInvoiceSetting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class DirectSunatGateway implements ElectronicInvoiceGateway
{
    private const MAX_RESPONSE_BYTES = 10 * 1024 * 1024;

    private Client $http;

    public function __construct(private SunatUblInvoiceBuilder $builder, private SunatXmlSignatureService $signer, private SunatSchemaBundle $schemas, ?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'timeout' => 20,
            'connect_timeout' => 5,
            'verify' => true,
            'allow_redirects' => false,
            'http_errors' => true,
        ]);
    }

    public function send(ElectronicDocument $document, ElectronicDocumentAttempt $attempt): array
    {
        if ($document->document_type !== 'factura') {
            throw new \DomainException('Boletas require the asynchronous daily summary flow.');
        }
        if ($attempt->environment !== 'testing' || $attempt->connection_type !== 'direct_sunat'
            || $document->environment !== 'testing' || $document->connection_type !== 'direct_sunat') {
            throw new \DomainException('This gateway only accepts the SUNAT test environment.');
        }

        $setting = ElectronicInvoiceSetting::query()->where('singleton_key', 'primary')->firstOrFail();
        $certPath = $setting->certificate_path;
        $certificate = $certPath ? Storage::disk('local')->get($certPath) : null;
        if (! is_string($certificate) || $certificate === '') {
            throw new \DomainException('A private signing certificate is not configured.');
        }
        $xmlDocument = $this->builder->build($document);
        // Formatting after signing would add whitespace nodes that are not part
        // of the signed DOM and would invalidate the enveloped reference.
        $xmlDocument->formatOutput = false;
        $xml = $this->signer->signAndVerify(
            $xmlDocument,
            $certificate,
            (string) $setting->certificate_password_encrypted,
            (string) data_get($document->seller_snapshot, 'tax_id_number'),
            config('electronic_invoicing.openssl_config')
        );
        $serializedDocument = new \DOMDocument;
        $serializedDocument->resolveExternals = false;
        $serializedDocument->substituteEntities = false;
        // Keep the exact signed whitespace for canonicalization verification.
        if (! $serializedDocument->loadXML($xml, LIBXML_NONET)) {
            throw new \DomainException('The signed invoice XML could not be safely reloaded after serialization.');
        }
        if (! $this->schemas->validate($serializedDocument)) {
            throw new \DomainException('The official UBL 2.1 invoice schema validation failed or is unavailable.');
        }

        $ruc = (string) data_get($document->seller_snapshot, 'tax_id_number');
        $number = str_pad((string) $document->document_number, 8, '0', STR_PAD_LEFT);
        $base = $ruc.'-01-'.$document->series.'-'.$number;
        $folder = 'electronic-invoices/documents/'.$document->id;
        $xmlPath = $folder.'/'.$base.'.xml';
        $zipPath = $folder.'/'.$base.'.zip';
        if (! Storage::disk('local')->put($xmlPath, $xml)) {
            throw new \RuntimeException('Signed XML could not be stored privately.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'sunat_');
        if ($tmp === false) {
            throw new \RuntimeException('Temporary archive could not be allocated.');
        }
        try {
            $zip = new \ZipArchive;
            if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('SUNAT payload archive could not be created.');
            }
            $zip->addEmptyDir('dummy');
            if (! $zip->addFromString($base.'.xml', $xml) || ! $zip->close()) {
                throw new \RuntimeException('SUNAT payload archive could not be finalized.');
            }
            $zipBytes = file_get_contents($tmp);
            if (! is_string($zipBytes) || $zipBytes === '') {
                throw new \RuntimeException('SUNAT payload archive is empty.');
            }
            if (! Storage::disk('local')->put($zipPath, $zipBytes)) {
                throw new \RuntimeException('SUNAT payload archive could not be stored privately.');
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }

        $username = filled($setting->sunat_username_encrypted)
            ? (string) $setting->sunat_username_encrypted
            : $ruc.'MODDATOS';
        $password = filled($setting->sunat_password_encrypted)
            ? (string) $setting->sunat_password_encrypted
            : 'MODDATOS';
        $responsePath = $folder.'/'.$base.'-response.xml';
        $evidence = [
            'folder' => $folder,
            'base' => $base,
            'file_name' => $base.'.ZIP',
            'xml' => $xml,
            'zip' => $zipBytes,
        ];
        try {
            $response = $this->soap($username, $password, 'sendBill', '<fileName>'.$base.'.ZIP</fileName><contentFile>'.base64_encode($zipBytes).'</contentFile>', $evidence);
            $cdrBytes = base64_decode($this->value($response, 'document'), true);
            if ($cdrBytes === false || $cdrBytes === '') {
                $this->updateTransmissionEvidence($evidence, ['result' => ['classification' => 'soap_without_cdr', 'status' => 'uncertain']]);

                return ['status' => 'uncertain', 'response_code' => 'cdr_missing', 'message' => 'SUNAT no devolvió una CDR interpretable.', 'xml_path' => $xmlPath, 'zip_path' => $zipPath, 'response_path' => $responsePath, 'xml_hash' => hash('sha256', $xml)];
            }
            $cdr = $this->readCdr($cdrBytes, $folder, $base);
            $this->updateTransmissionEvidence($evidence, ['result' => ['classification' => 'cdr', 'status' => $cdr['status'] ?? 'uncertain', 'response_code' => $cdr['response_code'] ?? null]]);

            return $cdr + ['xml_path' => $xmlPath, 'zip_path' => $zipPath, 'response_path' => $responsePath, 'xml_hash' => hash('sha256', $xml)];
        } catch (SunatSoapFaultException $exception) {
            $isClientException = $exception->faultType() === 'Client';
            $this->updateTransmissionEvidence($evidence, ['result' => ['classification' => 'soap_fault', 'fault_type' => $exception->faultType(), 'response_code' => $exception->responseCode()]]);

            return [
                'status' => $isClientException ? 'sunat_exception' : 'uncertain',
                'response_code' => $exception->responseCode() ?? 'soap_fault',
                'message' => $isClientException && $exception->responseCode() === '0160'
                    ? 'SUNAT respondió con Client.0160 (XML vacío o error de tamaño). Se conserva la numeración; revise el ZIP y el XML antes de cualquier reintento.'
                    : ($isClientException
                        ? 'SUNAT devolvió un SOAP Fault de cliente. Se conserva la numeración y no se realizará un reenvío automático.'
                        : 'SUNAT devolvió un SOAP Fault sin resultado CDR concluyente. Se conserva la numeración y no se realizará un reenvío automático.'),
                'xml_path' => $xmlPath,
                'zip_path' => $zipPath,
                'response_path' => Storage::disk('local')->exists($responsePath) ? $responsePath : null,
                'xml_hash' => hash('sha256', $xml),
            ];
        } catch (RequestException $exception) {
            $statusCode = $exception->getResponse()?->getStatusCode();
            $truncated = $this->responseWasTruncated($evidence);
            $this->updateTransmissionEvidence($evidence, ['result' => ['classification' => $truncated ? 'response_too_large' : ($statusCode ? 'http_error' : 'transport_error'), 'http_status' => $statusCode]]);
            $errorResponsePath = $this->responseEvidencePath($evidence);

            return [
                'status' => 'uncertain',
                'response_code' => $truncated ? 'response_too_large' : ($statusCode ? 'http_'.$statusCode : 'transport_error'),
                'message' => $truncated
                    ? 'La respuesta de SUNAT superó el límite de tamaño permitido. Se conserva la numeración y la evidencia capturada parcialmente; no reenvíe hasta revisar el resultado.'
                    : ($statusCode
                    ? 'El servicio Beta devolvió una respuesta HTTP sin CDR interpretable. Se conserva la numeración; no reenvíe hasta revisar el resultado.'
                    : 'No se pudo confirmar la respuesta de SUNAT. Conserve la numeración y revise el resultado antes de cualquier reenvío.'),
                'xml_path' => $xmlPath,
                'zip_path' => $zipPath,
                'response_path' => $errorResponsePath,
                'xml_hash' => hash('sha256', $xml),
            ];
        } catch (\LengthException $exception) {
            $this->updateTransmissionEvidence($evidence, ['result' => ['classification' => 'response_too_large', 'status' => 'uncertain']]);

            return [
                'status' => 'uncertain',
                'response_code' => 'response_too_large',
                'message' => 'La respuesta de SUNAT superó el límite de tamaño permitido. Se conserva la numeración y la evidencia capturada parcialmente; no reenvíe hasta revisar el resultado.',
                'xml_path' => $xmlPath,
                'zip_path' => $zipPath,
                'response_path' => $this->responseEvidencePath($evidence),
                'xml_hash' => hash('sha256', $xml),
            ];
        } catch (TransferException $exception) {
            $this->updateTransmissionEvidence($evidence, [
                'response_received' => false,
                'transport_error' => class_basename($exception),
                'result' => ['classification' => 'transport_error', 'status' => 'uncertain'],
            ]);

            return ['status' => 'uncertain', 'response_code' => 'transport_timeout', 'message' => 'No se pudo confirmar la respuesta de SUNAT. Conserve la numeración y revise el resultado antes de cualquier reenvío.', 'xml_path' => $xmlPath, 'zip_path' => $zipPath, 'response_path' => null, 'xml_hash' => hash('sha256', $xml)];
        }
    }

    public function query(ElectronicDocumentAttempt $attempt): array
    {
        $document = ElectronicDocument::query()->findOrFail($attempt->electronic_document_id);
        if ($attempt->environment !== 'testing' || $attempt->connection_type !== 'direct_sunat'
            || $document->environment !== 'testing' || $document->connection_type !== 'direct_sunat') {
            return ['status' => 'uncertain', 'response_code' => 'query_environment_unsupported', 'message' => 'La consulta solo está habilitada para intentos SUNAT de prueba.'];
        }

        // The current Beta billService WSDL does not expose getStatusCdr.
        // Do not call an unsupported operation or the production query service.
        return ['status' => 'uncertain', 'response_code' => 'beta_query_not_supported', 'message' => 'SUNAT Beta no ofrece consulta de CDR para esta factura síncrona; conserve la numeración y revise el resultado por el canal Beta autorizado.'];
    }

    private function soap(string $username, string $password, string $method, string $body, ?array $evidence = null): \DOMDocument
    {
        $securityNs = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
        $soap = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ser="http://service.sunat.gob.pe" xmlns:wsse="'.$securityNs.'">'
            .'<soapenv:Header><wsse:Security soapenv:mustUnderstand="1"><wsse:UsernameToken><wsse:Username>'.htmlspecialchars($username, ENT_XML1).'</wsse:Username><wsse:Password>'.htmlspecialchars($password, ENT_XML1).'</wsse:Password></wsse:UsernameToken></wsse:Security></soapenv:Header>'
            .'<soapenv:Body><ser:'.$method.'>'.$body.'</ser:'.$method.'></soapenv:Body></soapenv:Envelope>';
        if ($evidence !== null) {
            $this->assertEncodedPayloadMatchesArchive($soap, $method, $evidence);
            $this->storeRequestEvidence($soap, $method, $evidence);
        }
        $endpoint = (string) config('electronic_invoicing.sunat.testing_endpoint');
        $headers = ['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => 'urn:'.$method];
        try {
            $response = $this->http->post($endpoint, [
                'headers' => $headers,
                'body' => $soap,
                'on_stats' => function (TransferStats $stats) use ($evidence): void {
                    if ($evidence === null || ! $stats->getRequest() instanceof RequestInterface) {
                        return;
                    }
                    $request = $stats->getRequest();
                    $allowedHeaders = ['Host', 'Content-Type', 'SOAPAction', 'Content-Length', 'User-Agent', 'Accept-Encoding', 'Connection'];
                    $safeHeaders = [];
                    foreach ($allowedHeaders as $header) {
                        $value = $request->getHeaderLine($header);
                        if ($value !== '') {
                            $safeHeaders[$header] = $value;
                        }
                    }
                    $this->updateTransmissionEvidence($evidence, [
                        'transmitted_request' => [
                            'method' => $request->getMethod(),
                            'endpoint' => (string) $request->getUri(),
                            'headers' => $safeHeaders,
                            'body_bytes' => $request->getBody()->getSize(),
                        ],
                    ]);
                },
            ]);
        } catch (RequestException $exception) {
            if ($evidence !== null && $exception->getResponse() !== null) {
                [$body, $truncated, $totalBytes] = $this->readBoundedResponse($exception->getResponse());
                $this->storeResponseEvidence($evidence, $exception->getResponse()->getStatusCode(), $exception->getResponse()->getHeaderLine('Content-Type'), $body, false, $truncated, $totalBytes);
            }

            throw $exception;
        }
        [$responseBody, $truncated, $totalBytes] = $this->readBoundedResponse($response);
        if ($evidence !== null) {
            $this->storeResponseEvidence($evidence, $response->getStatusCode(), $response->getHeaderLine('Content-Type'), $responseBody, true, $truncated, $totalBytes);
        }
        if ($truncated) {
            throw new \LengthException('SUNAT SOAP response exceeded the configured size limit.');
        }
        $xml = new \DOMDocument;
        if (! $xml->loadXML($responseBody, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new \RuntimeException('SUNAT returned malformed SOAP XML.');
        }
        if ($xml->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Fault')->length > 0) {
            $faults = $xml->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Fault');
            $fault = $faults->item(0);
            $faultType = null;
            $code = null;
            if ($fault instanceof \DOMElement) {
                foreach ($fault->childNodes as $child) {
                    if ($child instanceof \DOMElement && $child->localName === 'faultcode'
                        && preg_match('/^(?:[\w-]+:)?(Client|Server)(?:\.(\d{4}))?$/', trim($child->textContent), $matches) === 1) {
                        $faultType = $matches[1];
                        $code = $matches[2] ?? null;
                        break;
                    }
                }
            }
            throw new SunatSoapFaultException($faultType, $code);
        }

        return $xml;
    }

    private function assertEncodedPayloadMatchesArchive(string $soap, string $method, array $evidence): void
    {
        $request = new \DOMDocument;
        $request->resolveExternals = false;
        $request->substituteEntities = false;
        if (! $request->loadXML($soap, LIBXML_NONET)) {
            throw new \RuntimeException('The outgoing SOAP request could not be verified.');
        }
        $xpath = new \DOMXPath($request);
        $fileName = $xpath->evaluate('string(//*[local-name()="fileName"])');
        $encoded = $xpath->evaluate('string(//*[local-name()="contentFile"])');
        $decoded = base64_decode($encoded, true);
        if ($method !== 'sendBill' || $fileName !== $evidence['file_name'] || ! is_string($decoded)
            || ! hash_equals(hash('sha256', $evidence['zip']), hash('sha256', $decoded))) {
            throw new \RuntimeException('The outgoing SOAP payload did not match the stored invoice archive.');
        }

        $temp = tempnam(sys_get_temp_dir(), 'sunat-evidence-');
        if ($temp === false) {
            throw new \RuntimeException('The outgoing invoice archive could not be verified.');
        }
        try {
            if (file_put_contents($temp, $decoded) !== strlen($decoded)) {
                throw new \RuntimeException('The outgoing invoice archive could not be verified.');
            }
            $zip = new \ZipArchive;
            if ($zip->open($temp) !== true) {
                throw new \RuntimeException('The outgoing invoice archive is invalid.');
            }
            $xml = $zip->getFromName(pathinfo((string) $evidence['file_name'], PATHINFO_FILENAME).'.xml');
            $hasDummy = $zip->locateName('dummy/') !== false;
            $zip->close();
            if (! $hasDummy || ! is_string($xml) || ! hash_equals(hash('sha256', $evidence['xml']), hash('sha256', $xml))) {
                throw new \RuntimeException('The outgoing archive XML did not match the stored signed invoice.');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    private function storeRequestEvidence(string $soap, string $method, array $evidence): void
    {
        $document = new \DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        if (! $document->loadXML($soap, LIBXML_NONET)) {
            throw new \RuntimeException('The outgoing SOAP request could not be sanitized for private evidence.');
        }
        $xpath = new \DOMXPath($document);
        foreach (['Username', 'Password'] as $secretElement) {
            foreach ($xpath->query('//*[local-name()="'.$secretElement.'"]') ?: [] as $node) {
                $node->textContent = '[REDACTED]';
            }
        }
        $sanitizedSoap = $document->saveXML();
        if (! is_string($sanitizedSoap)) {
            throw new \RuntimeException('The sanitized SOAP evidence could not be serialized.');
        }
        $disk = Storage::disk('local');
        $requestPath = $evidence['folder'].'/'.$evidence['base'].'-request-sanitized.xml';
        $manifestPath = $evidence['folder'].'/'.$evidence['base'].'-transmission-evidence.json';
        if (! $disk->put($requestPath, $sanitizedSoap)) {
            throw new \RuntimeException('The sanitized SOAP request could not be stored privately.');
        }
        $manifest = [
            'environment' => 'testing',
            'operation' => $method,
            'endpoint' => (string) config('electronic_invoicing.sunat.testing_endpoint'),
            'headers' => ['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => 'urn:'.$method],
            'request' => [
                'soap_bytes' => strlen($soap),
                'sanitized_soap_path' => $requestPath,
                'sanitized_soap_sha256' => hash('sha256', $sanitizedSoap),
                'xml_bytes' => strlen($evidence['xml']),
                'xml_sha256' => hash('sha256', $evidence['xml']),
                'zip_bytes' => strlen($evidence['zip']),
                'zip_sha256' => hash('sha256', $evidence['zip']),
                'content_file_decoded_bytes' => strlen($evidence['zip']),
                'content_file_decoded_sha256' => hash('sha256', $evidence['zip']),
                'file_name' => $evidence['file_name'],
            ],
            'response_received' => false,
        ];
        if (! $disk->put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) {
            throw new \RuntimeException('The private transmission evidence manifest could not be stored.');
        }
    }

    /** @return array{string, bool, int|null} */
    private function readBoundedResponse(ResponseInterface $response): array
    {
        $stream = $response->getBody();
        $totalBytes = $stream->getSize();
        $body = '';
        $captureLimit = self::MAX_RESPONSE_BYTES + 1;
        while (! $stream->eof() && strlen($body) < $captureLimit) {
            $chunk = $stream->read(min(8192, $captureLimit - strlen($body)));
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }
        $truncated = strlen($body) > self::MAX_RESPONSE_BYTES || ($totalBytes !== null && $totalBytes > self::MAX_RESPONSE_BYTES);
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            $body = substr($body, 0, self::MAX_RESPONSE_BYTES);
        }

        return [$body, $truncated, $totalBytes];
    }

    private function storeResponseEvidence(array $evidence, int $status, string $contentType, string $body, bool $soapResponse, bool $truncated, ?int $totalBytes): void
    {
        $disk = Storage::disk('local');
        $suffix = $truncated ? 'truncated.bin' : ($soapResponse ? 'xml' : 'http-'.$status.'.bin');
        $responsePath = $evidence['folder'].'/'.$evidence['base'].'-response.'.$suffix;
        if (! $disk->put($responsePath, $body)) {
            throw new \RuntimeException('The SUNAT response could not be stored privately.');
        }
        $this->updateTransmissionEvidence($evidence, [
            'response_received' => true,
            'response' => [
                'http_status' => $status,
                'content_type' => $contentType,
                'path' => $responsePath,
                'bytes' => strlen($body),
                'captured_bytes' => strlen($body),
                'total_bytes' => $totalBytes,
                'truncated' => $truncated,
                'sha256' => hash('sha256', $body),
                'classification' => $soapResponse ? 'soap_body' : 'http_error_body',
            ],
        ]);
    }

    private function responseWasTruncated(array $evidence): bool
    {
        $path = $evidence['folder'].'/'.$evidence['base'].'-transmission-evidence.json';
        $manifest = Storage::disk('local')->exists($path)
            ? json_decode((string) Storage::disk('local')->get($path), true)
            : null;

        return is_array($manifest) && data_get($manifest, 'response.truncated') === true;
    }

    private function responseEvidencePath(array $evidence): ?string
    {
        $path = $evidence['folder'].'/'.$evidence['base'].'-transmission-evidence.json';
        $manifest = Storage::disk('local')->exists($path)
            ? json_decode((string) Storage::disk('local')->get($path), true)
            : null;
        $responsePath = is_array($manifest) ? data_get($manifest, 'response.path') : null;

        return is_string($responsePath) && Storage::disk('local')->exists($responsePath) ? $responsePath : null;
    }

    private function updateTransmissionEvidence(array $evidence, array $updates): void
    {
        $disk = Storage::disk('local');
        $path = $evidence['folder'].'/'.$evidence['base'].'-transmission-evidence.json';
        $manifest = $disk->exists($path) ? json_decode((string) $disk->get($path), true) : [];
        if (! is_array($manifest)) {
            $manifest = [];
        }
        foreach ($updates as $key => $value) {
            $manifest[$key] = $value;
        }
        if (! $disk->put($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) {
            throw new \RuntimeException('The private transmission evidence manifest could not be updated.');
        }
    }

    private function value(\DOMDocument $xml, string $localName): string
    {
        $nodes = $xml->getElementsByTagNameNS('*', $localName);

        return $nodes->length ? trim($nodes->item(0)->textContent) : '';
    }

    private function readCdr(string $bytes, string $folder, string $base): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cdr_');
        if ($tmp === false) {
            return ['status' => 'uncertain', 'response_code' => 'cdr_temp_unavailable', 'message' => 'No se pudo procesar el archivo de respuesta de SUNAT.'];
        }
        try {
            file_put_contents($tmp, $bytes);
            $zip = new \ZipArchive;
            if ($zip->open($tmp) !== true) {
                return ['status' => 'uncertain', 'response_code' => 'cdr_zip_invalid', 'message' => 'La respuesta de SUNAT no contiene una CDR ZIP válida.'];
            }
            if ($zip->numFiles > 20) {
                $zip->close();

                return ['status' => 'uncertain', 'response_code' => 'cdr_entry_limit_exceeded', 'message' => 'La respuesta de SUNAT excede el límite de archivos admitido.'];
            }
            $cdrXml = null;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (is_string($name) && str_ends_with(strtolower($name), '.xml') && ! str_contains($name, '..') && ! str_starts_with($name, '/') && ! str_contains($name, '\\')) {
                    $entry = $zip->statIndex($index);
                    if (! is_array($entry) || ($entry['size'] ?? PHP_INT_MAX) > 2 * 1024 * 1024) {
                        $zip->close();

                        return ['status' => 'uncertain', 'response_code' => 'cdr_size_limit_exceeded', 'message' => 'El XML de respuesta de SUNAT excede el límite admitido.'];
                    }
                    $cdrXml = $zip->getFromIndex($index);
                    break;
                }
            }
            $zip->close();
            if (! is_string($cdrXml) || $cdrXml === '') {
                return ['status' => 'uncertain', 'response_code' => 'cdr_xml_missing', 'message' => 'La respuesta de SUNAT no incluyó un XML CDR.'];
            }
            $doc = new \DOMDocument;
            if (! $doc->loadXML($cdrXml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                return ['status' => 'uncertain', 'response_code' => 'cdr_xml_invalid', 'message' => 'El CDR devuelto no pudo validarse.'];
            }
            $code = $this->value($doc, 'ResponseCode');
            if ($code === '') {
                return ['status' => 'uncertain', 'response_code' => 'cdr_code_missing', 'message' => 'El XML de respuesta no contiene un código CDR interpretable.'];
            }
            $description = mb_substr(strip_tags($this->value($doc, 'Description')), 0, 500);
            $notes = [];
            foreach ($doc->getElementsByTagNameNS('*', 'Note') as $note) {
                $notes[] = mb_substr(strip_tags(trim($note->textContent)), 0, 250);
            }
            $cdrPath = $folder.'/'.$base.'-CDR.xml';
            if (! Storage::disk('local')->put($cdrPath, $cdrXml)) {
                return ['status' => 'uncertain', 'response_code' => 'cdr_storage_failed', 'message' => 'No se pudo conservar de forma privada la respuesta de SUNAT.'];
            }

            return [
                'status' => $code === '0' ? ($notes === [] ? 'accepted' : 'accepted_observed') : 'rejected',
                'response_code' => $code !== '' ? $code : 'cdr_code_missing',
                'message' => $description !== '' ? $description : 'Resultado CDR recibido.',
                'observations' => $notes === [] ? null : implode("\n", $notes),
                'cdr_path' => $cdrPath,
            ];
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }
}
