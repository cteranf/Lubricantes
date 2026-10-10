<?php

use App\Models\PaymentSubmission;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\InventoryReservationExpirationService;
use App\Services\OrderFulfillmentService;
use App\Services\TreasuryPaymentResolutionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

$arguments = array_slice($argv, 1);
if (count($arguments) !== 5) {
    echo json_encode(['result' => 'worker_error', 'ok' => false]);
    exit(2);
}

[$submissionId, $actorId, $ready, $release, $action] = $arguments;
$allowedActions = ['open_resolution', 'expire_reservation', 'cancel_order'];
if (getenv('APP_ENV') === 'testing') {
    $allowedActions[] = 'barrier_probe';
}
if (! in_array($action, $allowedActions, true)) {
    echo json_encode(['result' => 'worker_error', 'ok' => false]);
    exit(2);
}
if (
    ! is_dir(dirname($ready))
    || ! is_writable(dirname($ready))
    || dirname($ready) !== dirname($release)
    || ! in_array(basename($ready), ['ready-first', 'ready-second'], true)
    || basename($release) !== 'release'
) {
    echo json_encode(['result' => 'ready_creation_failed', 'ok' => false, 'stage' => 'ready']);
    exit(2);
}

$waitForRelease = static function (string $release): bool {
    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline) {
        clearstatcache(true, $release);
        $releaseHandle = @fopen($release, 'rb');
        if (is_resource($releaseHandle)) {
            $released = stream_get_contents($releaseHandle) === 'release';
            fclose($releaseHandle);
            if ($released) {
                return true;
            }
        }
        usleep(25_000);
    }

    return false;
};

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::purge();
DB::reconnect();
$database = DB::selectOne('SELECT DATABASE() AS name')->name ?? null;
if (app()->environment() !== 'testing' || config('app.debug') || DB::connection()->getDriverName() !== 'mysql' || $database !== 'lubristore_2b4_resolution_certification_test') {
    echo json_encode(['result' => 'worker_error', 'ok' => false]);
    exit(2);
}

if (@file_put_contents($ready, '') === false || ! is_file($ready)) {
    echo json_encode(['result' => 'ready_creation_failed', 'ok' => false, 'stage' => 'ready']);
    exit(2);
}

if (! $waitForRelease($release)) {
    echo json_encode(['result' => 'release_timeout', 'ok' => false]);
    exit(2);
}
if ($action === 'barrier_probe') {
    echo json_encode(['result' => 'success', 'ok' => true]);

    exit(0);
}
try {
    $submission = PaymentSubmission::findOrFail($submissionId);
    $result = match ($action) {
        'open_resolution' => app(TreasuryPaymentResolutionService::class)->open($submission->id, (int) $actorId),
        'expire_reservation' => app(InventoryReservationExpirationService::class)->expireOrder((int) $submission->order_id),
        'cancel_order' => app(OrderFulfillmentService::class)->cancelFulfillment($submission->order()->firstOrFail(), User::findOrFail($actorId), 'Concurrency certification'),
        default => throw new RuntimeException('invalid_action'),
    };
    $transaction = $action === 'open_resolution' ? PaymentTransaction::where('payment_submission_id', $submission->id)->first() : null;
    echo json_encode(['result' => 'success', 'ok' => true, 'status' => 200, 'case_id' => data_get($result, 'id'), 'transaction_id' => $transaction?->id]);
} catch (HttpResponseException $exception) {
    $response = $exception->getResponse();
    $body = json_decode($response->getContent(), true);
    $message = is_array($body) && is_string($body['message'] ?? null) ? strip_tags($body['message']) : 'La operación no pudo completarse.';
    echo json_encode(['result' => 'http_response', 'ok' => false, 'status' => $response->getStatusCode(), 'code' => is_array($body) ? ($body['code'] ?? null) : null, 'message' => substr($message, 0, 300)]);
} catch (Throwable) {
    echo json_encode(['result' => 'unexpected_error', 'ok' => false]);
    exit(1);
}
