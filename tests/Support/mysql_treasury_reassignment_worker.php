<?php

use App\Exceptions\InsufficientVersionedInventoryStockException;
use App\Exceptions\PaymentReassignmentConflictException;
use App\Models\InventoryReservation;
use App\Models\OrderFulfillmentHistory;
use App\Models\PaymentRefund;
use App\Models\PaymentResolutionCase;
use App\Models\PaymentResolutionHistory;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryReservationExpirationService;
use App\Services\InventoryService;
use App\Services\OrderFulfillmentService;
use App\Services\PaymentReassignmentFinalState;
use App\Services\TreasuryPaymentReassignmentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$arguments = array_slice($argv, 1);
[$submissionId, $actorId, $readyPath, $releaseLockName, $action, $payloadJson] = $arguments + [null, null, null, null, null, null];

if (! in_array(count($arguments), [5, 6], true)) {
    echo json_encode(['result' => 'worker_error', 'ok' => false]);
    exit(2);
}

if (! in_array($action, ['reassign', 'expire_reservation', 'cancel_order', 'transfer_inventory', 'barrier_probe_after_bootstrap'], true)
    || ! is_numeric($submissionId)
    || ! is_numeric($actorId)
    || ! is_dir(dirname($readyPath))
    || ! is_writable(dirname($readyPath))
    || ! in_array(basename($readyPath), ['ready-first', 'ready-second'], true)
    || ! is_string($releaseLockName)
    || strlen($releaseLockName) > 64
    || preg_match('/\A[a-z0-9-]+\z/', $releaseLockName) !== 1) {
    echo json_encode(['result' => 'worker_error', 'ok' => false]);
    exit(2);
}

$payload = null;
if ($action === 'transfer_inventory') {
    $payload = is_string($payloadJson) ? json_decode($payloadJson, true) : null;
    $payloadValuesArePositiveIntegers = is_array($payload);
    if ($payloadValuesArePositiveIntegers) {
        foreach ($payload as $value) {
            if (! is_int($value) || $value < 1) {
                $payloadValuesArePositiveIntegers = false;
                break;
            }
        }
    }
    if (! is_array($payload)
        || array_keys($payload) !== ['product_id', 'source_warehouse_id', 'destination_warehouse_id', 'quantity']
        || ! $payloadValuesArePositiveIntegers) {
        echo json_encode(['result' => 'worker_error', 'ok' => false]);
        exit(2);
    }
} elseif ($payloadJson !== null) {
    echo json_encode(['result' => 'worker_error', 'ok' => false]);
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::purge();
DB::reconnect();

$database = DB::selectOne('SELECT DATABASE() AS name')->name ?? null;
$version = DB::selectOne('SELECT VERSION() AS version')->version ?? '';
if (app()->environment() !== 'testing'
    || config('app.debug')
    || DB::connection()->getDriverName() !== 'mysql'
    || $database !== getenv('LARAVEL13_MYSQL_TEST_DATABASE')
    || preg_match('/\Alubristore_php85_laravel13_[a-z0-9_]+\z/', (string) getenv('LARAVEL13_MYSQL_TEST_DATABASE')) !== 1
    || $database === 'lubricantes'
    || ! str_starts_with((string) $version, '8.')) {
    echo json_encode(['result' => 'worker_error', 'ok' => false]);
    exit(2);
}

$releaseLockHash = hash('sha256', $releaseLockName);
$readyMessage = json_encode(['release_lock_hash' => $releaseLockHash]);
if (! is_string($readyMessage)
    || @file_put_contents($readyPath, $readyMessage, LOCK_EX) !== strlen($readyMessage)
    || ! is_file($readyPath)) {
    echo json_encode(['result' => 'ready_creation_failed', 'ok' => false, 'stage' => 'ready']);
    exit(2);
}

$acquired = DB::selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$releaseLockName, 20])->acquired ?? null;
if ($acquired === null) {
    echo json_encode(['result' => 'release_lock_error', 'ok' => false]);
    exit(2);
}
if ((int) $acquired === 0) {
    echo json_encode(['result' => 'release_timeout', 'ok' => false]);
    exit(2);
}

$released = DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$releaseLockName])->released ?? null;
if ((int) $released !== 1) {
    echo json_encode(['result' => 'release_lock_error', 'ok' => false]);
    exit(2);
}

if ($action === 'barrier_probe_after_bootstrap') {
    echo json_encode(['result' => 'success', 'ok' => true, 'release_observed' => true]);
    exit(0);
}

