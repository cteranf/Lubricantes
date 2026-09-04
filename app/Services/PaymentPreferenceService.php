<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentPreference;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
                return [$existing, false];
            }

            try {
                $preference = PaymentPreference::create([
                    'order_id' => $order->id,
                    'gateway' => $gateway,
                    'external_reference' => 'mp_'.Str::lower((string) Str::uuid()),
                    'idempotency_key' => 'pref_'.Str::lower((string) Str::uuid()),
                    'state' => PaymentPreference::CREATING,
                    'creation_started_at' => now(),
                ]);
            } catch (QueryException $exception) {
                $preference = PaymentPreference::where('order_id', $order->id)->where('gateway', $gateway)
                    ->whereIn('state', [PaymentPreference::CREATING, PaymentPreference::ACTIVE, PaymentPreference::ORPHANED])->first();
                if (! $preference) {
                    throw $exception;
                }

                return [$preference, false];
            }
            $order->update(['current_payment_preference_id' => $preference->id]);

            return [$preference, true];
        });

        if (! $created) {
            return $preference;
        }

        try {
            $createdPreference = PaymentGatewayFactory::create($gateway)->createPayment(array_merge($orderData, [
                'external_reference' => $preference->external_reference,
                'idempotency_key' => $preference->idempotency_key,
            ]));
        } catch (MPApiException $exception) {
            return $this->fail($preference, 'provider_rejected', $exception->getMessage());
        } catch (\Throwable $exception) {
            // A timeout may have created a remote preference. Reconciliation is required before another attempt.
            return $this->orphan($preference, 'remote_result_uncertain');
        }

        return DB::transaction(function () use ($preference, $createdPreference) {
            $locked = PaymentPreference::whereKey($preference->id)->lockForUpdate()->firstOrFail();
            if ($locked->state !== PaymentPreference::CREATING) {
                return $locked;
            }
            $locked->update([
                'provider_preference_id' => $createdPreference['id'],
                'init_point' => $createdPreference['init_point'] ?? null,
                'sandbox_init_point' => $createdPreference['sandbox_init_point'] ?? null,
                'state' => PaymentPreference::ACTIVE,
                'activated_at' => now(),
            ]);
            $locked->order()->lockForUpdate()->first()->update(['current_payment_preference_id' => $locked->id]);

            return $locked->refresh();
        });
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
        return DB::transaction(function () use ($preference, $state, $code, $message) {
            $locked = PaymentPreference::whereKey($preference->id)->lockForUpdate()->firstOrFail();
            if ($locked->state !== PaymentPreference::CREATING) {
                return $locked;
            }
            $locked->update([
                'state' => $state,
                'error_code' => $code,
                'error_message' => Str::limit(preg_replace('/[\r\n]+/', ' ', $message), 500, ''),
                'failed_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
