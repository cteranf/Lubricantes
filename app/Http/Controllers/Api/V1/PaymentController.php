<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InventoryException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentPreference;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Services\InventoryReservationExpirationService;
use App\Services\InventoryService;
use App\Services\OrderPaymentService;
use App\Services\OrderStateService;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use App\Services\PaymentPreferenceService;
use App\Services\PaymentProcessingService;
use App\Services\PaymentSettingService;
use App\Services\PaymentWebhookSignatureService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use MercadoPago\Exceptions\InvalidWebhookSignatureException;

class PaymentController extends Controller
{
    public function __construct(private PaymentSettingService $paymentSettings, private PaymentPreferenceService $preferences, private PaymentProcessingService $processor, private PaymentWebhookSignatureService $signatures, private OrderStateService $states, private InventoryService $inventory, private OrderPaymentService $payments, private InventoryReservationExpirationService $reservationExpiration) {}

    public function createPayment(Request $request)
    {
        $data = $request->validate(['order_id' => 'required|exists:orders,id']);
        $order = Order::with('items.product', 'user')->findOrFail($data['order_id']);
        abort_unless((int) $order->user_id === (int) $request->user()->id, 403);
        if (in_array($order->status, ['canceled', 'rejected'], true) || ($order->reserved_until && $order->reserved_until->isPast())) {
            if ($order->reserved_until && $order->reserved_until->isPast()) {
                $this->reservationExpiration->expireOrder($order->id);
            }

            return response()->json(['code' => 'reservation_expired', 'message' => 'El pedido o su reserva no se encuentran vigentes.', 'can_retry' => true], 409);
        }
        $this->assertPayable($order);
        $preference = $this->preferences->createFor($order, $this->gatewayOrderData($order), $this->paymentSettings->getActiveGateway());
        if ($preference->state !== PaymentPreference::ACTIVE) {
            return response()->json(['code' => $preference->state === PaymentPreference::ORPHANED ? 'payment_reconciliation_required' : 'payment_preference_failed', 'message' => 'La preferencia no está disponible todavía; no se creó un segundo intento.'], 409);
        }

        return response()->json(['payment_id' => $preference->provider_preference_id, 'preference_id' => $preference->provider_preference_id, 'checkout_url' => config('payment.mercadopago.sandbox') ? ($preference->sandbox_init_point ?: $preference->init_point) : $preference->init_point]);
    }

    /** Browser returns are display-only: they never call the provider or change inventory. */
    public function handleReturn(Request $request)
    {
        $data = $request->validate(['preference_id' => 'nullable|string|max:255', 'result' => 'nullable|string|max:40']);
        abort_unless(! empty($data['preference_id']), 422, 'No se pudo identificar la preferencia de pago.');
        $order = Order::where('user_id', $request->user()->id)->where(function ($query) use ($data) {
            $query->where('payment_id', $data['preference_id'])->orWhereHas('currentPaymentPreference', fn ($p) => $p->where('provider_preference_id', $data['preference_id']));
        })->firstOrFail();

        return response()->json(['order_id' => $order->id, 'order_status' => $order->status, 'payment_status' => $order->payment_status, 'display_status' => $order->payment_status]);
    }

    public function verifyPayment(Request $request, string $paymentId)
    {
        $data = $request->validate(['order_id' => 'required|integer|exists:orders,id']);
        $order = Order::whereKey($data['order_id'])->where('user_id', $request->user()->id)->firstOrFail();
        $preference = $order->currentPaymentPreference;
        abort_unless($preference && $preference->state === PaymentPreference::ACTIVE, 404);
        abort_unless(PaymentTransaction::where('order_id', $order->id)->where('gateway', $preference->gateway)->where('provider_payment_id', $paymentId)->exists(), 404);
        $updated = $this->processor->process($preference, PaymentGatewayFactory::create($preference->gateway)->verifyPayment($paymentId));

        return response()->json(['order_id' => $updated->id, 'order_status' => $updated->status, 'payment_status' => $updated->payment_status]);
    }

    public function webhook(Request $request)
    {
        // Existing simulator callers are retained only for the automated test environment.
        // Real Mercado Pago traffic always takes the signed path below.
        if ($this->legacyMockWebhookAllowed($request)) {
            return $this->processLegacyMockWebhook($request);
        }
        $paymentId = (string) data_get($request->all(), 'data.id', '');
        try {
            $this->signatures->validate($request->header('x-signature'), $request->header('x-request-id'), $paymentId ?: null);
        } catch (InvalidWebhookSignatureException|\InvalidArgumentException|\RuntimeException) {
            Log::warning('Mercado Pago webhook signature rejected', ['request_id_hash' => $this->hash($request->header('x-request-id'))]);

            return response()->json(['status' => 'unauthorized'], 401);
        }
        if ($paymentId === '') {
            return response()->json(['status' => 'invalid'], 422);
        }
        $event = $this->recordEvent($request, $paymentId);
        if (in_array($event->status, [PaymentWebhookEvent::PROCESSED, PaymentWebhookEvent::IGNORED], true)) {
            return response()->json(['status' => 'ok']);
        }
        try {
            $payment = PaymentGatewayFactory::create('mercadopago')->verifyPayment($paymentId);
            $preference = PaymentPreference::where('gateway', 'mercadopago')->where('provider_preference_id', $payment['preference_id'] ?? null)->where('external_reference', $payment['external_reference'] ?? null)->first();
            if (! $preference) {
                $this->finishEvent($event, PaymentWebhookEvent::IGNORED);

                return response()->json(['status' => 'ok']);
            }
            $this->processor->process($preference, $payment);
            $this->finishEvent($event, PaymentWebhookEvent::PROCESSED);
        } catch (ValidationException|InventoryException) {
            $this->finishEvent($event, PaymentWebhookEvent::FAILED, 'payment_validation_failed');

            return response()->json(['status' => 'invalid'], 422);
        } catch (\Throwable) {
            $this->finishEvent($event, PaymentWebhookEvent::FAILED, 'provider_or_processing_error');
            Log::error('Mercado Pago webhook processing failed', ['event_id' => $event->id]);

            return response()->json(['status' => 'error'], 500);
        }

        return response()->json(['status' => 'ok']);
    }

