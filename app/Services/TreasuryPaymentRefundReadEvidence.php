<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\OrderFulfillmentHistory;
use App\Models\OrderHandlingProcess;
use App\Models\OrderItem;
use App\Models\PaymentRefund;
use App\Models\PaymentResolutionCase;
use App\Models\PaymentResolutionHistory;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Internal, request-local batch evidence. Never serialize this object or its models. */
final class TreasuryPaymentRefundReadEvidence
{
    use TreasuryPaymentRefundPredicates;

    private Collection $orders;

    private Collection $submissions;

    private Collection $transactions;

    private Collection $cases;

    private Collection $refunds;

    private Collection $histories;

    private Collection $resolutionHistories;

    private Collection $deliveries;

    private Collection $handling;

    private Collection $items;

    private Collection $globalItems;

    private Collection $inventories;

    private Collection $reservations;

    private Collection $warehouses;

    private Collection $products;

    private Collection $fulfillment;

    private Collection $movements;

    private Collection $refundLinks;

    public const MAX_EVIDENCE_ROWS = 1000;

    public const MAX_ACTIVE_RESERVATIONS = 200;

    public const MAX_QUERIES = 64;

    private int $queries = 0;

    public function __construct(array $orderIds, private InventoryBalanceLockService $locks)
    {
        if (count($orderIds) > TreasuryPaymentRefundWorklistService::BATCH_SIZE) {
            throw new \App\Exceptions\PaymentRefundReadConflictException;
        }
        $this->orders = $this->readLinks(Order::class, ['id' => $orderIds])->keyBy('id');
        $this->submissions = $this->readLinks(PaymentSubmission::class, ['order_id' => $orderIds]);
        $this->transactions = $this->readLinks(PaymentTransaction::class, ['order_id' => $orderIds, 'payment_submission_id' => $this->submissions->pluck('id')->all()]);
        $this->cases = $this->readLinks(PaymentResolutionCase::class, ['payment_submission_id' => $this->submissions->pluck('id')->all(), 'received_payment_transaction_id' => $this->transactions->pluck('id')->all()]);
        $this->refunds = $this->readLinks(PaymentRefund::class, [
            'idempotency_key' => $this->transactions->map(fn ($p) => $this->refundKey((int) $p->id))->all(),
            'original_payment_transaction_id' => $this->transactions->pluck('id')->all(),
            'refund_payment_transaction_id' => $this->transactions->pluck('id')->all(),
            'payment_resolution_case_id' => $this->cases->pluck('id')->all(),
        ]);
        $related = $this->readLinks(PaymentTransaction::class, [
            'id' => $this->refunds->pluck('refund_payment_transaction_id')->merge($this->refunds->pluck('original_payment_transaction_id'))->filter()->all(),
            'idempotency_key' => $this->refunds->map(fn ($r) => $this->completionKey((int) $r->id))->all(),
        ]);
        $this->transactions = $this->bounded($this->transactions->merge($related)->unique('id')->sortBy('id')->values());
        $this->refundLinks = collect();
        foreach ($this->refunds->pluck('id')->chunk(TreasuryPaymentRefundWorklistService::BATCH_SIZE) as $ids) {
            $this->beforeQuery();
            $links = DB::table('payment_refunds as r')->whereIn('r.id', $ids->all())
                ->leftJoin('payment_transactions as original', 'original.id', '=', 'r.original_payment_transaction_id')
                ->leftJoin('payment_transactions as accounting', 'accounting.id', '=', 'r.refund_payment_transaction_id')
                ->leftJoin('payment_resolution_cases as c', 'c.id', '=', 'r.payment_resolution_case_id')
                ->leftJoin('payment_submissions as s', 's.id', '=', 'c.payment_submission_id')
                ->leftJoin('payment_transactions as received', 'received.id', '=', 'c.received_payment_transaction_id')
                ->orderBy('r.id')->get(['r.id', 'original.order_id as original_order', 'accounting.order_id as accounting_order', 's.order_id as submission_order', 'received.order_id as received_order']);
            $this->refundLinks = $this->refundLinks->concat($links);
        }
        $this->refundLinks = $this->refundLinks->keyBy('id');
        $this->histories = $this->readLinks(PaymentSubmissionHistory::class, ['payment_submission_id' => $this->submissions->pluck('id')->all()]);
        $this->resolutionHistories = $this->readLinks(PaymentResolutionHistory::class, ['payment_resolution_case_id' => $this->cases->pluck('id')->all()]);
        $this->deliveries = $this->readLinks(OrderDelivery::class, ['order_id' => $orderIds]);
        $this->handling = $this->readLinks(OrderHandlingProcess::class, ['order_id' => $orderIds]);
        $this->items = $this->readLinks(OrderItem::class, ['order_id' => $orderIds]);
        $productIds = $this->items->pluck('product_id')->filter()->unique()->values()->all();
        $this->inventories = $this->readLinks(WarehouseInventory::class, ['product_id' => $productIds])
            ->sortBy(fn ($row) => [(int) $row->warehouse_id, (int) $row->product_id, (int) $row->id])->values();
        $warehouseIds = $this->inventories->pluck('warehouse_id')->merge($this->items->pluck('warehouse_id'))->filter()->unique()->values()->all();
        $own = $this->readLinks(InventoryReservation::class, ['order_id' => $orderIds, 'order_item_id' => $this->items->pluck('id')->all()]);
        $active = $this->readLinks(InventoryReservation::class, ['product_id' => $productIds], fn ($q) => $q->where('status', InventoryReservation::ACTIVE), self::MAX_ACTIVE_RESERVATIONS);
        $this->reservations = $this->bounded($own->merge($active)->unique('id')->sortBy('id')->values());
        $referenced = $this->readLinks(OrderItem::class, ['id' => $active->pluck('order_item_id')->diff($this->items->pluck('id'))->filter()->unique()->values()->all()]);
        $this->globalItems = $this->bounded($this->items->merge($referenced)->unique('id')->sortBy('id')->values());
        $this->warehouses = $this->readLinks(Warehouse::class, ['id' => $warehouseIds]);
        $this->products = $this->readLinks(Product::class, ['id' => $productIds]);
        $this->fulfillment = $this->readLinks(OrderFulfillmentHistory::class, ['order_id' => $orderIds]);
        $this->movements = $this->readLinks(InventoryMovement::class, ['reference_id' => array_map('strval', $orderIds)], fn ($q) => $q->where('reference_type', 'order'));
    }

