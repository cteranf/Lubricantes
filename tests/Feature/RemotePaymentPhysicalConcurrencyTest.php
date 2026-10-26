<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\PaymentPreference;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\WarehouseInventory;
use App\Services\InventoryService;
use App\Services\MoneyService;
use App\Services\PaymentGateway\MercadoPagoGateway;
use App\Services\PaymentGateway\PaymentGatewayInterface;
use App\Services\PaymentPreferenceService;
use Illuminate\Support\Facades\DB;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;
use Tests\Feature\Concerns\SyntheticMercadoPagoLink;
use Tests\TestCase;

/** Physical MySQL races; intentionally does not use RefreshDatabase. */
class RemotePaymentPhysicalConcurrencyTest extends TestCase
{
    use SyntheticMercadoPagoLink;

    private const WEBHOOK_SECRET = 'physical-synthetic-webhook-secret';

    public function test_remote_payment_webhook_races_are_serialized_by_real_mysql_locks(): void
    {
        $this->assertSyntheticDatabaseIsolation();
        $this->assertSyntheticExternalCredentials();
        $this->assertSame(0, DB::connection()->transactionLevel(), 'Physical fixtures require committed autocommit setup.');
        $this->assertPerformanceSchemaLockObserverAvailable();
        $triggerCountBefore = (int) DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::raw('DATABASE()'))->count();

        $scenarios = [
            'identical_approved' => ['approved', 'approved', true],
            'distinct_approved' => ['approved', 'approved', false],
            'pending_then_approved' => ['pending', 'approved', false],
            'approved_then_pending' => ['approved', 'pending', false],
        ];
        $summary = ['races' => 0, 'lock_pairs' => 0, 'winner' => 'A', 'victim' => 'B', 'deadlock_sql_codes' => [], 'transaction_attempts' => 0];

        foreach ($scenarios as $name => [$statusA, $statusB, $sameEvent]) {
            for ($round = 1; $round <= 10; $round++) {
                $root = $this->createOwnedTempDirectory();
                $owned = [];
                $fixture = [];
                try {
                    $fixture = $this->createCommittedFixture($owned, $root, $name, $round, $statusA, $statusB, $sameEvent);
                    $this->assertCommittedFixtureVisibleFromIndependentConnection($fixture);
                    $codesBefore = $this->innodbDeadlocks();
                    $observation = $this->runObservedRace($root, $fixture);
                    $codesAfter = $this->innodbDeadlocks();
                    foreach (['worker_a', 'worker_b'] as $worker) {
                        $result = $observation[$worker]['result'];
                        if (($result['vendor_code'] ?? null) === 1205) {
                            $this->fail('MySQL lock wait timeout 1205 is forbidden in this harness.');
                        }
                        if (($result['vendor_code'] ?? null) === 1213 || ($result['sql_state'] ?? null) === '40001') {
                            $summary['deadlock_sql_codes']['40001'] = true;
                            $summary['deadlock_sql_codes']['1213'] = true;
                        }
                    }
                    $this->assertSame(0, $observation['worker_a']['exit_code'], 'The first webhook worker must complete.');
                    $this->assertSame(0, $observation['worker_b']['exit_code'], 'The second webhook worker must complete.');
                    foreach (['worker_a', 'worker_b'] as $worker) {
                        $result = $observation[$worker]['result'];
                        $this->assertSame(200, $result['http_status'] ?? null, 'Each authenticated synthetic webhook must succeed.');
                        $this->assertSame('ok', $result['status'] ?? null, 'Each authenticated synthetic webhook must be acknowledged.');
                        $this->assertSame(2, $result['sdk_calls'] ?? null, 'Each worker must use the real SDK through its two-call simulated transport.');
                    }
                    $this->assertSame($observation['expected_requesting_id'], $observation['lock']['requesting_connection_id']);
                    $this->assertSame($observation['expected_blocking_id'], $observation['lock']['blocking_connection_id']);
                    $this->assertSame('orders', $observation['lock']['table']);
                    $this->assertTrue($observation['lock']['schema_matches']);
                    $attempts = $observation['attempts_a'] + $observation['attempts_b'];
                    $summary['transaction_attempts'] += $attempts;
                    if ($codesAfter > $codesBefore) {
                        $this->assertGreaterThan(2, $attempts, 'A detected deadlock must re-enter the transaction callback on retry.');
                        $summary['deadlock_sql_codes']['40001'] = true;
                        $summary['deadlock_sql_codes']['1213'] = true;
                    }
                    $this->assertGreaterThanOrEqual(1, $observation['attempts_a']);
                    $this->assertGreaterThanOrEqual(1, $observation['attempts_b']);
                    $this->assertFinalInvariants($fixture, $sameEvent ? 1 : 2);

                    $summary['races']++;
                    $summary['lock_pairs']++;
                } finally {
                    $this->cleanupOwnedFixture($owned);
                    $this->assertOwnedFixtureRemoved($owned);
                    $this->removeOwnedTempDirectory($root);
                }
                $this->assertFalse(is_dir($root), 'The owned temporary race directory must be removed.');
            }
        }

