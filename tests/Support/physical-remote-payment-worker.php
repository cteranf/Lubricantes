<?php

use App\Http\Controllers\Api\V1\PaymentController;
use App\Services\PaymentGateway\MercadoPagoGateway;
use App\Services\PaymentGateway\PaymentGatewayInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;
use Tests\Support\PhysicalPaymentProcessingService;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function workerOption(string $name): string
{
    global $argv;
    $index = array_search($name, $argv, true);
    if ($index === false || ! isset($argv[$index + 1])) {
        throw new RuntimeException('Worker argument missing.');
    }

    return $argv[$index + 1];
}

function atomicJson(string $path, array $data): void
{
    $temporary = $path.'.tmp-'.bin2hex(random_bytes(6));
    $handle = @fopen($temporary, 'x');
    if ($handle === false) {
        throw new RuntimeException('Worker marker collision.');
    }
    try {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
            throw new RuntimeException('Worker marker write failed.');
        }
    } finally {
        fclose($handle);
    }
    if (! @rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Worker marker publication failed.');
    }
}

$fixture = json_decode((string) file_get_contents(workerOption('--fixture')), true, 32, JSON_THROW_ON_ERROR);
$role = workerOption('--role');
$paymentId = $fixture['payment_ids'][$role] ?? $fixture['payment_id'];
$root = $fixture['marker_root'];
$resultPath = $root.DIRECTORY_SEPARATOR.'result-'.$role.'.json';
$startPath = $root.DIRECTORY_SEPARATOR.'started-'.$role.'.json';

try {
    if (! app()->environment('testing') || config('app.debug') !== false || config('database.default') !== 'mysql') {
        throw new RuntimeException('Worker preflight failed.');
    }
    if (config('payment.mercadopago.access_token') !== 'TEST-SYNTHETIC-ACCESS-TOKEN'
        || config('payment.mercadopago.public_key') !== 'TEST-SYNTHETIC-PUBLIC-KEY'
        || config('payment.mercadopago.webhook_secret') !== 'synthetic-local-webhook-secret') {
        throw new RuntimeException('Worker external credentials rejected.');
    }
    $token = 'PHYSICAL-SYNTHETIC-TOKEN-'.bin2hex(random_bytes(16));
    config([
        'payment.mercadopago.access_token' => $token,
        'payment.mercadopago.public_key' => 'PHYSICAL-SYNTHETIC-PUBLIC-KEY',
        'payment.mercadopago.webhook_secret' => $fixture['webhook_secret'],
        'payment.currency' => $fixture['currency'],
    ]);
    MercadoPagoConfig::setAccessToken($token);
    $transport = new class($fixture, $role, $paymentId) implements MPHttpClient
    {
        public int $calls = 0;

        public function __construct(private array $fixture, private string $role, private string $paymentId) {}

        public function send(MPRequest $request): MPResponse
        {
            $this->calls++;
            $status = $this->fixture['statuses'][$this->role];
            if ($request->getMethod() === 'GET' && $request->getUri() === '/v1/payments/'.$this->paymentId) {
                return new MPResponse(200, [
                    'id' => (int) $this->paymentId,
                    'order' => ['id' => (int) $this->fixture['merchant_order_id'], 'type' => 'mercadopago'],
                    'status' => $status,
                    'status_detail' => $status === 'approved' ? 'accredited' : 'pending_review',
                    'external_reference' => $this->fixture['external_reference'],
                    'transaction_amount' => (float) $this->fixture['amount'],
                    'currency_id' => $this->fixture['currency'],
                    'payment_method_id' => 'visa',
                ]);
            }
            if ($request->getMethod() === 'GET' && $request->getUri() === '/merchant_orders/'.$this->fixture['merchant_order_id']) {
                return new MPResponse(200, [
                    'id' => (int) $this->fixture['merchant_order_id'],
                    'preference_id' => $this->fixture['preference_id'],
                    'external_reference' => $this->fixture['external_reference'],
                    'total_amount' => (float) $this->fixture['amount'],
                    'payments' => array_map(fn (string $memberId) => [
                        'id' => (int) $memberId,
                        'transaction_amount' => (float) $this->fixture['amount'],
                        'currency_id' => $this->fixture['currency'],
                    ], $this->fixture['merchant_payment_ids'] ?? [$this->fixture['payment_id']]),
                ]);
            }
            throw new LogicException('Unexpected SDK transport operation.');
        }
    };
    MercadoPagoConfig::setHttpClient($transport);
    app()->instance(PaymentGatewayInterface::class, new MercadoPagoGateway($token));
    $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    atomicJson($startPath, ['connection_id' => $connectionId, 'pid' => getmypid()]);

    $app->instance(App\Services\PaymentProcessingService::class, app(PhysicalPaymentProcessingService::class));
    $requestId = $fixture['request_ids'][$role];
    $eventId = $fixture['event_ids'][$role];
    $timestamp = (string) time();
    $signature = 'ts='.$timestamp.',v1='.hash_hmac('sha256', 'id:'.$paymentId.';request-id:'.$requestId.';ts:'.$timestamp.';', $fixture['webhook_secret']);
    $body = json_encode(['id' => $eventId, 'type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => $paymentId]], JSON_THROW_ON_ERROR);
    $request = Request::create('/api/v1/payment/webhook', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_REQUEST_ID' => $requestId,
        'HTTP_X_SIGNATURE' => $signature,
        'QUERY_STRING' => '',
    ], $body);
    $response = app(PaymentController::class)->webhook($request);
    $payload = json_decode((string) $response->getContent(), true);
    atomicJson($resultPath, ['http_status' => $response->getStatusCode(), 'status' => $payload['status'] ?? null, 'code' => $payload['code'] ?? null, 'sdk_calls' => $transport->calls]);
} catch (Throwable $exception) {
    $sqlState = $exception instanceof Illuminate\Database\QueryException ? $exception->getCode() : null;
    $vendorCode = $exception instanceof Illuminate\Database\QueryException ? ($exception->errorInfo[1] ?? null) : null;
    try {
        atomicJson($resultPath, ['exception' => get_class($exception), 'sql_state' => (string) $sqlState, 'vendor_code' => $vendorCode]);
    } catch (Throwable) {
        // Preserve a private, non-payload diagnostic only in the process exit status.
    }
    exit(1);
}