    private function recordEvent(Request $request, string $paymentId): PaymentWebhookEvent
    {
        $payload = $request->all();
        $canonicalPayload = $this->canonicalize($payload);
        $payloadHash = hash('sha256', json_encode($canonicalPayload, JSON_UNESCAPED_SLASHES));
        $eventId = data_get($payload, 'id');
        $key = $eventId ? 'mp:event:'.$eventId : 'mp:notification:'.hash('sha256', implode('|', [$paymentId, data_get($payload, 'type', ''), data_get($payload, 'action', ''), $payloadHash]));
        try {
            return DB::transaction(function () use ($request, $paymentId, $payload, $payloadHash, $eventId, $key) {
                $event = PaymentWebhookEvent::firstOrCreate(['idempotency_key' => $key], ['gateway' => 'mercadopago', 'provider_event_id' => $eventId ? (string) $eventId : null, 'provider_payment_id' => $paymentId, 'event_type' => data_get($payload, 'type'), 'action' => data_get($payload, 'action'), 'payload_hash' => $payloadHash, 'request_id_hash' => $this->hash($request->header('x-request-id')), 'status' => PaymentWebhookEvent::RECEIVED, 'received_at' => now()]);

                return PaymentWebhookEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            });
        } catch (QueryException) {
            return PaymentWebhookEvent::where('idempotency_key', $key)->firstOrFail();
        }
    }

    private function finishEvent(PaymentWebhookEvent $event, string $status, ?string $reason = null): void
    {
        $event->update(['status' => $status, 'failure_reason' => $reason, 'processing_started_at' => $event->processing_started_at ?: now(), 'processed_at' => now()]);
    }

    private function processLegacyMockWebhook(Request $request)
    {
        $order = Order::find(data_get($request->all(), 'order_id'));
        if (! $order) {
            return response()->json(['status' => 'ok']);
        }
        $payment = PaymentGatewayFactory::create('mock')->handleWebhook($request->all());
        DB::transaction(function () use ($order, $payment) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $updated = $this->states->applyPaymentStatus($locked, $payment);
            if ($updated->payment_status === 'approved') {
                $this->payments->recordCardAttempt($updated, $payment['id'], PaymentTransaction::APPROVED, $payment);
                if ($updated->reservations()->exists()) {
                    $this->inventory->consumeOrderReservation($updated);
                }
                if (! $updated->paid_at) {
                    $updated->update(['paid_at' => now()]);
                }
            }
        });

        return response()->json(['status' => 'ok']);
    }

    private function legacyMockWebhookAllowed(Request $request): bool
    {
        if (! app()->environment('testing') || ! config('payment.testing_legacy_webhook') || $this->paymentSettings->getActiveGateway() !== 'mock' || $request->header('x-signature')) {
            return false;
        }

        // Legacy simulator contract is deliberately unambiguous: both identifiers
        // must be present and the signed Mercado Pago shape must not be mixed in.
        return filled($request->input('order_id'))
            && filled($request->input('payment_id'))
            && ! $request->has('data');
    }

    private function assertPayable(Order $order): void
    {
        if ($order->payment_method !== 'card' || ! $this->paymentSettings->isMethodAllowed('card', $order->delivery_type)) {
            throw ValidationException::withMessages(['order_id' => ['El pago con tarjeta no está disponible para este pedido.']]);
        }
        if ($order->payment_status === 'approved' || $order->paid_at || in_array($order->status, ['canceled', 'rejected'], true) || ($order->reserved_until && $order->reserved_until->isPast())) {
            throw ValidationException::withMessages(['order_id' => ['El pedido no está disponible para pago.']]);
        }
    }

    private function gatewayOrderData(Order $order): array
    {
        return ['order_id' => $order->id, 'items' => $order->items->map(fn ($item) => ['name' => $item->product->name, 'quantity' => $item->quantity, 'price' => $item->price])->all(), 'customer' => ['name' => $order->user->name, 'email' => $order->user->email], 'total' => $order->total];
    }

    private function hash(?string $value): ?string
    {
        return $value ? hash('sha256', $value) : null;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        if (array_is_list($value)) {
            return $value;
        }
        ksort($value, SORT_STRING);

        return $value;
    }
}
