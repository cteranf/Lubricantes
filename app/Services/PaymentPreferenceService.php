<?php

namespace App\Services;

use App\Exceptions\PaymentLinkEvidenceException as LinkFailure;
use App\Models\Order;
use App\Models\PaymentPreference;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MercadoPago\Exceptions\MPApiException;

class PaymentPreferenceService
{
    /**
     * Persists intent before the remote call. A network-uncertain call is never retried blindly.
     */
    public function createFor(Order $order, array $orderData, string $gateway): PaymentPreference
    {
        [$preference, $created] = DB::transaction(function () use ($order, $gateway) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing = PaymentPreference::where('order_id', $order->id)
                ->where('gateway', $gateway)
                ->whereIn('state', [PaymentPreference::CREATING, PaymentPreference::ACTIVE, PaymentPreference::ORPHANED])
                ->lockForUpdate()->first();
            if ($existing) {
                if ((int) $order->current_payment_preference_id !== (int) $existing->id) {
                    $this->staleLink();
                }
                return [$existing, false];
            }

            // The order lock serializes creation; unexpected SQL failures must
            // propagate rather than being mistaken for an idempotent retry.
            $preference = PaymentPreference::create([
                    'order_id' => $order->id,
                    'gateway' => $gateway,
                    'external_reference' => 'mp_'.Str::lower((string) Str::uuid()),
                    'idempotency_key' => 'pref_'.Str::lower((string) Str::uuid()),
                    'state' => PaymentPreference::CREATING,
                    'creation_started_at' => now(),
            ]);
            $order->update(['current_payment_preference_id' => $preference->id]);

            return [$preference, true];
        }, 3);

        if (! $created) {
            return $preference;
        }

        $failureStage = 'gateway_initialization';
        try {
            $provider = PaymentGatewayFactory::create($gateway);
            $failureStage = 'remote_creation';
            $createdPreference = $provider->createPayment(array_merge($orderData, [
                'external_reference' => $preference->external_reference,
                'idempotency_key' => $preference->idempotency_key,
            ]));
        } catch (MPApiException $exception) {
            $this->recordFailure($preference, $exception, $failureStage);

            return $this->fail($preference, 'provider_rejected', $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->recordFailure($preference, $exception, $failureStage);

            // A timeout may have created a remote preference. Reconciliation is required before another attempt.
            return $this->orphan($preference, 'remote_result_uncertain');
        }

        return $this->withLockedCandidate($preference, function ($order, $locked) use ($createdPreference) {
            $locked->update([
                'provider_preference_id' => $createdPreference['id'],
                'init_point' => $createdPreference['init_point'] ?? null,
                'sandbox_init_point' => $createdPreference['sandbox_init_point'] ?? null,
                'state' => PaymentPreference::ACTIVE,
                'activated_at' => now(),
            ]);
            $order->update(['current_payment_preference_id' => $locked->id]);

            return $locked->refresh();
        });
    }

    private function recordFailure(PaymentPreference $preference, \Throwable $exception, string $stage): void
    {
        if ($preference->gateway !== 'mercadopago') {
            return;
        }
        // Diagnostics must never prevent the existing failed/orphaned transition.
        try {
            \Illuminate\Support\Facades\Log::warning('payment_preference_creation_failed',
                PaymentPreferenceFailureDiagnostics::context($preference, $exception, $stage));
        } catch (\Throwable) {
            // Do not fall back to reporting the exception/request through another logger.
        }
    }

    public function fail(PaymentPreference $preference, string $code, string $message): PaymentPreference
    {
        return $this->close($preference, PaymentPreference::FAILED, $code, $message);
    }

    public function orphan(PaymentPreference $preference, string $reason): PaymentPreference
    {
        return $this->close($preference, PaymentPreference::ORPHANED, $reason, 'Resultado remoto pendiente de reconciliación.');
    }

    private function close(PaymentPreference $preference, string $state, string $code, string $message): PaymentPreference
    {
        return $this->withLockedCandidate($preference, function ($order, $locked) use ($state, $code, $message) {
            $locked->update([
                'state' => $state,
                'error_code' => $code,
                'error_message' => Str::limit(preg_replace('/[\r\n]+/', ' ', $message), 500, ''),
                'failed_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    private function withLockedCandidate(PaymentPreference $candidate, \Closure $transition): PaymentPreference
    {
        // This read locates rows only; all decisions are revalidated under locks.
        $locator = PaymentPreference::whereKey($candidate->id)->first();
        $identity = ['id', 'order_id', 'gateway', 'external_reference', 'idempotency_key'];
        if (! $locator || $locator->only($identity) !== $candidate->only($identity)) {
            $this->staleLink();
        }
        $fields = [...$identity, 'state', 'provider_preference_id'];
        $located = $locator->only($fields);

        return DB::transaction(function () use ($located, $fields, $transition) {
            $order = Order::whereKey($located['order_id'])->lockForUpdate()->first();
            $locked = PaymentPreference::whereKey($located['id'])->lockForUpdate()->first();
            if (! $order || ! $locked || $locked->only($fields) !== $located
                || (int) $locked->order_id !== (int) $order->id
                || (int) $order->current_payment_preference_id !== (int) $locked->id) {
                $this->staleLink();
            }
            // Already completed transitions remain idempotent, with no writes.
            if ($locked->state !== PaymentPreference::CREATING) {
                return $locked;
            }

            return $transition($order, $locked);
        }, 3);
    }

    private function staleLink(): never
    {
        throw ValidationException::withMessages(['payment' => [LinkFailure::STALE_LOCAL_LINK]]);
    }
}
