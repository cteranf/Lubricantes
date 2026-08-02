<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;

class OrderPaymentService
{
    public function confirmCashOnDeliveryCollection(Order $order, User $collector, string $collectionMethod = 'cash', ?string $manualReference = null, $collectedAt = null, ?User $confirmer = null): PaymentTransaction
    {
        return DB::transaction(function () use ($order,$collector,$collectionMethod,$manualReference,$collectedAt,$confirmer) {
            $order=Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->payment_method !== 'contra_entrega') $this->invalid('Solo los pedidos contraentrega admiten cobro manual al entregar.');
            if (!in_array($collectionMethod,['cash','card_terminal','bank_transfer','other'],true)) $this->invalid('El medio de cobro no es válido.');

            $key='cod-collection-order-'.$order->id;
            $scope='cod-approved-order-'.$order->id;
            $existing=PaymentTransaction::where('idempotency_key',$key)->first();
            if ($existing) {
                if ((int)$existing->order_id !== (int)$order->id || $existing->payment_method !== 'contra_entrega' || $existing->transaction_type !== PaymentTransaction::PAYMENT) $this->invalid('La clave de cobro ya pertenece a otra operación.');
                $reference=$manualReference ? trim($manualReference) : null;
                if ($existing->collection_method!==$collectionMethod || $existing->manual_reference!==$reference) $this->invalid('El cobro ya fue confirmado con datos diferentes; no puede sobrescribirse.');
                $this->syncProjection($order,$existing);
                return $existing;
            }
            if ($approved=PaymentTransaction::where('approved_scope_key',$scope)->first()) {
                $this->syncProjection($order,$approved);
                return $approved;
            }

            $at=$collectedAt ? Carbon::parse($collectedAt) : now();
            if ($at->isFuture()) $this->invalid('La fecha efectiva del cobro no puede estar en el futuro.');
            try {
                $transaction=PaymentTransaction::create([
                    'order_id'=>$order->id,'payment_method'=>'contra_entrega','transaction_type'=>PaymentTransaction::PAYMENT,
                    'status'=>PaymentTransaction::APPROVED,'amount'=>$order->total,'currency'=>'PEN','idempotency_key'=>$key,
                    'approved_scope_key'=>$scope,'manual_reference'=>$manualReference ? trim($manualReference) : null,
                    'collection_method'=>$collectionMethod,'collected_by'=>$collector->id,'collected_at'=>$at,
                    'confirmed_by'=>($confirmer ?: $collector)->id,'confirmed_at'=>now(),
                    'metadata'=>['source'=>'delivery_confirmation','order_total_snapshot'=>(string)$order->total],
                ]);
            } catch (QueryException $e) {
                $transaction=PaymentTransaction::where('idempotency_key',$key)->orWhere('approved_scope_key',$scope)->first();
                if (!$transaction) throw $e;
            }
            $this->syncProjection($order,$transaction);
            return $transaction->fresh(['collector:id,name','confirmer:id,name']);
        });
    }

    public function approvedCashOnDeliveryFor(Order $order): ?PaymentTransaction
    {
        return PaymentTransaction::where('order_id',$order->id)->where('payment_method','contra_entrega')->where('transaction_type',PaymentTransaction::PAYMENT)->where('status',PaymentTransaction::APPROVED)->first();
    }

    private function syncProjection(Order $order, PaymentTransaction $transaction): void
    {
        if ($transaction->status===PaymentTransaction::APPROVED && ($order->payment_status!=='approved' || !$order->paid_at)) {
            $order->update(['payment_status'=>'approved','paid_at'=>$order->paid_at ?: $transaction->collected_at]);
        }
    }
    private function invalid(string $message): never { throw ValidationException::withMessages(['payment'=>[$message]]); }
}