    private function readLinks(string $model, array $links, ?callable $scope = null, int $limit = self::MAX_EVIDENCE_ROWS): Collection
    {
        $rows = collect();
        foreach ($links as $field => $ids) {
            foreach (array_chunk(array_values(array_unique($ids)), TreasuryPaymentRefundWorklistService::BATCH_SIZE) as $chunk) {
                $query = $model::query()->whereIn($field, $chunk)->orderBy('id');
                if ($scope !== null) {
                    $scope($query);
                }
                $this->beforeQuery();
                $read = $query->limit($limit + 1)->get();
                $this->bounded($read, $limit);
                $rows = $this->bounded($rows->merge($read)->unique('id')->sortBy('id')->values(), $limit);
            }
        }

        return $rows;
    }

    private function bounded(Collection $rows, int $limit = self::MAX_EVIDENCE_ROWS): Collection
    {
        if ($rows->count() > $limit) {
            throw new \App\Exceptions\PaymentRefundReadConflictException;
        }

        return $rows;
    }

    private function beforeQuery(): void
    {
        if ($this->queries >= self::MAX_QUERIES) {
            throw new \App\Exceptions\PaymentRefundReadConflictException;
        }
        $this->queries++;
    }

    /** Opaque per-attempt HMAC only; raw evidence and digests never leave this reader. */
    public function fingerprint(string $key): string
    {
        $evidence = [];
        foreach (get_object_vars($this) as $name => $value) {
            if ($value instanceof Collection) {
                $evidence[$name] = $value->map(fn ($row) => $row instanceof \Illuminate\Database\Eloquent\Model ? $row->getRawOriginal() : (array) $row)->sortBy('id')->values()->all();
            }
        }

        return hash_hmac('sha256', serialize($this->canonicalEvidence($evidence)), $key);
    }

