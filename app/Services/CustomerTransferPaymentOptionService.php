<?php

namespace App\Services;

use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\PaymentReceivingAccount;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

class CustomerTransferPaymentOptionService
{
    public function eligibleOrder(Order $order, int $userId): void
    {
        abort_unless((int) $order->user_id === $userId, 404);
        if ($order->payment_method !== 'transferencia' || $order->payment_status !== 'pending' || in_array($order->status, ['canceled', 'rejected', 'delivered'], true) || $order->tracking_status === 'picked_up') {
            $this->invalid();
        }
        if (! $order->reserved_until || $order->reserved_until->isPast() || ! $order->reservations()->where('status', InventoryReservation::ACTIVE)->where('expires_at', '>', now())->exists()) {
            $this->reservationExpired();
        }
    }

    public function options(Order $order)
    {
        return PaymentReceivingAccount::where('is_active', true)->where('is_default', true)->where('currency', 'PEN')->whereIn('channel', PaymentReceivingAccount::CHANNELS)->get()->filter(fn ($a) => $this->operational($a));
    }

    public function account(Order $order, PaymentReceivingAccount $account): void
    {
        if (! $this->options($order)->contains('id', $account->id) || ! in_array($account->channel, ['yape', 'plin'], true) || ! $account->qr_path || ! $account->qr_disk) {
            abort(404);
        }
    }

    private function operational(PaymentReceivingAccount $a): bool
    {
        return in_array($a->channel, ['yape', 'plin'], true) ? filled($a->phone) && filled($a->qr_path) : filled($a->bank_name) && (filled($a->account_number) || filled($a->cci));
    }

    private function invalid(string $message = 'El pedido no está disponible para pago por transferencia.'): never
    {
        throw ValidationException::withMessages(['order' => [$message]]);
    }

    private function reservationExpired(): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'La reserva de este pedido no está vigente.',
            'code' => 'reservation_expired',
        ], 422));
    }
}
