<?php

use App\Models\PaymentSubmission;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\InventoryReservationExpirationService;
use App\Services\OrderFulfillmentService;
use App\Services\TreasuryPaymentApprovalService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$submissionId, $treasuryId, $barrier, $action] = array_slice($argv, 1);
$deadline = microtime(true) + 15;
while (! file_exists($barrier) && microtime(true) < $deadline) {
    usleep(10_000);
}

if (! file_exists($barrier)) {
    echo json_encode(['result' => 'timeout']);
    exit(2);
}

try {
    if ($action === 'cancel') {
        $submission = PaymentSubmission::findOrFail($submissionId);
        app(OrderFulfillmentService::class)->cancelFulfillment($submission->order()->firstOrFail(), User::findOrFail($treasuryId), 'Physical concurrency certification cancellation.');
        echo json_encode(['result' => 'canceled']);
    } elseif ($action === 'expire') {
        $outcome = app(InventoryReservationExpirationService::class)->expireOrder((int) PaymentSubmission::findOrFail($submissionId)->order_id);
        echo json_encode(['result' => 'expired', 'processed' => (bool) $outcome['processed']]);
    } else {
        $submission = app(TreasuryPaymentApprovalService::class)->approve((int) $submissionId, (int) $treasuryId);
        $transaction = PaymentTransaction::query()->where('payment_submission_id', $submission->id)->first();
        echo json_encode(['result' => 'approved', 'submission_id' => $submission->id, 'transaction_id' => $transaction?->id]);
    }
} catch (HttpResponseException $exception) {
    echo json_encode(['result' => 'controlled_error', 'status' => $exception->getResponse()->getStatusCode(), 'code' => data_get($exception->getResponse()->getData(true), 'code')]);
} catch (ValidationException $exception) {
    echo json_encode(['result' => 'controlled_error', 'status' => 422, 'code' => 'validation_error']);
} catch (Throwable $exception) {
    echo json_encode(['result' => 'unexpected_error', 'type' => class_basename($exception)]);
    exit(1);
}
