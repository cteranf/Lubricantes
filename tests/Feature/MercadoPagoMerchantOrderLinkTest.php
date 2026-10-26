<?php

namespace Tests\Feature;

use App\Exceptions\PaymentLinkEvidenceException as LinkFailure;
use App\Services\PaymentGateway\MercadoPagoGateway;
use App\Models\PaymentPreference;
use App\Services\PaymentPreferenceService;
use App\Services\PaymentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MercadoPago\Exceptions\MPApiException;
use Tests\Feature\Concerns\SyntheticMercadoPagoLink;
use Tests\TestCase;

class MercadoPagoMerchantOrderLinkTest extends TestCase
{
    use RefreshDatabase;
    use SyntheticMercadoPagoLink;

    /** @dataProvider uninitializedResources */
    public function test_uninitialized_sdk_property_is_incomplete_evidence(string $resource): void
    {
        $this->withRemoteLink(function ($transport) use ($resource) {
            if ($resource === 'payment') {
                unset($transport->payment['status']);
                $dto = (new \MercadoPago\Client\Payment\PaymentClient)->get((string) $transport->paymentId);
                $this->assertFalse(isset($dto->status));
            } else {
                unset($transport->merchant['preference_id']);
                $dto = (new \MercadoPago\Client\MerchantOrder\MerchantOrderClient)->get($transport->merchant['id']);
                $this->assertFalse(isset($dto->preference_id));
            }
            $this->assertRejectedWithoutWrites(fn () => (new MercadoPagoGateway)->verifyPayment((string) $transport->paymentId), LinkFailure::REMOTE_EVIDENCE_INCOMPLETE);
        });
    }

    public static function uninitializedResources(): array
    {
        return [['payment'], ['merchant']];
    }

    /** @dataProvider providerFailures */
    public function test_provider_and_transport_failures_propagate_unchanged(string $resource, string $kind): void
    {
        $this->withRemoteLink(function ($transport) use ($resource, $kind) {
            $failure = match ($kind) {
                'error' => new \Error('Synthetic unexpected error'),
                'error exception' => new \ErrorException('Synthetic unexpected error exception'),
                'transport' => new \RuntimeException('Synthetic transport error'),
                default => new MPApiException('Synthetic provider error', new \MercadoPago\Net\MPResponse((int) $kind, ['message' => 'synthetic'])),
            };
            $property = $resource === 'payment' ? 'paymentFailure' : 'merchantFailure';
            $transport->$property = $failure;
            $this->assertFailurePropagates($transport, get_class($failure), $failure);
        });
    }

    public static function providerFailures(): array
    {
        $cases = [];
        foreach (['payment', 'merchant'] as $resource) {
            foreach (['401', '429', '500', 'error', 'error exception', 'transport'] as $kind) {
                $cases[$resource.' '.$kind] = [$resource, $kind];
            }
        }
        $cases['payment 404'] = ['payment', '404'];
        return $cases;
    }

    /** @dataProvider sdkFailures */
    public function test_sdk_deserialization_defects_propagate(string $case, string $expected): void
    {
        $this->withRemoteLink(function ($transport) use ($case, $expected) {
            switch ($case) {
                case 'payment identity unexpected type': $transport->payment['id'] = []; break;
                case 'order unexpected type': $transport->payment['order'] = 'invalid'; break;
                case 'payments unexpected type': $transport->merchant['payments'] = 'invalid'; break;
                case 'amount unexpected type': $transport->payment['transaction_amount'] = []; break;
                case 'status unexpected type': $transport->payment['status'] = []; break;
                case 'member unexpected type': $transport->merchant['payments'] = ['invalid']; break;
                case 'empty response': $transport->merchant = []; break;
            }
            $this->assertFailurePropagates($transport, $expected, null, $case === 'member unexpected type');
        });
    }

    public static function sdkFailures(): array
    {
        $cases = [];
        foreach (['payment identity unexpected type', 'order unexpected type', 'payments unexpected type', 'amount unexpected type', 'status unexpected type', 'empty response'] as $case) {
            $cases[$case] = [$case, \TypeError::class];
        }
        $cases['member unexpected type'] = ['member unexpected type', \ErrorException::class];
        return $cases;
    }

