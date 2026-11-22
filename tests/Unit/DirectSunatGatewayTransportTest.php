<?php

namespace Tests\Unit;

use App\Services\DirectSunatGateway;
use App\Services\MoneyService;
use App\Services\SunatSchemaBundle;
use App\Services\SunatSoapFaultException;
use App\Services\SunatUblInvoiceBuilder;
use App\Services\SunatXmlSignatureService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DirectSunatGatewayTransportTest extends TestCase
{
    public function test_soap_transport_is_mockable_and_uses_fixed_beta_endpoint_and_ws_security(): void
    {
        config(['electronic_invoicing.sunat.testing_endpoint' => 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService']);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'text/xml'], '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><result xmlns="urn:test">ok</result></soap:Body></soap:Envelope>'),
        ]));
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack]);
        $gateway = new DirectSunatGateway(new SunatUblInvoiceBuilder(new MoneyService), new SunatXmlSignatureService, new SunatSchemaBundle, $client);
        $soap = new \ReflectionMethod($gateway, 'soap');
        $soap->setAccessible(true);

        $result = $soap->invoke($gateway, '20123456789MODDATOS', 'synthetic&secret', 'sendBill', '<fileName>synthetic.ZIP</fileName>');

        $this->assertSame('ok', $result->getElementsByTagName('result')->item(0)?->textContent);
        $this->assertCount(1, $history);
        $this->assertSame('https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService', (string) $history[0]['request']->getUri());
        $this->assertSame('text/xml; charset=utf-8', $history[0]['request']->getHeaderLine('Content-Type'));
        $this->assertSame('urn:sendBill', $history[0]['request']->getHeaderLine('SOAPAction'));
        $requestBody = (string) $history[0]['request']->getBody();
        $this->assertStringContainsString('<wsse:Username>20123456789MODDATOS</wsse:Username>', $requestBody);
        $this->assertStringContainsString('<wsse:Password>synthetic&amp;secret</wsse:Password>', $requestBody);
        $this->assertStringNotContainsString('synthetic&secret', $requestBody);
    }

    public function test_sanitized_beta_client_0160_fault_is_preserved_as_a_sunat_exception_without_exposing_fault_text(): void
    {
        config(['electronic_invoicing.sunat.testing_endpoint' => 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService']);
        $fixture = file_get_contents(base_path('tests/Fixtures/Sunat/beta-client-0160-fault.xml'));
        $this->assertIsString($fixture);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'text/xml; charset=utf-8'], $fixture),
        ]));
        $stack->push(Middleware::history($history));
        $gateway = new DirectSunatGateway(new SunatUblInvoiceBuilder(new MoneyService), new SunatXmlSignatureService, new SunatSchemaBundle, new Client(['handler' => $stack]));
        $soap = new \ReflectionMethod($gateway, 'soap');
        $soap->setAccessible(true);

        try {
            $soap->invoke($gateway, 'synthetic-user', 'synthetic-password', 'sendBill', '<fileName>synthetic.ZIP</fileName>');
            $this->fail('Expected the SUNAT SOAP Fault to be classified.');
        } catch (SunatSoapFaultException $exception) {
            $this->assertSame('Client', $exception->faultType());
            $this->assertSame('0160', $exception->responseCode());
            $this->assertStringNotContainsString('RUC', $exception->getMessage());
            $this->assertStringNotContainsString('synthetic-password', $exception->getMessage());
        }

        $this->assertCount(1, $history);
        $this->assertSame('urn:sendBill', $history[0]['request']->getHeaderLine('SOAPAction'));
    }

    public function test_synthetic_cdr_results_are_classified_and_stored_privately(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('The PHP ZIP extension is required for synthetic CDR archive tests.');
        }

        Storage::fake('local');
        $gateway = new DirectSunatGateway(new SunatUblInvoiceBuilder(new MoneyService), new SunatXmlSignatureService, new SunatSchemaBundle, new Client);
        $readCdr = new \ReflectionMethod($gateway, 'readCdr');
        $readCdr->setAccessible(true);

        foreach ([
            ['<ResponseCode>0</ResponseCode><Description>Recibido</Description>', 'accepted'],
            ['<ResponseCode>0</ResponseCode><Description>Recibido</Description><Note>Observación sintética</Note>', 'accepted_observed'],
            ['<ResponseCode>2001</ResponseCode><Description>Rechazado</Description>', 'rejected'],
            ['<Description>Sin código concluyente</Description>', 'uncertain'],
        ] as $index => [$body, $expected]) {
            $path = tempnam(sys_get_temp_dir(), 'sunat-cdr-test-');
            $this->assertNotFalse($path);
            $zip = new \ZipArchive;
            try {
                $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
                $this->assertTrue($zip->addFromString('R-20123456789-F001-00000001.xml', '<ApplicationResponse>'.$body.'</ApplicationResponse>'));
                $this->assertTrue($zip->close());
                $bytes = file_get_contents($path);
                $this->assertIsString($bytes);

                $result = $readCdr->invoke($gateway, $bytes, 'electronic-invoices/documents/91', 'F001-00000001-'.$index);

                $this->assertSame($expected, $result['status']);
                if ($expected !== 'uncertain') {
                    Storage::disk('local')->assertExists($result['cdr_path']);
                }
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
