<?php

namespace Tests\Feature;

use App\Exceptions\PaymentLinkEvidenceException as LinkFailure;
use App\Models\PaymentPreference;
use App\Services\PaymentGateway\MercadoPagoGateway;
use App\Services\PaymentPreferenceService;
use App\Services\PaymentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;
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
                case 'payment identity unexpected type': $transport->payment['id'] = [];
                    break;
                case 'order unexpected type': $transport->payment['order'] = 'invalid';
                    break;
                case 'payments unexpected type': $transport->merchant['payments'] = 'invalid';
                    break;
                case 'amount unexpected type': $transport->payment['transaction_amount'] = [];
                    break;
                case 'status unexpected type': $transport->payment['status'] = [];
                    break;
                case 'member unexpected type': $transport->merchant['payments'] = ['invalid'];
                    break;
                case 'empty response': $transport->merchant = [];
                    break;
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
            $this->assertSame((string) $transport->payment['id'], $first['id']);
            $this->assertSame((string) $transport->merchant['id'], $first['merchant_order_id']);
            $this->assertTrue($preference->provider_preference_id === $first['preference_id'], 'Preference identity must match.');
            $this->assertTrue($beforeOrder === $order->refresh()->getRawOriginal(), 'Order must remain unchanged.');
            $this->assertTrue($beforePreference === $preference->refresh()->getRawOriginal(), 'Preference must remain unchanged.');
            $this->assertDatabaseCount('payment_transactions', 0);
            $this->assertSame(4, count($transport->calls));
        });
    }

    /** @dataProvider equivalentIdentityRepresentations */
    public function test_equivalent_string_and_integer_remote_order_identities_are_accepted(string $representation): void
    {
        $this->withRemoteLink(function ($transport) use ($representation) {
            // Both responses pass through the real SDK clients and simulated
            // transport; only the JSON representation of remote identities varies.
            if ($representation === 'payment order string') {
                $transport->payment['order']['id'] = (string) $transport->merchant['id'];
            } else {
                $transport->merchant['id'] = (string) $transport->payment['order']['id'];
                $transport->payment['id'] = (string) $transport->payment['id'];
                $transport->merchant['payments'][0]['id'] = (string) $transport->merchant['payments'][0]['id'];
            }

            $evidence = (new MercadoPagoGateway)->verifyPayment((string) $transport->payment['id']);

            $this->assertSame((string) $transport->payment['id'], $evidence['id']);
            $this->assertSame((string) $transport->merchant['id'], $evidence['merchant_order_id']);
            $this->assertSame(2, count(array_filter($transport->calls, static fn ($call) => $call[0] === 'GET')));
        });
    }

    public static function equivalentIdentityRepresentations(): array
    {
        return [['payment order string'], ['merchant order and payment member strings']];
    }

    public function test_sdk_boundary_identities_are_returned_as_exact_canonical_strings(): void
    {
        $this->withRemoteLink(function ($transport) {
            $paymentId = (string) PHP_INT_MAX;
            $merchantOrderId = (string) (PHP_INT_MAX - 1);
            $transport->paymentId = PHP_INT_MAX;
            $transport->payment['id'] = $paymentId;
            $transport->payment['order']['id'] = $merchantOrderId;
            $transport->merchant['id'] = $merchantOrderId;
            $transport->merchant['payments'][0]['id'] = $paymentId;

            $evidence = (new MercadoPagoGateway)->verifyPayment($paymentId);

            $this->assertSame($paymentId, $evidence['id']);
            $this->assertSame($merchantOrderId, $evidence['merchant_order_id']);
            $this->assertSame('/v1/payments/'.$paymentId, $transport->calls[0][1]);
            $this->assertSame('/merchant_orders/'.$merchantOrderId, $transport->calls[1][1]);
        });
    }

    public function test_payment_id_above_sdk_range_fails_before_any_remote_call(): void
    {
        $this->withRemoteLink(function ($transport) {
            $requestedId = self::incrementDecimal((string) PHP_INT_MAX);
            $writes = new \ArrayObject;
            DB::listen(static function ($query) use ($writes) {
                if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query->sql)) {
                    $writes->append(true);
                }
            });

            try {
                (new MercadoPagoGateway)->verifyPayment($requestedId);
                $this->fail('An identity outside the SDK integer range must fail closed.');
            } catch (\OverflowException $exception) {
                $this->assertSame('Mercado Pago identity exceeds the SDK integer range.', $exception->getMessage());
            }

            $this->assertSame([], $transport->calls);
            $this->assertCount(0, $writes, 'An out-of-range requested identity must execute no writes.');
            $this->assertDatabaseCount('payment_transactions', 0);
        });
    }

    public function test_merchant_order_id_above_sdk_range_fails_after_payment_lookup_only(): void
    {
        $this->withRemoteLink(function ($transport) {
            $requestedOrderId = self::incrementDecimal((string) PHP_INT_MAX);
            $this->overrideSdkResponseRaw('/v1/payments/', static function (array $content) use ($requestedOrderId): array {
                $content['order']['id'] = $requestedOrderId;

                return $content;
            });
            $writes = new \ArrayObject;
            DB::listen(static function ($query) use ($writes) {
                if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query->sql)) {
                    $writes->append(true);
                }
            });

            try {
                (new MercadoPagoGateway)->verifyPayment((string) $transport->paymentId);
                $this->fail('An out-of-range merchant order identity must fail before its lookup.');
            } catch (\OverflowException $exception) {
                $this->assertSame('Mercado Pago identity exceeds the SDK integer range.', $exception->getMessage());
            }

            $this->assertSame(1, count($transport->calls));
            $this->assertSame('/v1/payments/'.$transport->paymentId, $transport->calls[0][1]);
            $this->assertCount(0, $writes, 'An out-of-range merchant order identity must execute no writes.');
            $this->assertDatabaseCount('payment_transactions', 0);
        });
    }

    public function test_identity_larger_than_sdk_integer_range_fails_before_typed_client_conversion(): void
    {
        $this->withRemoteLink(function ($transport) {
            $oversizedOrderId = self::incrementDecimal((string) PHP_INT_MAX);
            $this->overrideSdkResponseRaw('/v1/payments/', static function (array $content) use ($oversizedOrderId): array {
                $content['order']['id'] = $oversizedOrderId;

                return $content;
            });
            $writes = new \ArrayObject;
            DB::listen(static function ($query) use ($writes) {
                if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query->sql)) {
                    $writes->append(true);
                }
            });

            try {
                (new MercadoPagoGateway)->verifyPayment((string) $transport->paymentId);
                $this->fail('An out-of-range raw identity must fail before SDK integer conversion.');
            } catch (\OverflowException $exception) {
                $this->assertSame('Mercado Pago identity exceeds the SDK integer range.', $exception->getMessage());
            }

            $this->assertSame(1, count($transport->calls));
            $this->assertTrue(str_starts_with($transport->calls[0][1], '/v1/payments/'), 'Only the payment lookup may occur.');
            $this->assertCount(0, $writes, 'Unrepresentable identities must not cause writes.');
            $this->assertDatabaseCount('payment_transactions', 0);
        });
    }

    public function test_raw_identity_divergence_from_its_sdk_dto_is_contradictory(): void
    {
        $this->withRemoteLink(function ($transport) {
            $this->overrideSdkResponseRaw('/v1/payments/', static function (array $content): array {
                $content['id'] = (string) $content['id'].'1';

                return $content;
            });

            $this->assertRejectedWithoutWrites(
                fn () => (new MercadoPagoGateway)->verifyPayment((string) $transport->paymentId),
                LinkFailure::CONTRADICTORY_EVIDENCE
            );
        });
    }

    private function overrideSdkResponseRaw(string $uriPrefix, \Closure $mutator): void
    {
        $property = new \ReflectionProperty(MercadoPagoConfig::class, 'http_client');
        $delegate = $property->getValue();
        $property->setValue(null, new class($delegate, $uriPrefix, $mutator) implements MPHttpClient
        {
            public function __construct(private MPHttpClient $delegate, private string $uriPrefix, private \Closure $mutator) {}

            public function send(MPRequest $request): MPResponse
            {
                $response = $this->delegate->send($request);
                if ($request->getMethod() !== 'GET' || ! str_starts_with($request->getUri(), $this->uriPrefix)) {
                    return $response;
                }

                return new class($response->getStatusCode(), $response->getContent(), $this->mutator) extends MPResponse
                {
                    private int $reads = 0;

                    public function __construct(int $statusCode, array $content, private \Closure $mutator)
                    {
                        parent::__construct($statusCode, $content);
                    }

                    public function getContent(): array
                    {
                        $content = parent::getContent();
                        if (++$this->reads > 1) {
                            return ($this->mutator)($content);
                        }

                        return $content;
                    }
                };
            }
        });
    }

    private static function incrementDecimal(string $digits): string
    {
        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            if ($digits[$index] !== '9') {
                $digits[$index] = chr(ord($digits[$index]) + 1);

                return $digits;
            }
            $digits[$index] = '0';
        }

        return '1'.$digits;
    }

    /** @dataProvider invalidRemoteIdentityRepresentations */
    public function test_noncanonical_remote_identities_are_rejected(string $location, string $form): void
    {
        $this->withRemoteLink(function ($transport) use ($location, $form) {
            $identity = match ($location) {
                'payment' => $transport->payment['id'],
                'payment order' => $transport->payment['order']['id'],
                'merchant order' => $transport->merchant['id'],
                default => $transport->merchant['payments'][0]['id'],
            };
            $invalid = match ($form) {
                'leading zero' => '0'.(string) $identity,
                'zero' => 0,
                'negative' => -1,
                'float' => (float) $identity + 0.5,
                'scientific notation' => (string) $identity.'e0',
                'spaces' => ' '.(string) $identity,
                'alphanumeric' => (string) $identity.'x',
                'suffix' => (string) $identity.'suffix',
                'positive sign' => '+'.(string) $identity,
                'decimal notation' => (string) $identity.'.0',
                'prefix' => 'id'.(string) $identity,
                'empty' => '',
                'null' => null,
                'boolean' => true,
                default => '',
            };
            $uriPrefix = in_array($location, ['payment', 'payment order'], true)
                ? '/v1/payments/'
                : '/merchant_orders/';
            $this->overrideSdkResponseRaw($uriPrefix, static function (array $content) use ($location, $invalid): array {
                switch ($location) {
                    case 'payment': $content['id'] = $invalid;
                        break;
                    case 'payment order': $content['order']['id'] = $invalid;
                        break;
                    case 'merchant order': $content['id'] = $invalid;
                        break;
                    case 'payment member': $content['payments'][0]['id'] = $invalid;
                        break;
                }

                return $content;
            });

            $writes = new \ArrayObject;
            DB::listen(static function ($query) use ($writes) {
                if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query->sql)) {
                    $writes->append(true);
                }
            });
            try {
                (new MercadoPagoGateway)->verifyPayment((string) $transport->paymentId);
                $this->fail('A noncanonical remote identity must be rejected.');
            } catch (LinkFailure $exception) {
                $this->assertSame(LinkFailure::REMOTE_EVIDENCE_INCOMPLETE, $exception->category);
            }
            $this->assertCount(0, $writes, 'Rejected identities must execute no writes.');
            $this->assertDatabaseCount('payment_transactions', 0);
        });
    }

    public static function invalidRemoteIdentityRepresentations(): array
    {
        $cases = [];
        foreach (['payment', 'payment order', 'merchant order', 'payment member'] as $location) {
            foreach (['leading zero', 'zero', 'negative', 'float', 'scientific notation', 'spaces', 'alphanumeric', 'suffix', 'positive sign', 'decimal notation', 'prefix', 'empty', 'null', 'boolean'] as $form) {
                $cases[$location.' '.$form] = [$location, $form];
            }
        }

        return $cases;
    }

    public function test_duplicate_canonically_equivalent_member_identities_are_rejected(): void
    {
        $this->withRemoteLink(function ($transport) {
            $transport->merchant['payments'][] = [
                'id' => (string) $transport->merchant['payments'][0]['id'],
                'transaction_amount' => $transport->merchant['payments'][0]['transaction_amount'],
                'currency_id' => $transport->merchant['payments'][0]['currency_id'],
            ];

            $this->assertRejectedWithoutWrites(
                fn () => (new MercadoPagoGateway)->verifyPayment((string) $transport->paymentId),
                LinkFailure::CONTRADICTORY_EVIDENCE
            );
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
                case 'payment without identity': unset($transport->payment['id']);
                    break;
                case 'payment identity mismatch': $transport->payment['id']++;
                    break;
                case 'payment without status': unset($transport->payment['status']);
                    break;
                case 'payment without order': unset($transport->payment['order']);
                    break;
                case 'invalid order identity': $transport->payment['order']['id'] = -1;
                    break;
                case 'merchant identity mismatch': $transport->merchant['id']++;
                    break;
                case 'merchant absent': $transport->missing = true;
                    break;
                case 'merchant without preference': unset($transport->merchant['preference_id']);
                    break;
                case 'merchant without reference': unset($transport->merchant['external_reference']);
                    break;
                case 'merchant without payments': unset($transport->merchant['payments']);
                    break;
                case 'empty payments': $transport->merchant['payments'] = [];
                    break;
                case 'payment not contained': $transport->merchant['payments'] = [['id' => random_int(2000000001, 2100000000), 'transaction_amount' => 10.25, 'currency_id' => 'PEN']];
                    break;
                case 'contradictory duplicates': $other = $transport->merchant['payments'][0];
                    $other['transaction_amount'] = 9;
                    $transport->merchant['payments'][] = $other;
                    break;
                case 'identical duplicates': $transport->merchant['payments'][] = $transport->merchant['payments'][0];
                    break;
                case 'different member identity': $transport->merchant['payments'][0]['id']++;
                    break;
                case 'member amount mismatch': $transport->merchant['payments'][0]['transaction_amount'] = 9;
                    break;
                case 'member currency mismatch': $transport->merchant['payments'][0]['currency_id'] = 'USD';
                    break;
                case 'remote preference mismatch': $transport->merchant['preference_id'] = 'synthetic-'.bin2hex(random_bytes(16));
                    break;
                case 'local gateway mismatch': $preference->update(['gateway' => 'mock']);
                    $located->refresh();
                    break;
                case 'remote references mismatch': $transport->merchant['external_reference'] = 'synthetic-'.bin2hex(random_bytes(16));
                    break;
                case 'local reference mismatch': $transport->merchant['external_reference'] = 'synthetic-'.bin2hex(random_bytes(16));
                    $transport->payment['external_reference'] = $transport->merchant['external_reference'];
                    break;
                case 'local amount mismatch': $transport->payment['transaction_amount'] = 9;
                    $transport->merchant['payments'][0]['transaction_amount'] = 9;
                    $transport->merchant['total_amount'] = 9;
                    break;
                case 'configured currency mismatch': $transport->payment['currency_id'] = 'USD';
                    $transport->merchant['payments'][0]['currency_id'] = 'USD';
                    break;
                case 'inactive preference': $preference->update(['state' => PaymentPreference::SUPERSEDED]);
                    $located->refresh();
                    break;
                case 'preference switched after localization': $order->update(['current_payment_preference_id' => null]);
                    break;
                case 'preference reassigned after localization':
                    $other = $order->replicate(['current_payment_preference_id']);
                    $other->save();
                    $preference->update(['order_id' => $other->id]);
                    break;
                case 'order replaced after localization':
                    $other = $order->replicate(['current_payment_preference_id']);
                    $other->save();
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
                case 'payment missing amount': unset($transport->payment['transaction_amount']);
                    break;
                case 'member missing amount': unset($transport->merchant['payments'][0]['transaction_amount']);
                    break;
                case 'member missing currency': unset($transport->merchant['payments'][0]['currency_id']);
                    break;
                case 'merchant missing total': unset($transport->merchant['total_amount']);
                    break;
                case 'payment missing reference': unset($transport->payment['external_reference']);
                    break;
                case 'payment identity unexpected type': $transport->payment['id'] = [];
                    break;
                case 'order unexpected type': $transport->payment['order'] = 'invalid';
                    break;
                case 'payments unexpected type': $transport->merchant['payments'] = 'invalid';
                    break;
                case 'amount unexpected type': $transport->payment['transaction_amount'] = [];
                    break;
                case 'status unexpected type': $transport->payment['status'] = [];
                    break;
                case 'member unexpected type': $transport->merchant['payments'] = ['invalid'];
                    break;
                case 'empty response': $transport->merchant = [];
                    break;
                case 'currency lowercase': $transport->payment['currency_id'] = 'pen';
                    $transport->merchant['payments'][0]['currency_id'] = 'pen';
                    break;
                case 'currency whitespace': $transport->payment['currency_id'] = 'PEN ';
                    $transport->merchant['payments'][0]['currency_id'] = 'PEN ';
                    break;
                case 'amount precision': $transport->payment['transaction_amount'] = 10.251;
                    break;
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