try {
    $actor = User::findOrFail((int) $actorId);
    if ($action === 'transfer_inventory') {
        $product = Product::findOrFail($payload['product_id']);
        $source = Warehouse::findOrFail($payload['source_warehouse_id']);
        $destination = Warehouse::findOrFail($payload['destination_warehouse_id']);
        app(InventoryService::class)->transfer($product, $source, $destination, $payload['quantity'], 'Concurrency certification', $actor);
        $case = null;
    } else {
        $submission = PaymentSubmission::findOrFail((int) $submissionId);
        $case = match ($action) {
            'reassign' => app(TreasuryPaymentReassignmentService::class)->reassignAndApprove($submission, $actor),
            'expire_reservation' => app(InventoryReservationExpirationService::class)->expireOrder((int) $submission->order_id),
            'cancel_order' => app(OrderFulfillmentService::class)->cancelFulfillment($submission->order()->firstOrFail(), $actor, 'Concurrency certification'),
        };
    }
    echo json_encode([
        'result' => 'success',
        'ok' => true,
        'status' => 200,
        'case_id' => $action === 'reassign' ? data_get($case, 'id') : null,
        'release_observed' => true,
    ]);
    exit(0);
} catch (InsufficientVersionedInventoryStockException) {
    echo json_encode([
        'result' => 'domain_conflict',
        'ok' => false,
        'status' => 409,
        'code' => 'insufficient_stock',
        'message' => 'No existe stock suficiente para completar la reasignación.',
        'release_observed' => true,
    ]);
    exit(0);
} catch (PaymentReassignmentConflictException|ValidationException $exception) {
    $reason = match ($exception->getMessage()) {
        'La reasignación existente está incompleta o es incompatible.' => 'existing_final_inconsistent',
        'La estructura financiera de la reasignación es incompatible.' => 'initial_financial_structure',
        'La presentación, transacción o resolución no cumple el contrato financiero.' => 'initial_financial_contract',
        'El pedido ya inició una operación incompatible con la reasignación.' => 'operational_state',
        'El pedido tiene picking, packing o cancelación operativa iniciada.' => 'handling_started',
        'Existe otra resolución cerrada para el pedido.' => 'other_resolution_closed',
        'La evidencia original de reservas es incompleta o incompatible.' => 'reservation_provenance',
        default => 'other_allowlisted_conflict',
    };
    $submission = PaymentSubmission::query()->with('order')->find((int) $submissionId);
    $order = $submission?->order;
    $linkedTransactions = $submission ? PaymentTransaction::query()->where('payment_submission_id', $submission->id)->orderBy('id')->get() : collect();
    $transaction = $linkedTransactions->first();
    $cases = $submission ? PaymentResolutionCase::query()->where('payment_submission_id', $submission->id)->orderBy('id')->get() : collect();
    $case = $cases->first();
    $state = null;
    if ($submission && $order && $transaction && $case) {
        $reservations = InventoryReservation::query()->where('resolution_case_id', $case->id)->get();
        try {
            DB::transaction(fn () => app(InventoryService::class)->assertCoherentConsumedReservationsForResolutionCase($order, $case));
            $inventoryCoherent = true;
        } catch (Throwable) {
            $inventoryCoherent = false;
        }
        $state = [
            'submission_status' => $submission->status,
            'order_status' => $order->status,
            'tracking_status' => $order->tracking_status,
            'order_fulfillment' => $order->fulfillment_status,
            'payment_status' => $order->payment_status,
            'transaction_status' => $transaction->status,
            'scope_matches' => $transaction->approved_scope_key === PaymentTransaction::approvedScopeKeyForOrder($order->id),
            'linked_transaction_count' => $linkedTransactions->count(),
            'case_count' => $cases->count(),
            'approved_transaction_count' => PaymentTransaction::query()->where('order_id', $order->id)->where('transaction_type', PaymentTransaction::PAYMENT)->where('status', PaymentTransaction::APPROVED)->count(),
            'approved_transaction_matches_linked' => (int) PaymentTransaction::query()->where('order_id', $order->id)->where('transaction_type', PaymentTransaction::PAYMENT)->where('status', PaymentTransaction::APPROVED)->value('id') === (int) $transaction->id,
            'refund_exists' => PaymentRefund::query()->where('payment_resolution_case_id', $case->id)->exists(),
            'case_status' => $case->status,
            'case_type' => $case->type,
            'reservation_statuses' => $reservations->pluck('status')->all(),
            'reservation_expiry_matches' => $order->reserved_until !== null && $reservations->every(fn ($reservation) => $reservation->expires_at?->getTimestamp() === $order->reserved_until->getTimestamp()),
            'consumed_inventory_coherent' => $inventoryCoherent,
            'final_snapshot_coherent' => PaymentReassignmentFinalState::isCoherent(
                $order,
                $submission,
                $transaction,
                $case,
                PaymentSubmissionHistory::query()->where('payment_submission_id', $submission->id)->get(),
                PaymentResolutionHistory::query()->where('payment_resolution_case_id', $case->id)->get(),
                OrderFulfillmentHistory::query()->where('order_id', $order->id)->get(),
                $reservations->count(),
            ),
        ];
    }
    echo json_encode([
        'result' => 'domain_conflict',
        'ok' => false,
        'status' => 409,
        'code' => 'payment_reassignment_conflict',
        'message' => 'La reasignación no puede completarse en el estado actual.',
        'reason' => $reason,
        'state' => $state,
        'previous_exception_class' => $exception->getPrevious() ? get_class($exception->getPrevious()) : null,
        'release_observed' => true,
    ]);
    exit(0);
} catch (Throwable $exception) {
    $isQueryException = $exception instanceof QueryException;
    $errorInfo = $isQueryException && is_array($exception->errorInfo) ? $exception->errorInfo : [];
    $previousException = $exception->getPrevious();
    echo json_encode([
        'result' => $isQueryException ? 'database_error' : 'unexpected_error',
        'ok' => false,
        'release_observed' => true,
        'exception_class' => $exception::class,
        'stage' => 'financial_action',
        'sqlstate' => $isQueryException && is_string($errorInfo[0] ?? null) ? $errorInfo[0] : null,
        'driver_code' => $isQueryException && is_numeric($errorInfo[1] ?? null) ? (int) $errorInfo[1] : null,
        'previous_exception_class' => $previousException ? $previousException::class : null,
    ]);
    exit(1);
}
