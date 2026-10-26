<?php

namespace Tests\Feature;

use App\Exceptions\PaymentLinkEvidenceException as LinkFailure;
use App\Models\PaymentPreference;
use App\Services\PaymentPreferenceService;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\SyntheticMercadoPagoLink;
use Tests\TestCase;
use Tests\Support\PaymentLockSqlObserver;

class PaymentPreferenceLockOrderTest extends TestCase
{
    use RefreshDatabase;
    use SyntheticMercadoPagoLink;

    /** @dataProvider transitions */
    public function test_link_changed_between_locator_and_lock_rejects_without_subsequent_writes(string $transition): void
    {
        $this->withRemoteLink(function ($transport, $order, $preference) use ($transition) {
            if ($transition === 'activate') {
                $order = $order->replicate(['current_payment_preference_id']);
                $order->save();
                $transport->preferenceId = 'synthetic-'.bin2hex(random_bytes(16));
            } else {
                $preference->update(['state' => PaymentPreference::CREATING]);
            }
            $beginnings = 0;
            $observing = false;
            $writes = new \ArrayObject;
            $reads = new \ArrayObject;
            Event::listen(TransactionBeginning::class, function () use (&$beginnings, &$observing, $transition, $order) {
                $beginnings++;
                $target = $transition === 'activate' ? 2 : 1;
                if ($beginnings !== $target) {
                    return;
                }
                // Transaction has begun, but neither financial row lock has
                // been requested. Simulate an intervening locator
                // change deterministically, entirely in the synthetic fixture.
                $order->update(['current_payment_preference_id' => null]);
                $observing = true;
            });
            DB::listen(static function ($query) use (&$observing, $writes, $reads) {
                if (! $observing) {
                    return;
                }
                if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                    $writes->append(true);
                }
                $intent = PaymentLockSqlObserver::builderRequestsUpdateLock(debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS));
                foreach (PaymentLockSqlObserver::tables($query->sql, $query->connection->getDriverName(), $intent) as $table) {
                    $reads->append($table);
                }
            });
            $rejected = false;
            try {
                $service = app(PaymentPreferenceService::class);
                match ($transition) {
                    'activate' => $service->createFor($order, [
                        'items' => [['name' => 'Synthetic item', 'quantity' => 1, 'price' => 10.25]],
                        'customer' => ['name' => 'Synthetic buyer', 'email' => 'buyer@example.invalid'],
                    ], 'mercadopago'),
                    'fail' => $service->fail($preference, 'synthetic_rejection', 'Synthetic rejection'),
                    'orphan' => $service->orphan($preference, 'synthetic_uncertainty'),
                };
            } catch (ValidationException $exception) {
                $rejected = ($exception->errors()['payment'] ?? []) === [LinkFailure::STALE_LOCAL_LINK];
            }
            $this->assertTrue($rejected, 'Changed link must be rejected under lock.');
            $this->assertTrue($observing, 'Interleaving must execute before the locked validation.');
            $this->assertCount(0, $writes, 'No writes may follow the intervening change.');
            $this->assertSame(['orders', 'payment_preferences'], array_slice($reads->getArrayCopy(), 0, 2));
            $this->assertDatabaseCount('payment_transactions', 0);
        });
    }

    public static function transitions(): array
    {
        return [['activate'], ['fail'], ['orphan']];
    }

    /** @dataProvider terminalTransitions */
    public function test_closing_creation_is_idempotent(string $transition, string $state): void
    {
        $this->withRemoteLink(function ($transport, $order, $preference) use ($transition, $state) {
            $preference->update(['state' => PaymentPreference::CREATING]);
            $service = app(PaymentPreferenceService::class);
            $apply = fn () => $transition === 'fail'
                ? $service->fail($preference, 'synthetic_rejection', 'Synthetic rejection')
                : $service->orphan($preference, 'synthetic_uncertainty');
            $first = $apply();
            $this->assertSame($state, $first->state);
            $before = $first->getRawOriginal();
            $this->assertTrue($before === $apply()->getRawOriginal(), 'Repeated transition must not rewrite the preference.');
        });
    }

    public static function terminalTransitions(): array
    {
        return [['fail', PaymentPreference::FAILED], ['orphan', PaymentPreference::ORPHANED]];
    }

    public function test_preference_creation_sql_error_propagates_before_remote_request(): void
    {
        $this->withRemoteLink(function ($transport, $order, $preference) {
            $other = $order->replicate(['current_payment_preference_id']);
            $other->save();
            $sqlite = DB::connection()->getDriverName() === 'sqlite';
            if ($sqlite) {
                DB::unprepared("CREATE TEMP TRIGGER synthetic_preference_failure BEFORE INSERT ON payment_preferences BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
            }
            $beforeOther = $other->refresh()->getRawOriginal();
            $beforeOrder = $order->refresh()->getRawOriginal();
            $snapshot = static fn () => hash('sha256', json_encode(DB::table('payment_preferences')->orderBy('id')->get()->all()));
            $beforePreferences = $snapshot();
            $beforeCount = PaymentPreference::count();
            $beforeLevel = DB::transactionLevel();
            $canary = new \ArrayObject(['attempts' => 0, 'inside_transaction' => false]);
            PaymentPreference::creating(static function (PaymentPreference $candidate) use ($other, $preference, $sqlite, $beforeLevel, $canary) {
                if ((int) $candidate->order_id !== (int) $other->id || $candidate->gateway !== 'mercadopago'
                    || $candidate->state !== PaymentPreference::CREATING) {
                    return;
                }
                $canary['inside_transaction'] = DB::transactionLevel() > $beforeLevel;
                DB::table('orders')->where('id', $other->id)->update([
                    'shipping_info' => json_encode(['synthetic_rollback_marker' => true]),
                ]);
                $canary['attempts']++;
                if (! $sqlite) {
                    // Test-only fault injection on this unsaved synthetic row:
                    // the actual INSERT must hit the existing UNIQUE index.
                    $candidate->idempotency_key = $preference->idempotency_key;
                }
            });
            $caught = false;
            $physicalConstraint = false;
            try {
                app(PaymentPreferenceService::class)->createFor($other, [], 'mercadopago');
            } catch (\Illuminate\Database\QueryException $exception) {
                $caught = true;
                $physicalConstraint = ($exception->errorInfo[0] ?? null) === '23000'
                    && (int) ($exception->errorInfo[1] ?? 0) === ($sqlite ? 19 : 1062);
            } finally {
                if ($sqlite) {
                    DB::unprepared('DROP TRIGGER synthetic_preference_failure');
                }
            }
            $this->assertTrue($caught, 'Preference creation SQL failure must propagate.');
            $this->assertTrue($physicalConstraint, 'The expected physical integrity constraint must fail.');
            $this->assertTrue($canary['inside_transaction'] && $canary['attempts'] === 1, 'The canary must execute once inside the production transaction.');
            $this->assertCount(0, $transport->calls);
            $this->assertNull($other->refresh()->current_payment_preference_id);
            $this->assertTrue($beforeOther === $other->getRawOriginal(), 'Rollback must preserve the destination order.');
            $this->assertTrue($beforeOrder === $order->refresh()->getRawOriginal(), 'Rollback must preserve the original order.');
            $this->assertTrue(hash_equals($beforePreferences, $snapshot()), 'Rollback must preserve every pre-existing preference.');
            $this->assertTrue(PaymentPreference::count() === $beforeCount, 'Failed creation must insert no preferences.');
            $this->assertTrue(DB::transactionLevel() === $beforeLevel, 'Failed creation must restore the original transaction depth.');
            $this->assertTrue((int) DB::selectOne('SELECT 1 AS connection_usable')->connection_usable === 1, 'The connection must remain usable after rollback.');
        });
    }
}