    private function canonicalEvidence(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonicalEvidence($item), $value);
    }

    public function state(int $orderId): ?array
    {
        $order = $this->orders->get($orderId);
        if (! $order) {
            return null;
        }
        $submissions = $this->submissions->where('order_id', $orderId)->values();
        $submission = $submissions->first();
        $transactions = $this->transactions->filter(fn ($p) => (int) $p->order_id === $orderId || $submissions->contains('id', $p->payment_submission_id))->values();
        $cases = $this->cases->filter(fn ($c) => $submissions->contains('id', $c->payment_submission_id) || $transactions->contains('id', $c->received_payment_transaction_id))->values();
        $keys = $transactions->map(fn ($p) => $this->refundKey((int) $p->id));
        $refunds = $this->refunds->filter(fn ($r) => $keys->contains($r->idempotency_key)
            || $transactions->contains('id', $r->original_payment_transaction_id) || $transactions->contains('id', $r->refund_payment_transaction_id)
            || $cases->contains('id', $r->payment_resolution_case_id))->values();
        $related = $this->transactions->filter(fn ($p) => $refunds->contains('refund_payment_transaction_id', $p->id)
            || $refunds->map(fn ($r) => $this->completionKey((int) $r->id))->contains($p->idempotency_key));
        $transactions = $transactions->merge($related)->unique('id')->sortBy('id')->values();
        $histories = $this->histories->whereIn('payment_submission_id', $submissions->pluck('id'))->values();
        $resolutionHistories = $this->resolutionHistories->whereIn('payment_resolution_case_id', $cases->pluck('id'))->values();
        $delivery = $this->deliveries->firstWhere('order_id', $orderId);
        $handling = $this->handling->firstWhere('order_id', $orderId);

        return compact('order', 'submissions', 'submission', 'transactions', 'cases', 'refunds', 'histories', 'resolutionHistories', 'delivery', 'handling');
    }

    public function normalEvidence(int $orderId): array
    {
        return ['consumedReservations' => $this->reservations->where('order_id', $orderId)->where('status', InventoryReservation::CONSUMED)->count(),
            'fulfillmentHistories' => $this->fulfillment->where('order_id', $orderId)->values()];
    }

    public function refundOrderIds(int $refundId): array
    {
        $link = $this->refundLinks->get($refundId);
        if (! $link) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', array_filter([$link->original_order, $link->accounting_order, $link->submission_order, $link->received_order], fn ($id) => $id !== null))));
        sort($ids);

        return $ids;
    }

    public function compensatedInventory(Order $order): array
    {
        $items = $this->items->where('order_id', $order->id)->values();
        $this->assertInventoryItems($items);
        $productIds = $items->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $globalItems = $this->globalItems->filter(fn ($i) => (int) $i->order_id === (int) $order->id || $productIds->contains((int) $i->product_id))->values();
        $inventories = $this->inventories->whereIn('product_id', $productIds)->values();
        $pairs = $this->locks->canonicalPairs($inventories->map(fn ($i) => ['warehouse_id' => (int) $i->warehouse_id, 'product_id' => (int) $i->product_id])
            ->concat($items->map(fn ($i) => ['warehouse_id' => (int) $i->warehouse_id, 'product_id' => (int) $i->product_id])));
        $reservations = $this->reservations->filter(function ($r) use ($order, $items, $pairs) {
            if ((int) $r->order_id === (int) $order->id || $items->contains('id', $r->order_item_id)) {
                return true;
            }

            return $r->status === InventoryReservation::ACTIVE && collect($pairs)->contains(fn ($pair) => $pair['warehouse_id'] === (int) $r->warehouse_id && $pair['product_id'] === (int) $r->product_id);
        })->values();
        $active = $reservations->where('status', InventoryReservation::ACTIVE)->values();
        $this->assertGlobalActiveReservations($active, $globalItems);
        if ($this->warehouses->whereIn('id', collect($pairs)->pluck('warehouse_id')->unique())->count() !== collect($pairs)->pluck('warehouse_id')->unique()->count()) {
            $this->conflict();
        }
        $this->assertPhysicalBalances($inventories, $pairs);
        $products = $this->products->whereIn('id', $productIds)->values();
        if ($products->count() !== $productIds->count()) {
            $this->conflict();
        }
        $this->assertReservedBalances($inventories, $active);
        $histories = $this->fulfillment->where('order_id', $order->id)->values();
        $movements = $this->movements->where('reference_id', (string) $order->id)->values();
        $consumed = $this->assertCompensatedEvidence($order, $items, $reservations, $movements, $inventories, $products, $histories);

        return ['consumedReservations' => $consumed, 'fulfillmentHistories' => $histories];
    }
}
