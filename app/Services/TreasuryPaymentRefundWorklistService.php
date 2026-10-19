<?php

namespace App\Services;

use App\Exceptions\PaymentRefundReadConflictException;
use App\Models\Order;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Private read model. The exact status filter scans candidates in bounded evidence batches. */
class TreasuryPaymentRefundWorklistService
{
    public const MAX_PER_PAGE = 20;

    public const BATCH_SIZE = 20;

    public const MAX_CANDIDATES = 200;

    public const MAX_MATCHES = 100;

    public const MAX_ATTEMPTS = 3;

    public function __construct(private TreasuryPaymentRefundAvailabilityService $availability, private InventoryBalanceLockService $locks) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->candidates();
        if (isset($filters['order'])) {
            $query->where('orders.id', (int) ltrim($filters['order'], '#'));
        }
        if (isset($filters['payment_method'])) {
            $query->where('orders.payment_method', $filters['payment_method']);
        }
        // relevant_at is always orders.created_at (application timezone, inclusive days).
        if (isset($filters['date_from'])) {
            $query->where('orders.created_at', '>=', $filters['date_from'].' 00:00:00');
        }
        if (isset($filters['date_to'])) {
            $query->where('orders.created_at', '<', \Carbon\CarbonImmutable::parse($filters['date_to'])->addDay()->format('Y-m-d').' 00:00:00');
        }
        if (isset($filters['refund_status'])) {
            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
                $frontier = (int) (clone $query)->max('orders.id');
                $this->afterFrontierCaptured($attempt, $frontier);
                $key = random_bytes(32);
                $classified = $this->scan($query, $frontier, $filters['refund_status'], $key);
                $this->afterClassification($attempt, $frontier);
                $validated = $this->scan($query, $frontier, $filters['refund_status'], $key);
                if (hash_equals($classified['fingerprint'], $validated['fingerprint'])) {
                    return $this->observedPage($validated['rows'], $filters);
                }
            }
            throw new PaymentRefundReadConflictException;
        }
        $frontier = (int) (clone $query)->max('orders.id');
        $this->afterFrontierCaptured(1, $frontier);
        $query->where('orders.id', '<=', $frontier);
        $page = $query->select('orders.id')->orderBy('orders.created_at')->orderBy('orders.id')
            ->paginate($filters['per_page'] ?? self::MAX_PER_PAGE, ['orders.id'], 'page', $filters['page'] ?? 1);
        $matchedRows = $this->rows($page->getCollection()->modelKeys());
        $page->setCollection(collect($page->getCollection()->map(fn ($order) => $matchedRows[(int) $order->id])));

        return $page;
    }

    private function scan(Builder $query, int $frontier, string $status, string $key): array
    {
        $cursor = 0;
        $inspected = 0;
        $matches = [];
        $hash = hash_init('sha256', HASH_HMAC, $key);
        while (true) {
            $take = min(self::BATCH_SIZE, self::MAX_CANDIDATES - $inspected + 1);
            $ids = (clone $query)->where('orders.id', '>', $cursor)->where('orders.id', '<=', $frontier)
                ->orderBy('orders.id')->limit($take)->pluck('orders.id')->map(fn ($id) => (int) $id)->all();
            if ($ids === []) {
                break;
            }
            $inspected += count($ids);
            if ($inspected > self::MAX_CANDIDATES || min($ids) <= $cursor) {
                throw new PaymentRefundReadConflictException;
            }
            $evidence = new TreasuryPaymentRefundReadEvidence($ids, $this->locks);
            hash_update($hash, json_encode($ids, JSON_THROW_ON_ERROR));
            hash_update($hash, $evidence->fingerprint($key));
            foreach ($this->rows($ids, $evidence) as $id => $row) {
                hash_update($hash, serialize($row));
                $observedStatus = $row['requires_manual_review'] ? 'manual_review' : ($row['refund']['status'] ?? 'not_started');
                if ($observedStatus === $status) {
                    if (count($matches) >= self::MAX_MATCHES) {
                        throw new PaymentRefundReadConflictException;
                    }
                    $matches[(int) $id] = $row;
                }
            }
            $cursor = max($ids);
        }

        return ['fingerprint' => hash_final($hash), 'rows' => $matches];
    }

    private function observedPage(array $rows, array $filters): LengthAwarePaginator
    {
        $size = $filters['per_page'] ?? self::MAX_PER_PAGE;
        $page = $filters['page'] ?? 1;
        if ($rows === []) {
            return new LengthAwarePaginator([], 0, $size, $page, ['path' => LengthAwarePaginator::resolveCurrentPath()]);
        }
        // A bounded SQL relation over public observed values, not another domain read.
        // It preserves DB pagination without a large IN or reloading mutable evidence.
        $relation = null;
        foreach ($rows as $row) {
            $part = DB::query()->selectRaw('(? + 0) AS id, ? AS observed_at', [$row['order_id'], $row['relevant_at'] ?? '']);
            if ($relation === null) {
                $relation = $part;
            } else {
                $relation->unionAll($part);
            }
        }
        $result = DB::query()->fromSub($relation, 'observed')->orderBy('observed_at')->orderBy('id')
            ->paginate($size, ['id'], 'page', $page);
        $result->setCollection($result->getCollection()->map(fn ($row) => $rows[(int) $row->id]));

        return $result;
    }

    protected function afterFrontierCaptured(int $attempt, int $frontier): void {}

    protected function afterClassification(int $attempt, int $frontier): void {}

    public function forOrder(int $orderId): ?array
    {
        return $this->rows([$orderId])[$orderId] ?? null;
    }

    protected function candidates(): Builder
    {
        return Order::query()->where(function ($q): void {
            $q->whereExists(fn ($p) => $p->selectRaw('1')->from('payment_transactions as p')
                ->whereColumn('p.order_id', 'orders.id')->where('p.transaction_type', PaymentTransaction::PAYMENT)->where('p.status', PaymentTransaction::APPROVED))
                ->orWhereIn('orders.payment_status', ['approved', 'refund_pending', 'refunded'])
                ->orWhereExists(fn ($p) => $p->selectRaw('1')->from('payment_transactions as p')->whereColumn('p.order_id', 'orders.id')->where('p.transaction_type', PaymentTransaction::REFUND))
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('payment_submissions as s')->whereColumn('s.order_id', 'orders.id')->where(function ($state): void {
                    $state->whereIn('s.status', ['approved', 'refund_pending', 'refunded'])->orWhereExists(fn ($h) => $h->selectRaw('1')->from('payment_submission_histories as h')
                        ->whereColumn('h.payment_submission_id', 's.id')->whereIn('h.event', ['refund_marked_pending', 'refund_completed']));
                }))
                ->orWhereExists(function ($r): void {
                    $r->selectRaw('1')->from('payment_refunds as r')
                        ->leftJoin('payment_transactions as original', 'original.id', '=', 'r.original_payment_transaction_id')
                        ->leftJoin('payment_transactions as accounting', 'accounting.id', '=', 'r.refund_payment_transaction_id')
                        ->leftJoin('payment_resolution_cases as c', 'c.id', '=', 'r.payment_resolution_case_id')
                        ->leftJoin('payment_submissions as s', 's.id', '=', 'c.payment_submission_id')
                        ->leftJoin('payment_transactions as received', 'received.id', '=', 'c.received_payment_transaction_id')
                        ->where(fn ($link) => $link->whereColumn('original.order_id', 'orders.id')->orWhereColumn('accounting.order_id', 'orders.id')
                            ->orWhereColumn('s.order_id', 'orders.id')->orWhereColumn('received.order_id', 'orders.id'));
                });
        });
    }

    private function rows(array $ids, ?TreasuryPaymentRefundReadEvidence $evidence = null): array
    {
        if ($ids === []) {
            return [];
        }
        $evidence ??= new TreasuryPaymentRefundReadEvidence($ids, $this->locks);
        $flags = $this->availability->forOrders($ids, $evidence);
        $rows = [];
        foreach ($ids as $id) {
            $state = $evidence->state((int) $id);
            if ($state === null) {
                continue;
            }
            $availability = $flags[(int) $id]->toArray();
            $payments = $state['transactions']->where('transaction_type', PaymentTransaction::PAYMENT)->where('status', PaymentTransaction::APPROVED)->values();
            $payment = $payments->count() === 1 && ! $availability['requires_manual_review'] ? $payments->first() : null;
            $refund = $state['refunds']->count() === 1 ? $state['refunds']->first() : null;
            // A refund whose links disagree is never attributed to either order.
            if ($refund && ! $this->unambiguousRefund($refund, (int) $id, $evidence)) {
                $refund = null;
            }
            $rows[(int) $id] = [
                'order_id' => (int) $id, 'order_number' => '#'.str_pad((string) $id, 6, '0', STR_PAD_LEFT),
                'order_payment_status' => (string) $state['order']->payment_status,
                'original_payment_method' => $payment?->payment_method,
                'amount' => $payment ? (string) $payment->amount : null, 'currency' => $payment?->currency,
                'refund' => $refund ? ['id' => (int) $refund->id, 'status' => (string) $refund->status,
                    'created_at' => $refund->created_at?->toIso8601String(), 'completed_at' => $refund->completed_at?->toIso8601String()] : null,
                ...$availability,
                'relevant_at' => $state['order']->created_at?->toIso8601String(),
                'message' => $availability['requires_manual_review'] ? 'Requiere revisión manual.' : ($availability['can_mark_refund_pending'] ? 'Disponible para solicitar reembolso.' : ($availability['can_complete_refund'] ? 'Reembolso pendiente de finalización.' : 'Sin acciones disponibles.')),
            ];
        }

        return $rows;
    }

    private function unambiguousRefund(PaymentRefund $refund, int $orderId, TreasuryPaymentRefundReadEvidence $evidence): bool
    {
        return $evidence->refundOrderIds((int) $refund->id) === [$orderId];
    }
}
