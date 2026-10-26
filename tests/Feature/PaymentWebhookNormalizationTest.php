<?php

namespace Tests\Feature;

use App\Models\PaymentWebhookEvent;
use App\Models\PaymentTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Concerns\SyntheticMercadoPagoLink;
use Tests\TestCase;

class PaymentWebhookNormalizationTest extends TestCase
{
    use RefreshDatabase;
    use SyntheticMercadoPagoLink;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = 'SYNTHETIC_'.bin2hex(random_bytes(16));
        config(['payment.testing_legacy_webhook' => false, 'payment.mercadopago.webhook_secret' => $this->secret, 'payment.mock.enabled' => false, 'app.debug' => false]);
    }

    private function headers(string $id): array
    {
        $requestId = 'synthetic-'.bin2hex(random_bytes(16));
        $ts = (string) time();
        return ['x-request-id' => $requestId, 'x-signature' => 'ts='.$ts.',v1='.hash_hmac('sha256', 'id:'.$id.';request-id:'.$requestId.';ts:'.$ts.';', $this->secret)];
    }

    private function deliver(string $query, ?string $body, array $headers)
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'QUERY_STRING' => $query];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        return $this->call('POST', '/api/v1/payment/webhook'.($query === '' ? '' : '?'.$query), [], [], [], $server, $body ?? '');
    }

    private function payload(string $id, ?string $event = null): string
    {
        return json_encode(['type' => 'payment', 'data' => ['id' => $id], 'id' => $event ?? 'synthetic-'.bin2hex(random_bytes(16)), 'action' => 'payment.updated'], JSON_THROW_ON_ERROR);
    }

    /** @dataProvider supportedFormats */
    public function test_authenticated_supported_formats_use_real_sdk_evidence(string $format): void
    {
        $this->withRemoteLink(function ($transport, $order) use ($format) {
            $id = (string) $transport->paymentId;
            $query = match ($format) {
                'json' => '',
                'encoded' => '%64ata%2Eid=%'.bin2hex($id[0]).substr($id, 1).'&%74ype=%70ayment',
                default => 'data.id='.$id.'&type=payment',
            };
            $body = in_array($format, ['json', 'both'], true) ? $this->payload($id) : null;
            $response = $this->deliver($query, $body, $this->headers($id));
            $this->assertTrue($response->status() === 200, 'Authenticated supported format must succeed.');
            $this->assertSame(['status' => 'ok'], $response->json());
            $this->assertSame(2, count($transport->calls));
            $this->assertSame('approved', $order->refresh()->payment_status);
            $this->assertDatabaseCount('payment_transactions', 1);
            $this->assertSame(PaymentWebhookEvent::PROCESSED, PaymentWebhookEvent::sole()->status);
        });
    }

    public static function supportedFormats(): array
    {
        return [['json'], ['query'], ['both'], ['encoded']];
    }

    /** @dataProvider invalidEnvelopes */
    public function test_invalid_envelope_has_no_gateway_calls_events_or_writes(string $case): void
    {
        $this->withRemoteLink(function ($transport) use ($case) {
            $id = (string) $transport->paymentId;
            $body = $this->payload($id);
            $query = '';
            switch ($case) {
                case 'contradictory ids': $query = 'data.id='.($transport->paymentId + 1).'&type=payment'; break;
                case 'literal duplicate': $query = 'data.id='.$id.'&data.id='.$id.'&type=payment'; break;
                case 'encoded duplicate': $query = 'data.id='.$id.'&data%2Eid='.$id.'&type=payment'; break;
                case 'encoded topic duplicate': $query = 'type=payment&%74ype=payment'; break;
                case 'leading empty segment': $query = '&type=payment'; break;
                case 'middle empty segment': $query = 'type=payment&&data.id='.$id; break;
                case 'trailing empty segment': $query = 'type=payment&'; break;
                case 'missing equals': $query = 'type=payment&data.id'; break;
                case 'brackets': $query = 'data%5Bid%5D='.$id.'&type=payment'; break;
                case 'array': $query = 'data.id%5B%5D='.$id.'&type=payment'; break;
                case 'underscore alias': $query = 'data_id='.$id.'&type=payment'; break;
                case 'space alias': $query = 'data+id='.$id.'&type=payment'; break;
                case 'double encoded name': $query = 'data%252Eid='.$id.'&type=payment'; break;
                case 'double encoded value': $query = 'data.id=%2531&type=payment'; break;
                case 'bad percent': $query = 'data.id=%GG&type=payment'; break;
                case 'empty id': $query = 'data.id=&type=payment'; break;
                case 'missing topic': $body = json_encode(['data' => ['id' => $id]]); break;
                case 'unknown topic': $body = json_encode(['type' => 'merchant_order', 'data' => ['id' => $id]]); break;
                case 'contradictory topic': $query = 'type=merchant_order'; break;
                case 'topic array': $body = json_encode(['type' => ['payment'], 'data' => ['id' => $id]]); break;
                case 'ipn': $body = null; $query = 'topic=payment&id='.$id; break;
                case 'body id array': $body = json_encode(['type' => 'payment', 'data' => ['id' => [$id]]]); break;
                case 'body id null': $body = json_encode(['type' => 'payment', 'data' => ['id' => null]]); break;
                case 'body id boolean': $body = json_encode(['type' => 'payment', 'data' => ['id' => true]]); break;
                case 'body id empty': $body = json_encode(['type' => 'payment', 'data' => ['id' => '']]); break;
                case 'malformed json': $body = '{'; break;
                case 'json duplicate': $body = '{"type":"payment","type":"payment","data":{"id":"'.$id.'"}}'; break;
                case 'json escaped duplicate': $body = '{"type":"payment","\u0074ype":"payment","data":{"id":"'.$id.'"}}'; break;
                case 'nested duplicate': $body = '{"type":"payment","data":{"id":"'.$id.'","id":"'.$id.'"}}'; break;
            }
            $writes = new \ArrayObject;
            DB::listen(static function ($query) use ($writes) {
                if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                    $writes->append(true);
                }
            });
            $response = $this->deliver($query, $body, $this->headers($id));
            $this->assertTrue($response->status() === 422, 'Invalid envelope must be rejected.');
            $this->assertSame(['status' => 'invalid'], $response->json());
            $this->assertCount(0, $transport->calls);
            $this->assertCount(0, $writes);
            $this->assertDatabaseCount('payment_webhook_events', 0);
            $this->assertDatabaseCount('payment_transactions', 0);
        });
    }

    public static function invalidEnvelopes(): array
    {
        $cases = ['contradictory ids', 'literal duplicate', 'encoded duplicate', 'encoded topic duplicate', 'leading empty segment', 'middle empty segment', 'trailing empty segment', 'missing equals', 'brackets', 'array', 'underscore alias', 'space alias', 'double encoded name', 'double encoded value', 'bad percent', 'empty id', 'missing topic', 'unknown topic', 'contradictory topic', 'topic array', 'ipn', 'body id array', 'body id null', 'body id boolean', 'body id empty', 'malformed json', 'json duplicate', 'json escaped duplicate', 'nested duplicate'];
        return array_combine($cases, array_map(static fn ($case) => [$case], $cases));
    }

    /** @dataProvider invalidAuthentication */
    public function test_invalid_authentication_is_private_and_has_no_side_effects(string $case): void
    {
        $this->withRemoteLink(function ($transport) use ($case) {
            $id = (string) $transport->paymentId;
            $headers = $this->headers($id);
            switch ($case) {
                case 'wrong signature': $headers['x-signature'] = 'ts='.time().',v1='.str_repeat('0', 64); break;
                case 'wrong signed id': $headers = $this->headers((string) ($transport->paymentId + 1)); break;
                case 'missing signature': unset($headers['x-signature']); break;
                case 'missing request': unset($headers['x-request-id']); break;
                case 'bad request': $headers['x-request-id'] = 'invalid;request'; break;
                case 'bad ts': $headers['x-signature'] = 'ts=invalid,v1='.str_repeat('0', 64); break;
                case 'duplicate ts': $headers['x-signature'] .= ',ts='.time(); break;
                case 'missing ts': $headers['x-signature'] = 'v1='.str_repeat('0', 64); break;
                case 'old ts': $ts = (string) (time() - 1000); $headers['x-signature'] = 'ts='.$ts.',v1='.hash_hmac('sha256', 'id:'.$id.';request-id:'.$headers['x-request-id'].';ts:'.$ts.';', $this->secret); break;
            }
            $messages = new \ArrayObject;
            Log::listen(static function ($event) use ($messages) { $messages->append([$event->message, $event->context]); });
            $response = $this->deliver('', $this->payload($id), $headers);
            $this->assertTrue($response->status() === 401, 'Invalid authentication must be rejected.');
            $this->assertSame(['status' => 'unauthorized'], $response->json());
            $this->assertTrue($messages->getArrayCopy() === [['Mercado Pago webhook signature rejected', []]], 'Authentication logs must contain no request data.');
            $this->assertCount(0, $transport->calls);
            $this->assertDatabaseCount('payment_webhook_events', 0);
            $this->assertDatabaseCount('payment_transactions', 0);
        });
    }

    public static function invalidAuthentication(): array
    {
        $cases = ['wrong signature', 'wrong signed id', 'missing signature', 'missing request', 'bad request', 'bad ts', 'duplicate ts', 'missing ts', 'old ts'];
        return array_combine($cases, array_map(static fn ($case) => [$case], $cases));
    }

    public function test_missing_secret_is_technical_failure_without_remote_calls_or_events(): void
    {
        $this->withRemoteLink(function ($transport) {
            $id = (string) $transport->paymentId;
            $headers = $this->headers($id);
            config(['payment.mercadopago.webhook_secret' => null, 'app.debug' => true]);
            $response = $this->deliver('', $this->payload($id), $headers);
            $this->assertTrue($response->status() === 500, 'Missing secret must remain a technical failure.');
            $this->assertSame(['status' => 'error'], $response->json());
            $this->assertCount(0, $transport->calls);
            $this->assertDatabaseCount('payment_webhook_events', 0);
        });
    }

    public function test_processed_approved_event_is_idempotent_including_inventory(): void
    {
        $this->withRemoteLink(function ($transport, $order) {
            $inventory = app(\App\Services\InventoryService::class);
            $product = \App\Models\Product::create(['name' => 'Synthetic item', 'slug' => 'synthetic-'.bin2hex(random_bytes(16)), 'sku' => 'SYN-'.bin2hex(random_bytes(8)), 'price' => '10.25', 'is_active' => true]);
            $warehouse = $inventory->defaultWarehouse();
            $inventory->initializeProduct($product, 5, $warehouse);
            $item = $order->items()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'price' => '10.25', 'subtotal' => '10.25']);
            $inventory->reserveForOrder($item, now()->addMinutes(30));
            $id = (string) $transport->paymentId;
            $body = $this->payload($id);
            $first = $this->deliver('', $body, $this->headers($id));
            $this->assertTrue($first->status() === 200, 'First approved event must succeed.');
            $tables = ['payment_transactions', 'inventory_reservations', 'inventory_movements', 'warehouse_inventories', 'order_fulfillment_histories'];
            // Capture all affected financial/stock/history rows without printing them.
            $snapshot = fn () => hash('sha256', json_encode(array_map(static fn ($table) => DB::table($table)->orderBy('id')->get()->all(), $tables)));
            $before = $snapshot();
            $second = $this->deliver('', $body, $this->headers($id));
            $this->assertTrue($second->status() === 200, 'Repeated event must succeed coherently.');
            $this->assertTrue(hash_equals($before, $snapshot()), 'Repetition must preserve financial, inventory and history rows.');
            $this->assertSame(2, count($transport->calls));
            $this->assertDatabaseCount('payment_transactions', 1);
            $this->assertSame('approved', $order->refresh()->payment_status);
        });
    }

    /** @dataProvider ignoredReasons */
    public function test_only_categorized_missing_remote_preference_can_be_reevaluated(?string $reason, bool $recover): void
    {
        $this->withRemoteLink(function ($transport, $order) use ($reason, $recover) {
            $id = (string) $transport->paymentId;
            $notification = 'synthetic-'.bin2hex(random_bytes(16));
            PaymentWebhookEvent::create(['gateway' => 'mercadopago', 'provider_event_id' => $notification, 'provider_payment_id' => $id, 'event_type' => 'payment', 'payload_hash' => hash('sha256', 'synthetic'), 'idempotency_key' => 'mp:event:'.$notification, 'status' => PaymentWebhookEvent::IGNORED, 'failure_reason' => $reason, 'received_at' => now()]);
            $response = $this->deliver('', $this->payload($id, $notification), $this->headers($id));
            $this->assertTrue($response->status() === 200, 'Authenticated retry must have a coherent response.');
            $this->assertSame(['status' => $recover ? 'ok' : 'ignored'], $response->json());
            $this->assertSame($recover ? 2 : 0, count($transport->calls));
            $event = PaymentWebhookEvent::sole();
            $this->assertSame($recover ? PaymentWebhookEvent::PROCESSED : PaymentWebhookEvent::IGNORED, $event->status);
            $this->assertSame($recover ? null : ($reason ?? PaymentWebhookEvent::LEGACY_IGNORE_REASON_UNKNOWN), $event->failure_reason);
            $this->assertSame($recover ? 'approved' : 'pending', $order->refresh()->payment_status);
        });
    }

    public static function ignoredReasons(): array
    {
        return [[PaymentWebhookEvent::REMOTE_PREFERENCE_UNAVAILABLE, true], [PaymentWebhookEvent::LOCAL_LINK_NOT_FOUND, false], [null, false]];
    }

    /** @dataProvider incompatibleLinks */
    public function test_incoherent_remote_or_local_link_is_never_processed(string $case): void
    {
        $this->withRemoteLink(function ($transport, $order) use ($case) {
            switch ($case) {
                case 'preference': $transport->merchant['preference_id'] = 'synthetic-'.bin2hex(random_bytes(16)); break;
                case 'reference': $transport->merchant['external_reference'] = 'synthetic-'.bin2hex(random_bytes(16)); break;
                case 'amount': $transport->payment['transaction_amount'] = 9; $transport->merchant['total_amount'] = 9; $transport->merchant['payments'][0]['transaction_amount'] = 9; break;
                case 'currency': $transport->payment['currency_id'] = 'USD'; $transport->merchant['payments'][0]['currency_id'] = 'USD'; break;
                case 'current preference': $order->update(['current_payment_preference_id' => null]); break;
            }
            $id = (string) $transport->paymentId;
            $response = $this->deliver('', $this->payload($id), $this->headers($id));
            $this->assertTrue($response->status() === 422, 'Incoherent payment linkage must fail.');
            $this->assertSame(['status' => 'invalid'], $response->json());
            $this->assertDatabaseCount('payment_transactions', 0);
            $this->assertSame('pending', $order->refresh()->payment_status);
            $this->assertSame(PaymentWebhookEvent::FAILED, PaymentWebhookEvent::sole()->status);
            $this->assertNotNull(PaymentWebhookEvent::sole()->failure_reason);
        });
    }

    public static function incompatibleLinks(): array
    {
        return [['preference'], ['reference'], ['amount'], ['currency'], ['current preference']];
    }

    /** @dataProvider technicalFailures */
    public function test_technical_failures_propagate_without_becoming_processed_events(string $kind): void
    {
        $this->withRemoteLink(function ($transport, $order) use ($kind) {
            $notification = null;
            $sqlite = DB::connection()->getDriverName() === 'sqlite';
            $blocker = null;
            if ($kind === 'sql') {
                $notification = 'synthetic-'.bin2hex(random_bytes(16));
                if ($sqlite) {
                    DB::unprepared("CREATE TEMP TRIGGER synthetic_webhook_failure BEFORE INSERT ON payment_webhook_events BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
                } else {
                    $blocker = PaymentWebhookEvent::create([
                        'gateway' => 'synthetic_fixture',
                        'idempotency_key' => 'synthetic-blocker-'.bin2hex(random_bytes(16)),
                        'payload_hash' => hash('sha256', 'synthetic blocker'),
                        'status' => PaymentWebhookEvent::RECEIVED, 'received_at' => now(),
                    ]);
                    $this->assertTrue($blocker->idempotency_key !== 'mp:event:'.$notification, 'The conflict must not match the event locator.');
                }
                $beforeOrder = $order->refresh()->getRawOriginal();
                $eventSnapshot = static fn () => hash('sha256', json_encode(DB::table('payment_webhook_events')->orderBy('id')->get()->all()));
                $beforeEvents = $eventSnapshot();
                $beforeLevel = DB::transactionLevel();
                $canary = new \ArrayObject(['attempts' => 0, 'inside_transaction' => false]);
                PaymentWebhookEvent::creating(static function (PaymentWebhookEvent $candidate) use ($transport, $order, $notification, $blocker, $beforeLevel, $canary) {
                    if ($candidate->gateway !== 'mercadopago'
                        || $candidate->provider_event_id !== $notification
                        || $candidate->provider_payment_id !== (string) $transport->paymentId
                        || $candidate->idempotency_key !== 'mp:event:'.$notification) {
                        return;
                    }
                    $canary['inside_transaction'] = DB::transactionLevel() > $beforeLevel;
                    DB::table('orders')->where('id', $order->id)->update([
                        'shipping_info' => json_encode(['synthetic_rollback_marker' => true]),
                    ]);
                    $canary['attempts']++;
                    if ($blocker !== null) {
                        // Test-only physical PK collision, independent of the
                        // unchanged idempotency locator; no mocked SQL error.
                        $candidate->id = $blocker->id;
                    }
                });
                $failure = null;
            } else {
                $failure = $kind === 'provider'
                    ? new \MercadoPago\Exceptions\MPApiException('Synthetic provider failure', new \MercadoPago\Net\MPResponse(500, ['message' => 'synthetic']))
                    : new \RuntimeException('Synthetic transport failure');
                $transport->paymentFailure = $failure;
            }
            $this->withoutExceptionHandling();
            $caught = null;
            try {
                $id = (string) $transport->paymentId;
                $this->deliver('', $this->payload($id, $notification), $this->headers($id));
            } catch (\Throwable $exception) {
                $caught = $exception;
            } finally {
                if ($kind === 'sql' && $sqlite) {
                    DB::unprepared('DROP TRIGGER synthetic_webhook_failure');
                }
            }
            if ($kind === 'sql') {
                $this->assertTrue($caught instanceof \Illuminate\Database\QueryException, 'SQL failure must propagate.');
                $this->assertTrue(($caught->errorInfo[0] ?? null) === '23000'
                    && (int) ($caught->errorInfo[1] ?? 0) === ($sqlite ? 19 : 1062), 'The expected physical integrity constraint must fail.');
                $this->assertTrue($canary['inside_transaction'] && $canary['attempts'] === 1, 'The canary must execute once inside event registration.');
                $this->assertCount(0, $transport->calls);
                $this->assertTrue($beforeOrder === $order->refresh()->getRawOriginal(), 'Event registration failure must roll back the canary.');
                $this->assertTrue(hash_equals($beforeEvents, $eventSnapshot()), 'All pre-existing events must remain intact.');
                $attempt = PaymentWebhookEvent::where('provider_event_id', $notification)->where('provider_payment_id', (string) $transport->paymentId);
                $this->assertTrue((clone $attempt)->count() === 0, 'No event from the failed attempt may remain.');
                $this->assertTrue((clone $attempt)->whereIn('status', [PaymentWebhookEvent::PROCESSED, PaymentWebhookEvent::IGNORED])->count() === 0, 'A failed attempt must never become processed or ignored.');
                $this->assertTrue(DB::transactionLevel() === $beforeLevel, 'Event registration must restore the original transaction depth.');
                $this->assertTrue((int) DB::selectOne('SELECT 1 AS connection_usable')->connection_usable === 1, 'The connection must remain usable after rollback.');
                if ($blocker !== null) {
                    $deleted = PaymentWebhookEvent::whereKey($blocker->id)->where('gateway', 'synthetic_fixture')->where('idempotency_key', $blocker->idempotency_key)->delete();
                    $this->assertTrue($deleted === 1, 'Cleanup must remove only the owned conflict fixture.');
                }
                $this->assertDatabaseCount('payment_webhook_events', 0);
            } else {
                $this->assertTrue($caught === $failure, 'The original technical failure must propagate.');
                $this->assertSame(PaymentWebhookEvent::FAILED, PaymentWebhookEvent::sole()->status);
                $this->assertSame('provider_or_processing_error', PaymentWebhookEvent::sole()->failure_reason);
            }
            $this->assertDatabaseCount('payment_transactions', 0);
        });
    }

    public static function technicalFailures(): array
    {
        return [['provider'], ['transport'], ['sql']];
    }

    public function test_distinct_authenticated_events_finalize_the_same_pending_remote_payment(): void
    {
        $this->withRemoteLink(function ($transport, $order) {
            $id = (string) $transport->paymentId;
            $transport->payment['status'] = 'pending';
            $pending = $this->deliver('', $this->payload($id), $this->headers($id));
            $this->assertTrue($pending->status() === 200, 'Pending notification must succeed.');
            $entry = PaymentTransaction::sole();
            $entryId = $entry->id;
            $this->assertSame(PaymentTransaction::PENDING, $entry->status);
            $transport->payment['status'] = 'approved';
            $approvedBody = $this->payload($id);
            $approved = $this->deliver('', $approvedBody, $this->headers($id));
            $this->assertTrue($approved->status() === 200, 'Approval notification must succeed.');
            $this->assertSame('approved', $order->refresh()->payment_status);
            $this->assertSame(PaymentTransaction::APPROVED, $entry->refresh()->status);
            $this->assertSame($entryId, PaymentTransaction::sole()->id);
            $this->assertDatabaseCount('payment_transactions', 1);
            $repeated = $this->deliver('', $approvedBody, $this->headers($id));
            $this->assertTrue($repeated->status() === 200, 'Duplicate approval must remain idempotent.');
            $this->assertSame(4, count($transport->calls));
            $this->assertSame(2, PaymentWebhookEvent::where('status', PaymentWebhookEvent::PROCESSED)->count());
        });
    }

    public function test_query_only_notifications_allow_later_approval_without_duplicate_financial_writes(): void
    {
        $this->withRemoteLink(function ($transport, $order) {
            $id = (string) $transport->paymentId;
            $query = 'data.id='.$id.'&type=payment';
            $transport->payment['status'] = 'pending';
            $pendingHeaders = $this->headers($id);
            $first = $this->deliver($query, null, $pendingHeaders);
            $this->assertTrue($first->status() === 200, 'Pending query notification must succeed.');
            $repeatedPending = $this->deliver($query, null, $pendingHeaders);
            $this->assertTrue($repeatedPending->status() === 200, 'Same authenticated request must be idempotent.');
            $this->assertSame(2, count($transport->calls));
            $entry = PaymentTransaction::sole();
            $entryId = $entry->id;
            $this->assertSame(PaymentTransaction::PENDING, $entry->status);

            $transport->payment['status'] = 'approved';
            $approvedHeaders = $this->headers($id);
            $approved = $this->deliver($query, null, $approvedHeaders);
            $this->assertTrue($approved->status() === 200, 'New authenticated query notification must revalidate remote approval.');
            $this->assertSame('approved', $order->refresh()->payment_status);
            $this->assertSame(PaymentTransaction::APPROVED, $entry->refresh()->status);
            $this->assertSame($entryId, PaymentTransaction::sole()->id);
            $repeatedApproved = $this->deliver($query, null, $approvedHeaders);
            $this->assertTrue($repeatedApproved->status() === 200, 'Approved query notification must remain idempotent.');
            $this->assertSame(4, count($transport->calls));
            $this->assertDatabaseCount('payment_transactions', 1);
            $this->assertDatabaseCount('payment_webhook_events', 2);
        });
    }
}