    private function assertFailurePropagates(object $transport, string $expected, ?\Throwable $original = null, bool $fromSerializer = false): void
    {
        $writes = new \ArrayObject;
        DB::listen(static function ($query) use ($writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query->sql)) {
                $writes->append(true);
            }
        });
        $failure = null;
        try {
            (new MercadoPagoGateway)->verifyPayment((string) $transport->paymentId);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        $this->assertTrue($failure instanceof $expected, 'Original failure type must propagate.');
        if ($original) {
            $this->assertTrue($failure === $original, 'The exact original exception must propagate.');
        }
        if ($fromSerializer) {
            $this->assertTrue(count(array_filter($failure->getTrace(), static fn ($frame) => ($frame['class'] ?? null) === \MercadoPago\Serialization\Serializer::class)) > 0, 'Failure must originate in real SDK serialization.');
        }
        $this->assertCount(0, $writes, 'Failures must execute no writes.');
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_valid_link_is_repeatable_and_read_only(): void
    {
        $this->withRemoteLink(function ($transport, $order, $preference) {
            $beforeOrder = $order->getRawOriginal();
            $beforePreference = $preference->getRawOriginal();
            $gateway = new MercadoPagoGateway;
            $first = $gateway->verifyPayment((string) $transport->payment['id']);
            $this->assertTrue($first === $gateway->verifyPayment((string) $transport->payment['id']), 'Repeated evidence must match.');
            $this->assertSame($transport->merchant['id'], $first['merchant_order_id']);
            $this->assertTrue($preference->provider_preference_id === $first['preference_id'], 'Preference identity must match.');
            $this->assertTrue($beforeOrder === $order->refresh()->getRawOriginal(), 'Order must remain unchanged.');
            $this->assertTrue($beforePreference === $preference->refresh()->getRawOriginal(), 'Preference must remain unchanged.');
            $this->assertDatabaseCount('payment_transactions', 0);
            $this->assertSame(4, count($transport->calls));
        });
    }

    public function test_current_preference_changed_after_localization_is_rejected_without_writes(): void
    {
        $this->withRemoteLink(function ($transport, $order, $preference) {
            $evidence = (new MercadoPagoGateway)->verifyPayment((string) $transport->paymentId);
            // Same locator as the webhook. Keep this model stale deliberately.
            $located = PaymentPreference::where('gateway', 'mercadopago')
                ->where('provider_preference_id', $evidence['preference_id'])
                ->where('external_reference', $evidence['external_reference'])->firstOrFail();

            // Deterministic intervening actor, entirely in synthetic SQLite:
            // replace the preference after localization and before process locks.
            $preference->update(['state' => PaymentPreference::SUPERSEDED, 'superseded_at' => now()]);
            $transport->preferenceId = 'synthetic-'.bin2hex(random_bytes(16));
            $replacement = app(PaymentPreferenceService::class)->createFor($order, [
                'items' => [['name' => 'Synthetic item', 'quantity' => 1, 'price' => 10.25]],
                'customer' => ['name' => 'Synthetic buyer', 'email' => 'buyer@example.invalid'],
            ], 'mercadopago');
            $this->assertSame(PaymentPreference::ACTIVE, $replacement->state);
            $this->assertNotSame($located->id, $replacement->id);
            $this->assertSame($replacement->id, $order->refresh()->current_payment_preference_id);

            $beforeOrder = $order->getRawOriginal();
            $beforePreferences = PaymentPreference::orderBy('id')->get()->map->getRawOriginal()->all();
            $queries = new \ArrayObject;
            DB::listen(static function ($query) use ($queries) {
                $queries->append($query->sql);
            });
            try {
                app(PaymentProcessingService::class)->process($located, $evidence);
                $this->fail('A preference changed after localization must be rejected under lock.');
            } catch (ValidationException $exception) {
                $this->assertSame([LinkFailure::STALE_LOCAL_LINK], $exception->errors()['payment'] ?? []);
            }
            $this->assertSame([], array_values(array_filter($queries->getArrayCopy(),
                static fn ($sql) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $sql))));
            $this->assertTrue($beforeOrder === $order->refresh()->getRawOriginal(), 'Order must remain unchanged.');
            $this->assertTrue($beforePreferences === PaymentPreference::orderBy('id')->get()->map->getRawOriginal()->all(), 'Preferences must remain unchanged.');
            $this->assertDatabaseCount('payment_transactions', 0);
            $this->assertSame('pending', $order->payment_status);
        });
    }

    /** @dataProvider incompatibleEvidence */
    public function test_incompatible_or_incomplete_evidence_is_rejected(string $case, string $category): void
    {
        $this->withRemoteLink(function ($transport, $order, $preference) use ($case, $category) {
            $located = PaymentPreference::findOrFail($preference->id);
            switch ($case) {
                case 'payment without identity': unset($transport->payment['id']); break;
                case 'payment identity mismatch': $transport->payment['id']++; break;
                case 'payment without status': unset($transport->payment['status']); break;
                case 'payment without order': unset($transport->payment['order']); break;
                case 'invalid order identity': $transport->payment['order']['id'] = -1; break;
                case 'merchant identity mismatch': $transport->merchant['id']++; break;
                case 'merchant absent': $transport->missing = true; break;
                case 'merchant without preference': unset($transport->merchant['preference_id']); break;
                case 'merchant without reference': unset($transport->merchant['external_reference']); break;
                case 'merchant without payments': unset($transport->merchant['payments']); break;
                case 'empty payments': $transport->merchant['payments'] = []; break;
                case 'payment not contained': $transport->merchant['payments'] = [['id' => random_int(2000000001, 2100000000), 'transaction_amount' => 10.25, 'currency_id' => 'PEN']]; break;
                case 'contradictory duplicates': $other = $transport->merchant['payments'][0]; $other['transaction_amount'] = 9; $transport->merchant['payments'][] = $other; break;
                case 'identical duplicates': $transport->merchant['payments'][] = $transport->merchant['payments'][0]; break;
                case 'different member identity': $transport->merchant['payments'][0]['id']++; break;
                case 'member amount mismatch': $transport->merchant['payments'][0]['transaction_amount'] = 9; break;
                case 'member currency mismatch': $transport->merchant['payments'][0]['currency_id'] = 'USD'; break;
                case 'remote preference mismatch': $transport->merchant['preference_id'] = 'synthetic-'.bin2hex(random_bytes(16)); break;
                case 'local gateway mismatch': $preference->update(['gateway' => 'mock']); $located->refresh(); break;
                case 'remote references mismatch': $transport->merchant['external_reference'] = 'synthetic-'.bin2hex(random_bytes(16)); break;
                case 'local reference mismatch': $transport->merchant['external_reference'] = 'synthetic-'.bin2hex(random_bytes(16)); $transport->payment['external_reference'] = $transport->merchant['external_reference']; break;
                case 'local amount mismatch': $transport->payment['transaction_amount'] = 9; $transport->merchant['payments'][0]['transaction_amount'] = 9; $transport->merchant['total_amount'] = 9; break;
                case 'configured currency mismatch': $transport->payment['currency_id'] = 'USD'; $transport->merchant['payments'][0]['currency_id'] = 'USD'; break;
                case 'inactive preference': $preference->update(['state' => PaymentPreference::SUPERSEDED]); $located->refresh(); break;
                case 'preference switched after localization': $order->update(['current_payment_preference_id' => null]); break;
                case 'preference reassigned after localization':
                    $other = $order->replicate(['current_payment_preference_id']); $other->save();
                    $preference->update(['order_id' => $other->id]); break;
                case 'order replaced after localization':
                    $other = $order->replicate(['current_payment_preference_id']); $other->save();
                    // Move the synthetic preference coherently to another order;
                    // retain the original locator and both existing order IDs.
                    // All interleaving writes precede the write observer below.
                    $order->update(['current_payment_preference_id' => null]);
                    $preference->update(['order_id' => $other->id]);
                    $other->update(['current_payment_preference_id' => $preference->id]);
                    $this->assertTrue((int) $located->order_id === (int) $order->id, 'Locator must retain the original order.');
                    $this->assertTrue((int) $preference->fresh()->order_id === (int) $other->id, 'Preference must belong to the replacement order.');
                    $this->assertTrue((int) $other->fresh()->current_payment_preference_id === (int) $preference->id, 'Replacement order must hold the current preference.');
                    break;
                case 'payment missing amount': unset($transport->payment['transaction_amount']); break;
                case 'member missing amount': unset($transport->merchant['payments'][0]['transaction_amount']); break;
                case 'member missing currency': unset($transport->merchant['payments'][0]['currency_id']); break;
                case 'merchant missing total': unset($transport->merchant['total_amount']); break;
                case 'payment missing reference': unset($transport->payment['external_reference']); break;
                case 'payment identity unexpected type': $transport->payment['id'] = []; break;
                case 'order unexpected type': $transport->payment['order'] = 'invalid'; break;
                case 'payments unexpected type': $transport->merchant['payments'] = 'invalid'; break;
                case 'amount unexpected type': $transport->payment['transaction_amount'] = []; break;
                case 'status unexpected type': $transport->payment['status'] = []; break;
                case 'member unexpected type': $transport->merchant['payments'] = ['invalid']; break;
                case 'empty response': $transport->merchant = []; break;
                case 'currency lowercase': $transport->payment['currency_id'] = 'pen'; $transport->merchant['payments'][0]['currency_id'] = 'pen'; break;
                case 'currency whitespace': $transport->payment['currency_id'] = 'PEN '; $transport->merchant['payments'][0]['currency_id'] = 'PEN '; break;
                case 'amount precision': $transport->payment['transaction_amount'] = 10.251; break;
            }
            $this->assertRejectedWithoutWrites(function () use ($transport, $located) {
                $evidence = (new MercadoPagoGateway)->verifyPayment((string) $transport->paymentId);
                app(PaymentProcessingService::class)->process($located, $evidence);
            }, $category);
        });
    }

    private function assertRejectedWithoutWrites(\Closure $attempt, string $category): void
    {
        $writes = new \ArrayObject;
        DB::listen(static function ($query) use ($writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query->sql)) {
                $writes->append(true);
            }
        });
        $level = DB::transactionLevel();
        try {
            $attempt();
            $this->fail('Incompatible linkage must be rejected.');
        } catch (LinkFailure $exception) {
            $this->assertSame($category, $exception->category);
            if ($category === LinkFailure::REMOTE_RESOURCE_ABSENT) {
                $this->assertInstanceOf(MPApiException::class, $exception->getPrevious());
                $this->assertSame(404, $exception->getPrevious()->getStatusCode());
            }
        } catch (ValidationException $exception) {
            $this->assertSame([$category], $exception->errors()['payment'] ?? []);
        }
        $this->assertCount(0, $writes, 'Rejected linkage must execute no writes of any kind.');
        $this->assertSame($level, DB::transactionLevel());
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public static function incompatibleEvidence(): array
    {
        $incomplete = ['payment without identity', 'payment without status', 'payment without order', 'invalid order identity', 'merchant without preference', 'merchant without reference', 'merchant without payments', 'payment missing amount', 'member missing amount', 'member missing currency', 'merchant missing total', 'payment missing reference', 'currency lowercase', 'currency whitespace', 'amount precision'];
        $contradictory = ['payment identity mismatch', 'merchant identity mismatch', 'empty payments', 'payment not contained', 'contradictory duplicates', 'identical duplicates', 'different member identity', 'member amount mismatch', 'member currency mismatch', 'remote preference mismatch', 'local gateway mismatch', 'remote references mismatch', 'local reference mismatch', 'local amount mismatch', 'configured currency mismatch'];
        $stale = ['inactive preference', 'preference switched after localization', 'preference reassigned after localization', 'order replaced after localization'];
        $cases = [];
        foreach ([LinkFailure::REMOTE_EVIDENCE_INCOMPLETE => $incomplete, LinkFailure::CONTRADICTORY_EVIDENCE => $contradictory, LinkFailure::STALE_LOCAL_LINK => $stale, LinkFailure::REMOTE_RESOURCE_ABSENT => ['merchant absent']] as $category => $names) {
            foreach ($names as $name) {
                $cases[$name] = [$name, $category];
            }
        }
        return $cases;
    }
}