        $this->assertSame(40, $summary['races']);
        $this->assertSame(40, $summary['lock_pairs']);
        if ($summary['deadlock_sql_codes'] === []) {
            $summary['deadlock_sql_codes'] = ['none'];
        } else {
            $summary['deadlock_sql_codes'] = array_map('strval', array_keys($summary['deadlock_sql_codes']));
        }
        $this->assertSame($triggerCountBefore, (int) DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::raw('DATABASE()'))->count(), 'The harness must not create or remove database triggers.');
        $this->assertSame(0, DB::table('products')->where('slug', 'like', 'physical-race-%')->count());
        $this->assertSame(0, DB::table('payment_preferences')->where('provider_preference_id', 'like', 'physical-synthetic-%')->count());
        fwrite(STDOUT, 'PHYSICAL_MYSQL_RACE_SUMMARY '.json_encode($summary, JSON_THROW_ON_ERROR).PHP_EOL);
    }

    public function test_two_distinct_approved_payments_race_to_same_order_routes_loser_to_review(): void
    {
        $this->assertSyntheticDatabaseIsolation();
        $this->assertSyntheticExternalCredentials();
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertPerformanceSchemaLockObserverAvailable();
        $triggerCountBefore = (int) DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::raw('DATABASE()'))->count();
        $summary = ['races' => 0, 'lock_pairs' => 0, 'winner' => 'A', 'reviewed' => 'B', 'deadlock_sql_codes' => [], 'transaction_attempts' => 0];

        for ($round = 1; $round <= 10; $round++) {
            $root = $this->createOwnedTempDirectory();
            $owned = [];
            $fixture = [];
            try {
                $fixture = $this->createCommittedFixture($owned, $root, 'two-approved-payment-ids', $round, 'approved', 'approved', false, true);
                $this->assertNotSame($fixture['payment_ids']['A'], $fixture['payment_ids']['B']);
                $this->assertCommittedFixtureVisibleFromIndependentConnection($fixture);
                $codesBefore = $this->innodbDeadlocks();
                $observation = $this->runObservedRace($root, $fixture);
                $codesAfter = $this->innodbDeadlocks();

                foreach (['worker_a', 'worker_b'] as $worker) {
                    $result = $observation[$worker]['result'];
                    if (($result['vendor_code'] ?? null) === 1205) {
                        $this->fail('MySQL lock wait timeout 1205 is forbidden in this harness.');
                    }
                }
                $this->assertSame(0, $observation['worker_a']['exit_code']);
                $this->assertSame(0, $observation['worker_b']['exit_code']);
                $this->assertSame(200, $observation['worker_a']['result']['http_status'] ?? null);
                $this->assertSame('ok', $observation['worker_a']['result']['status'] ?? null);
                $this->assertSame(202, $observation['worker_b']['result']['http_status'] ?? null);
                $this->assertSame('review_required', $observation['worker_b']['result']['status'] ?? null);
                $this->assertSame('financial_review_required', $observation['worker_b']['result']['code'] ?? null);
                $this->assertSame(2, $observation['worker_a']['result']['sdk_calls'] ?? null);
                $this->assertSame(2, $observation['worker_b']['result']['sdk_calls'] ?? null);
                $this->assertSame($observation['expected_requesting_id'], $observation['lock']['requesting_connection_id']);
                $this->assertSame($observation['expected_blocking_id'], $observation['lock']['blocking_connection_id']);
                $this->assertSame('orders', $observation['lock']['table']);
                $this->assertTrue($observation['lock']['schema_matches']);

                $attempts = $observation['attempts_a'] + $observation['attempts_b'];
                $summary['transaction_attempts'] += $attempts;
                if ($codesAfter > $codesBefore) {
                    $this->assertGreaterThan(2, $attempts, 'A detected deadlock must re-enter the transaction callback on retry.');
                    $summary['deadlock_sql_codes']['40001'] = true;
                    $summary['deadlock_sql_codes']['1213'] = true;
                }
                $this->assertGreaterThanOrEqual(1, $observation['attempts_a']);
                $this->assertGreaterThanOrEqual(1, $observation['attempts_b']);
                $this->assertCompetingApprovedInvariants($fixture);
                $summary['races']++;
                $summary['lock_pairs']++;
            } finally {
                $this->cleanupOwnedFixture($owned);
                $this->assertOwnedFixtureRemoved($owned);
                $this->removeOwnedTempDirectory($root);
            }
            $this->assertFalse(is_dir($root));
        }

        $this->assertSame(10, $summary['races']);
        $this->assertSame(10, $summary['lock_pairs']);
        if ($summary['deadlock_sql_codes'] === []) {
            $summary['deadlock_sql_codes'] = ['none'];
        } else {
            $summary['deadlock_sql_codes'] = array_map('strval', array_keys($summary['deadlock_sql_codes']));
        }
        $this->assertSame($triggerCountBefore, (int) DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::raw('DATABASE()'))->count());
        $this->assertSame(0, DB::table('products')->where('slug', 'like', 'physical-race-%')->count());
        $this->assertSame(0, DB::table('payment_preferences')->where('provider_preference_id', 'like', 'physical-synthetic-%')->count());
        fwrite(STDOUT, 'PHYSICAL_MYSQL_SECOND_PAYMENT_SUMMARY '.json_encode($summary, JSON_THROW_ON_ERROR).PHP_EOL);
    }

    private function assertCompetingApprovedInvariants(array $fixture): void
    {
        $order = Order::findOrFail($fixture['order_id']);
        $preference = PaymentPreference::findOrFail($order->current_payment_preference_id);
        $this->assertSame('approved', $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame('confirmed', $order->status);
        $this->assertNotSame('canceled', $order->status);
        $this->assertSame(PaymentPreference::ACTIVE, $preference->state);
        $this->assertSame('mercadopago', $preference->gateway);
        $this->assertSame($fixture['preference_id'], $preference->provider_preference_id);
        $this->assertSame($fixture['external_reference'], $preference->external_reference);

        $transactions = PaymentTransaction::where('order_id', $order->id)->where('transaction_type', PaymentTransaction::PAYMENT)->get();
        $this->assertCount(1, $transactions);
        $transaction = $transactions->sole();
        $this->assertSame('mercadopago', $transaction->gateway);
        $this->assertSame($fixture['payment_ids']['A'], $transaction->provider_payment_id);
        $this->assertSame((int) $preference->id, (int) $transaction->payment_preference_id);
        $this->assertSame(PaymentTransaction::APPROVED, $transaction->status);
        $this->assertSame('approved', $transaction->provider_status);
        $this->assertSame($fixture['payment_ids']['A'], $transaction->external_reference);
        $this->assertSame('PEN', $transaction->currency);
        $this->assertSame(1025, app(MoneyService::class)->toMinor($transaction->amount));
        $this->assertSame(0, PaymentTransaction::where('provider_payment_id', $fixture['payment_ids']['B'])->count());
        $this->assertSame(0, PaymentTransaction::where('order_id', $order->id)->where('status', PaymentTransaction::CANCELED)->where('approved_scope_key', PaymentTransaction::approvedScopeKeyForOrder($order->id))->count());

        $events = DB::table('payment_webhook_events')->where('gateway', 'mercadopago')->whereIn('provider_payment_id', array_values($fixture['payment_ids']))->get();
        $this->assertCount(2, $events);
        $winner = $events->firstWhere('provider_payment_id', $fixture['payment_ids']['A']);
        $reviewed = $events->firstWhere('provider_payment_id', $fixture['payment_ids']['B']);
        $this->assertNotNull($winner);
        $this->assertNotNull($reviewed);
        $this->assertSame('processed', $winner->status);
        $this->assertSame('review_required', $reviewed->status);
        $this->assertSame('financial_review_required', $reviewed->failure_reason);
        $this->assertSame($fixture['payment_ids']['B'], $reviewed->provider_payment_id);
        $this->assertSame(1, DB::table('payment_webhook_events')->where('provider_payment_id', $fixture['payment_ids']['A'])->where('status', 'processed')->count());
        $this->assertSame(0, DB::table('payment_webhook_events')->where('provider_payment_id', $fixture['payment_ids']['B'])->where('status', 'processed')->count());

        $reservations = InventoryReservation::where('order_id', $order->id)->get();
        $this->assertCount(1, $reservations);
        $this->assertSame(InventoryReservation::CONSUMED, $reservations->sole()->status);
        $this->assertNotNull($reservations->sole()->consumed_at);
        $inventory = WarehouseInventory::where('product_id', $fixture['product_id'])->sole();
        $this->assertSame(4, (int) $inventory->quantity);
        $this->assertSame(0, (int) $inventory->reserved_quantity);
        $this->assertSame((int) DB::table('warehouse_inventories')->where('product_id', $fixture['product_id'])->sum('quantity'), (int) Product::findOrFail($fixture['product_id'])->stock);
        $movements = InventoryMovement::where('idempotency_key', 'sale-order-item-'.$fixture['order_item_id'])->get();
        $this->assertCount(1, $movements);
        $this->assertSame(InventoryMovement::SALE, $movements->sole()->type);
        $this->assertSame(0, DB::table('order_fulfillment_histories')->where('order_id', $order->id)->count());
        $this->assertSame((int) $preference->id, (int) $order->current_payment_preference_id);
    }

    private function createCommittedFixture(array &$owned, string $root, string $scenario, int $round, string $statusA, string $statusB, bool $sameEvent, bool $distinctPaymentIds = false): array
    {
        $this->assertSyntheticDatabaseIsolation();
        $this->assertSyntheticExternalCredentials();
        $this->assertSame(0, DB::connection()->transactionLevel());
        $savedToken = MercadoPagoConfig::getAccessToken();
        $savedConfiguredToken = config('payment.mercadopago.access_token');
        $savedCurrency = config('payment.currency');
        $clientProperty = new \ReflectionProperty(MercadoPagoConfig::class, 'http_client');
        $savedClient = $clientProperty->getValue();

        try {
            $transport = new class implements MPHttpClient {
                public string $preferenceId;

                public function __construct()
                {
                    $this->preferenceId = 'physical-synthetic-'.bin2hex(random_bytes(12));
                }

                public function send(MPRequest $request): MPResponse
                {
                    if ($request->getMethod() !== 'POST' || $request->getUri() !== '/checkout/preferences') {
                        throw new \LogicException('Unexpected fixture SDK request.');
                    }
                    return new MPResponse(201, [
                        'id' => $this->preferenceId,
                        'init_point' => 'https://example.invalid/physical-checkout',
                        'sandbox_init_point' => 'https://example.invalid/physical-sandbox',
                    ]);
                }
            };
            config(['payment.mercadopago.access_token' => 'PARENT-PHYSICAL-SYNTHETIC-TOKEN', 'payment.currency' => 'PEN']);
            $gateway = new MercadoPagoGateway('PARENT-PHYSICAL-SYNTHETIC-TOKEN');
            MercadoPagoConfig::setHttpClient($transport);
            $this->app->instance(PaymentGatewayInterface::class, $gateway);

            $customer = User::factory()->create();
            $owned['user_id'] = (int) $customer->id;
            $order = Order::create([
                'user_id' => $customer->id, 'status' => 'pending', 'total' => '10.25',
                'shipping_info' => [], 'payment_method' => 'card', 'payment_status' => 'pending',
                'delivery_type' => 'delivery', 'tracking_status' => 'pending',
            ]);
            $owned['order_id'] = (int) $order->id;
            $product = Product::create([
                'name' => 'Synthetic physical race item',
                'slug' => 'physical-race-'.bin2hex(random_bytes(12)),
                'sku' => 'PHYS-'.strtoupper(bin2hex(random_bytes(6))),
                'price' => '10.25', 'is_active' => true,
            ]);
            $owned['product_id'] = (int) $product->id;
            $inventory = app(InventoryService::class);
            $warehouse = $inventory->defaultWarehouse();
            $inventory->initializeProduct($product, 5, $warehouse);
            $warehouseInventory = WarehouseInventory::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
            $owned['warehouse_inventory_id'] = (int) $warehouseInventory->id;
            $item = $order->items()->create([
                'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1,
                'price' => '10.25', 'subtotal' => '10.25',
            ]);
            $owned['order_item_id'] = (int) $item->id;
            $reservation = $inventory->reserveForOrder($item, now()->addMinutes(30));
            $owned['reservation_id'] = (int) $reservation->id;

            $preference = app(PaymentPreferenceService::class)->createFor($order, [
                'items' => [['name' => $product->name, 'quantity' => 1, 'price' => '10.25']],
                'customer' => ['name' => $customer->name, 'email' => 'physical-race@example.invalid'],
            ], 'mercadopago');
            $owned['preference_id'] = (int) $preference->id;
            $this->assertSame(PaymentPreference::ACTIVE, $preference->state);
            $this->assertSame((int) $order->id, (int) $preference->order_id);
            $this->assertSame((int) $preference->id, (int) $order->refresh()->current_payment_preference_id);
            $this->assertSame(0, DB::connection()->transactionLevel(), 'All fixture rows must be committed before workers start.');

            $paymentId = (string) random_int(110000000, 199999999);
            $secondPaymentId = $paymentId;
            if ($distinctPaymentIds) {
                do {
                    $secondPaymentId = (string) random_int(110000000, 199999999);
                } while ($secondPaymentId === $paymentId);
            }
            $merchantOrderId = (string) random_int(210000000, 299999999);
            $owned['payment_id'] = $paymentId;
            $owned['payment_ids'] = array_values(array_unique([$paymentId, $secondPaymentId]));
            $raceId = $scenario.'-'.$round.'-'.bin2hex(random_bytes(8));
            $eventA = 'event-a-'.$raceId;
            $eventB = $sameEvent ? $eventA : 'event-b-'.$raceId;
            return [
                'marker_root' => $root,
                'order_id' => (int) $order->id,
                'preference_id_local' => (int) $preference->id,
                'product_id' => (int) $product->id,
                'order_item_id' => (int) $item->id,
                'payment_id' => $paymentId,
                'payment_ids' => ['A' => $paymentId, 'B' => $secondPaymentId],
                'merchant_payment_ids' => array_values(array_unique([$paymentId, $secondPaymentId])),
                'merchant_order_id' => $merchantOrderId,
                'preference_id' => $preference->provider_preference_id,
                'external_reference' => $preference->external_reference,
                'amount' => '10.25', 'currency' => 'PEN',
                'webhook_secret' => self::WEBHOOK_SECRET,
                'statuses' => ['A' => $statusA, 'B' => $statusB],
                'event_ids' => ['A' => $eventA, 'B' => $eventB],
                'request_ids' => ['A' => 'req-a-'.$raceId, 'B' => $sameEvent ? 'req-a-'.$raceId : 'req-b-'.$raceId],
                'synthetic_event_count' => $sameEvent ? 1 : 2,
            ];
        } finally {
            $clientProperty->setValue(null, $savedClient);
            MercadoPagoConfig::setAccessToken($savedToken);
            config(['payment.mercadopago.access_token' => $savedConfiguredToken, 'payment.currency' => $savedCurrency]);
        }
    }

    private function runObservedRace(string $root, array $fixture): array
    {
        $fixturePath = $root.DIRECTORY_SEPARATOR.'worker-fixture.json';
        $this->writeAtomicJson($fixturePath, $fixture);
        $processes = [];
        try {
            $processes['A'] = $this->startOwnedPhpProcess(base_path('tests/Support/physical-remote-payment-worker.php'), ['--fixture', $fixturePath, '--role', 'A'], $root, 'A', $fixture);
            $this->assertTrue($this->waitForFile($root.DIRECTORY_SEPARATOR.'started-A.json', 10), 'The first worker must start independently.');
            $startedA = $this->readJson($root.DIRECTORY_SEPARATOR.'started-A.json');
            $this->assertTrue($this->waitForFile($root.DIRECTORY_SEPARATOR.'entered-A-1.json', 10), 'The first worker must hold the locked order row.');

            $processes['B'] = $this->startOwnedPhpProcess(base_path('tests/Support/physical-remote-payment-worker.php'), ['--fixture', $fixturePath, '--role', 'B'], $root, 'B', $fixture);
            $this->assertTrue($this->waitForFile($root.DIRECTORY_SEPARATOR.'started-B.json', 10), 'The second worker must start independently.');
            $startedB = $this->readJson($root.DIRECTORY_SEPARATOR.'started-B.json');
            $this->assertNotSame($startedA['connection_id'], $startedB['connection_id'], 'Workers must hold independent MySQL connections.');

            $observerFixture = $root.DIRECTORY_SEPARATOR.'observer-fixture.json';
            $this->writeAtomicJson($observerFixture, [
                'marker_root' => $root,
                'requesting_connection_id' => (int) $startedB['connection_id'],
                'blocking_connection_id' => (int) $startedA['connection_id'],
            ]);
            $processes['O'] = $this->startOwnedPhpProcess(base_path('tests/Support/physical-mysql-lock-observer.php'), ['--fixture', $observerFixture], $root, 'O', $fixture);
            $this->assertTrue($this->waitForFile($root.DIRECTORY_SEPARATOR.'lock-observed.json', 25), 'Independent MySQL observer must see the exact InnoDB lock pair.');
            $lock = $this->readJson($root.DIRECTORY_SEPARATOR.'lock-observed.json');
            $observerExit = $this->finishOwnedPhpProcess($processes['O']);
            unset($processes['O']);
            $this->assertSame(0, $observerExit, 'MySQL lock observer must complete.');

            $exitA = $this->finishOwnedPhpProcess($processes['A']);
            unset($processes['A']);
            $exitB = $this->finishOwnedPhpProcess($processes['B']);
            unset($processes['B']);
            $resultA = is_file($root.DIRECTORY_SEPARATOR.'result-A.json') ? $this->readJson($root.DIRECTORY_SEPARATOR.'result-A.json') : [];
            $resultB = is_file($root.DIRECTORY_SEPARATOR.'result-B.json') ? $this->readJson($root.DIRECTORY_SEPARATOR.'result-B.json') : [];

            return [
                'lock' => $lock,
                'expected_requesting_id' => (int) $startedB['connection_id'],
                'expected_blocking_id' => (int) $startedA['connection_id'],
                'worker_a' => ['exit_code' => $exitA, 'result' => $resultA],
                'worker_b' => ['exit_code' => $exitB, 'result' => $resultB],
                'attempts_a' => count(glob($root.DIRECTORY_SEPARATOR.'entered-A-*.json') ?: []),
                'attempts_b' => count(glob($root.DIRECTORY_SEPARATOR.'entered-B-*.json') ?: []),
            ];
        } finally {
            foreach ($processes as $process) {
                $this->stopOwnedPhpProcess($process);
            }
        }
    }

    private function assertFinalInvariants(array $fixture, int $expectedEvents): void
    {
        $order = Order::findOrFail($fixture['order_id']);
        $preference = PaymentPreference::findOrFail($order->current_payment_preference_id);
        $this->assertSame('approved', $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame('confirmed', $order->status);
        $this->assertNotSame('canceled', $order->status);
        $this->assertSame(PaymentPreference::ACTIVE, $preference->state);
        $this->assertSame('mercadopago', $preference->gateway);
        $this->assertSame($fixture['preference_id'], $preference->provider_preference_id);
        $this->assertSame($fixture['external_reference'], $preference->external_reference);

        $transactions = PaymentTransaction::where('gateway', 'mercadopago')->where('provider_payment_id', $fixture['payment_id'])->get();
        $this->assertCount(1, $transactions);
        $transaction = $transactions->sole();
        $this->assertSame((int) $order->id, (int) $transaction->order_id);
        $this->assertSame((int) $preference->id, (int) $transaction->payment_preference_id);
        $this->assertSame(PaymentTransaction::PAYMENT, $transaction->transaction_type);
        $this->assertSame(PaymentTransaction::APPROVED, $transaction->status);
        $this->assertSame('approved', $transaction->provider_status);
        $this->assertNotSame(PaymentTransaction::FAILED, $transaction->status);
        $this->assertSame($fixture['payment_id'], $transaction->external_reference);
        $this->assertSame('PEN', $transaction->currency);
        $this->assertSame(1025, app(MoneyService::class)->toMinor($transaction->amount));

        $reservations = InventoryReservation::where('order_id', $order->id)->get();
        $this->assertCount(1, $reservations);
        $this->assertSame(InventoryReservation::CONSUMED, $reservations->sole()->status);
        $this->assertNotNull($reservations->sole()->consumed_at);
        $inventory = WarehouseInventory::where('product_id', $fixture['product_id'])->sole();
        $this->assertSame(4, (int) $inventory->quantity);
        $this->assertSame(0, (int) $inventory->reserved_quantity);
        $this->assertSame((int) DB::table('warehouse_inventories')->where('product_id', $fixture['product_id'])->sum('quantity'), (int) Product::findOrFail($fixture['product_id'])->stock);

        $movements = InventoryMovement::where('idempotency_key', 'sale-order-item-'.$fixture['order_item_id'])->get();
        $this->assertCount(1, $movements);
        $this->assertSame(InventoryMovement::SALE, $movements->sole()->type);
        $this->assertSame($expectedEvents, DB::table('payment_webhook_events')->where('gateway', 'mercadopago')->where('provider_payment_id', $fixture['payment_id'])->where('status', 'processed')->count());
        $this->assertSame($expectedEvents, DB::table('payment_webhook_events')->where('gateway', 'mercadopago')->where('provider_payment_id', $fixture['payment_id'])->count());
        $historyCount = DB::table('order_fulfillment_histories')->where('order_id', $order->id)->count();
        $duplicateHistories = DB::table('order_fulfillment_histories')->select('idempotency_key')->where('order_id', $order->id)->groupBy('idempotency_key')->havingRaw('COUNT(*) > 1')->count();
        $this->assertSame(0, $historyCount, 'Remote payment processing must not create duplicate fulfillment history.');
        $this->assertSame(0, $duplicateHistories);
        $this->assertSame(0, DB::table('payment_transactions')->where('order_id', $order->id)->where('status', PaymentTransaction::CANCELED)->where('approved_scope_key', PaymentTransaction::approvedScopeKeyForOrder($order->id))->count());
        $this->assertSame((int) $preference->id, (int) $order->current_payment_preference_id);
    }

    private function assertCommittedFixtureVisibleFromIndependentConnection(array $fixture): void
    {
        $connection = config('database.connections.mysql');
        $pdo = new \PDO(
            'mysql:host='.$connection['host'].';port='.$connection['port'].';dbname='.$connection['database'].';charset='.$connection['charset'],
            $connection['username'], $connection['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $order = $pdo->prepare('SELECT current_payment_preference_id FROM orders WHERE id = ?');
        $order->execute([$fixture['order_id']]);
        $preferenceId = $order->fetchColumn();
        $this->assertNotFalse($preferenceId, 'Committed synthetic order must be visible to another connection.');
        $this->assertSame((int) $fixture['preference_id_local'], (int) $preferenceId);
        $reservation = $pdo->prepare("SELECT status FROM inventory_reservations WHERE order_id = ?");
        $reservation->execute([$fixture['order_id']]);
        $this->assertSame('active', $reservation->fetchColumn());
        $pdo = null;
    }

    private function assertSyntheticExternalCredentials(): void
    {
        $this->assertSame('TEST-SYNTHETIC-ACCESS-TOKEN', config('payment.mercadopago.access_token'), 'Only local synthetic provider credentials are permitted.');
        $this->assertSame('TEST-SYNTHETIC-PUBLIC-KEY', config('payment.mercadopago.public_key'), 'Only local synthetic provider credentials are permitted.');
        $this->assertSame('synthetic-local-webhook-secret', config('payment.mercadopago.webhook_secret'), 'Only local synthetic provider credentials are permitted.');
    }

    private function assertPerformanceSchemaLockObserverAvailable(): void
    {
        $pdo = DB::connection()->getPdo();
        $count = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'performance_schema' AND table_name IN ('data_lock_waits', 'data_locks')")->fetchColumn();
        $this->assertSame(2, (int) $count, 'Physical race certification requires MySQL performance_schema lock visibility.');
        $pdo->query('SELECT COUNT(*) FROM performance_schema.data_lock_waits')->fetchColumn();
        $pdo->query('SELECT COUNT(*) FROM performance_schema.data_locks')->fetchColumn();
    }

    private function innodbDeadlocks(): int
    {
        $row = DB::selectOne("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'");
        return is_object($row) ? (int) ($row->Value ?? 0) : 0;
    }

    private function workerEnvironment(string $role, array $fixture): array
    {
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $connection = config('database.connections.mysql');
        $environment = array_merge($environment, [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'], 'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'], 'DB_URL' => '',
            'MERCADOPAGO_ACCESS_TOKEN' => '', 'MERCADOPAGO_PUBLIC_KEY' => '', 'MERCADOPAGO_WEBHOOK_SECRET' => '',
            'SYNTHETIC_MP_RACE_FIXTURE' => $fixture['fixture_path'], 'SYNTHETIC_MP_RACE_ROLE' => $role,
        ]);
        foreach (['DB_READ_HOST', 'DB_WRITE_HOST', 'DB_SOCKET'] as $unset) {
            unset($environment[$unset]);
        }
        return $environment;
    }

    private function startOwnedPhpProcess(string $script, array $arguments, string $root, string $role, array $fixture)
    {
        $command = array_merge([PHP_BINARY, $script], $arguments);
        $descriptors = [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', $root.DIRECTORY_SEPARATOR.'stdout-'.$role.'.log', 'a'],
            2 => ['file', $root.DIRECTORY_SEPARATOR.'stderr-'.$role.'.log', 'a'],
        ];
        $fixture['fixture_path'] = $root.DIRECTORY_SEPARATOR.'worker-fixture.json';
        $process = proc_open($command, $descriptors, $pipes, base_path(), $this->workerEnvironment($role, $fixture), ['bypass_shell' => true]);
        $this->assertTrue(is_resource($process), 'An isolated PHP worker process must start.');
        return $process;
    }

    private function finishOwnedPhpProcess($process): int
    {
        $exit = null;
        while (true) {
            $status = proc_get_status($process);
            if (! $status['running']) {
                $exit = (int) $status['exitcode'];
                break;
            }
            usleep(20000);
        }
        $closed = proc_close($process);
        return $exit ?? (int) $closed;
    }

    private function stopOwnedPhpProcess($process): void
    {
        if (! is_resource($process)) {
            return;
        }
        $status = proc_get_status($process);
        if ($status['running']) {
            proc_terminate($process);
        }
        proc_close($process);
    }

    private function waitForFile(string $path, int $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (! is_file($path) && microtime(true) < $deadline) {
            usleep(20000);
        }
        return is_file($path);
    }

    private function writeAtomicJson(string $path, array $data): void
    {
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(6));
        file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX);
        if (! rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Owned harness fixture could not be installed.');
        }
    }

    private function readJson(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new \RuntimeException('Owned harness marker is invalid.');
        }
        return $data;
    }

    private function createOwnedTempDirectory(): string
    {
        $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'synthetic-mp-race-'.bin2hex(random_bytes(16));
        if (! mkdir($root, 0700)) {
            throw new \RuntimeException('Owned race directory could not be created.');
        }
        return $root;
    }

    private function removeOwnedTempDirectory(string $root): void
    {
        $base = realpath(sys_get_temp_dir());
        $resolved = realpath($root);
        if ($resolved === false) {
            return;
        }
        if (! is_string($base) || ! str_starts_with($resolved, $base.DIRECTORY_SEPARATOR.'synthetic-mp-race-')) {
            throw new \RuntimeException('Harness cleanup path escaped its owned temporary directory.');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($resolved);
    }

    private function cleanupOwnedFixture(array $owned): void
    {
        $orderId = (int) ($owned['order_id'] ?? 0);
        $productId = (int) ($owned['product_id'] ?? 0);
        $userId = (int) ($owned['user_id'] ?? 0);
        if ($orderId < 1 && $productId < 1 && $userId < 1) {
            return;
        }
        $paymentIds = array_values(array_unique(array_filter(array_map('strval', $owned['payment_ids'] ?? [$owned['payment_id'] ?? '']))));
        DB::transaction(function () use ($owned, $orderId, $productId, $userId, $paymentIds) {
            $ids = static fn (string $table, string $column, int $value): array => $value > 0 ? DB::table($table)->where($column, $value)->pluck('id')->all() : [];
            $transactionIds = $ids('payment_transactions', 'order_id', $orderId);
            $historyIds = $ids('order_fulfillment_histories', 'order_id', $orderId);
            $reservationIds = $ids('inventory_reservations', 'order_id', $orderId);
            $itemIds = $ids('order_items', 'order_id', $orderId);
            $movementIds = $productId > 0 ? DB::table('inventory_movements')->where('product_id', $productId)->pluck('id')->all() : [];
            $inventoryIds = $productId > 0 ? DB::table('warehouse_inventories')->where('product_id', $productId)->pluck('id')->all() : [];
            $eventIds = $paymentIds !== [] ? DB::table('payment_webhook_events')->whereIn('provider_payment_id', $paymentIds)->pluck('id')->all() : [];

            foreach ([['payment_transactions', $transactionIds], ['payment_webhook_events', $eventIds], ['order_fulfillment_histories', $historyIds], ['inventory_movements', $movementIds], ['inventory_reservations', $reservationIds], ['order_items', $itemIds], ['warehouse_inventories', $inventoryIds]] as [$table, $rowIds]) {
                if ($rowIds !== []) {
                    DB::table($table)->whereIn('id', $rowIds)->delete();
                }
            }
            if ($orderId > 0) {
                DB::table('orders')->where('id', $orderId)->update(['current_payment_preference_id' => null]);
            }
            $preferenceIds = $orderId > 0 ? DB::table('payment_preferences')->where('order_id', $orderId)->pluck('id')->all() : [];
            if ($preferenceIds !== []) {
                DB::table('payment_preferences')->whereIn('id', $preferenceIds)->delete();
            }
            if ($orderId > 0) {
                DB::table('orders')->where('id', $orderId)->delete();
            }
            if ($productId > 0) {
                DB::table('products')->where('id', $productId)->delete();
            }
            if ($userId > 0) {
                DB::table('users')->where('id', $userId)->delete();
            }
        }, 1);
    }

    private function assertOwnedFixtureRemoved(array $owned): void
    {
        foreach ([['orders', 'id', 'order_id'], ['payment_preferences', 'id', 'preference_id'], ['products', 'id', 'product_id'], ['order_items', 'id', 'order_item_id'], ['inventory_reservations', 'id', 'reservation_id'], ['warehouse_inventories', 'id', 'warehouse_inventory_id']] as [$table, $column, $key]) {
            if (! empty($owned[$key])) {
                $this->assertSame(0, DB::table($table)->where($column, (int) $owned[$key])->count(), 'Owned synthetic fixture row must be removed by its ID.');
            }
        }
        if (! empty($owned['order_id'])) {
            $this->assertSame(0, DB::table('payment_transactions')->where('order_id', (int) $owned['order_id'])->count());
            $this->assertSame(0, DB::table('order_fulfillment_histories')->where('order_id', (int) $owned['order_id'])->count());
        }
        if (! empty($owned['product_id'])) {
            $this->assertSame(0, DB::table('inventory_movements')->where('product_id', (int) $owned['product_id'])->count());
        }
        $paymentIds = array_values(array_unique(array_filter(array_map('strval', $owned['payment_ids'] ?? [$owned['payment_id'] ?? '']))));
        if ($paymentIds !== []) {
            $this->assertSame(0, DB::table('payment_webhook_events')->whereIn('provider_payment_id', $paymentIds)->count());
            $this->assertSame(0, DB::table('payment_transactions')->whereIn('provider_payment_id', $paymentIds)->count());
        }
    }

}
