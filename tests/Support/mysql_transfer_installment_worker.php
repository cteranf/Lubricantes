<?php

use App\Models\Order;
use App\Models\PaymentSubmission;
use App\Services\CustomerPaymentSubmissionService;
use App\Services\TreasuryPaymentApprovalService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$action, $barrier, $ready, $orderId, $submissionId, $userId, $accountId, $amount, $operation, $key] = array_slice($argv, 1);
if (DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'lubristore_payment_installments_test') {
    fwrite(STDERR, "Worker refused non-isolated database.\n");
    exit(3);
}

touch($ready);
$deadline = microtime(true) + 20;
while (! file_exists($barrier) && microtime(true) < $deadline) {
    usleep(10_000);
}
if (! file_exists($barrier)) {
    echo json_encode(['result' => 'timeout']);
    exit(2);
}

try {
    if ($action === 'submit') {
        [$submission] = app(CustomerPaymentSubmissionService::class)->submit(Order::query()->findOrFail($orderId), (int) $userId, [
            'amount' => $amount,
            'channel' => 'bank_transfer',
            'receiving_account_id' => (int) $accountId,
            'operation_number' => $operation,
            'paid_at' => now()->subMinute()->toIso8601String(),
            'origin_phone_last_four' => null,
            'origin_bank' => 'Banco de prueba',
        ], $key);
        echo json_encode(['result' => 'submitted', 'submission_id' => $submission->id]);
    } else {
        $submission = app(TreasuryPaymentApprovalService::class)->approve((int) $submissionId, (int) $userId);
        echo json_encode(['result' => 'approved', 'submission_id' => $submission->id]);
    }
} catch (HttpResponseException $exception) {
    echo json_encode(['result' => 'controlled_error', 'status' => $exception->getResponse()->getStatusCode(), 'code' => data_get($exception->getResponse()->getData(true), 'code')]);
} catch (ValidationException $exception) {
    echo json_encode(['result' => 'controlled_error', 'status' => 422, 'code' => 'validation_error']);
} catch (Throwable $exception) {
    echo json_encode(['result' => 'unexpected_error', 'type' => class_basename($exception)]);
    exit(1);
}
