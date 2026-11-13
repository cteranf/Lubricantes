<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentSubmission;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductReviewSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ProductReviewService
{
    /**
     * Bounded per-order review projection for authenticated customer tracking.
     * It is presentation only; submit() remains the authorization boundary.
     *
     * @return array<int, array{enabled: bool, eligible: bool, existing_review_status: ?string, product_slug: ?string}>
     */
    public function trackingReviewAccess(Order $order, User $user): array
    {
        if (! $user->is_active || (int) $order->user_id !== (int) $user->id) {
            return [];
        }

        $settings = app(ProductReviewSettingService::class)->current();
        $moduleEnabled = (bool) $settings->show_reviews_publicly && (bool) $settings->accept_new_reviews;
        $orderDelivered = $order->fulfillment_status === Order::FULFILLMENT_DELIVERED
            && $order->delivered_at !== null
            && in_array($order->tracking_status, ['delivered', 'picked_up'], true)
            && ! in_array($order->status, ['canceled', 'rejected'], true);

        $products = $order->items
            ->filter(fn (OrderItem $item) => $item->product !== null)
            ->mapWithKeys(fn (OrderItem $item) => [(int) $item->product_id => $item->product])
            ->all();
        $reviewableIds = collect($products)
            ->filter(fn (Product $product) => $product->is_active && $product->allow_reviews)
            ->keys()
            ->values();

        $existingStatuses = collect();
        $eligibleProductIds = collect();
        if ($moduleEnabled && $orderDelivered && $reviewableIds->isNotEmpty()) {
            $existingStatuses = ProductReview::query()
                ->where('user_id', $user->id)
                ->whereIn('product_id', $reviewableIds)
                ->pluck('status', 'product_id');

            $hasRefundedSubmission = $order->payment_status === 'refunded'
                || PaymentSubmission::query()
                    ->where('order_id', $order->id)
                    ->where(function (Builder $submissions): void {
                        $this->applyRefundedSubmissionCondition($submissions);
                    })
                    ->exists();

            if (! $hasRefundedSubmission) {
                $eligibleProductIds = OrderItem::query()
                    ->where('order_id', $order->id)
                    ->whereIn('product_id', $reviewableIds)
                    ->distinct()
                    ->pluck('product_id')
                    ->map(fn ($id) => (int) $id);
            }
        }

        $access = [];
        foreach ($products as $productId => $product) {
            $enabled = $moduleEnabled && $orderDelivered && $product->is_active && $product->allow_reviews;
            $access[$productId] = [
                'enabled' => (bool) $enabled,
                'eligible' => (bool) (
                    $enabled
                    && $eligibleProductIds->contains((int) $productId)
                    && ! $existingStatuses->has($productId)
                ),
                'existing_review_status' => $existingStatuses->get($productId),
                'product_slug' => is_string($product->slug) ? $product->slug : null,
            ];
        }

        return $access;
    }

    /**
     * Read-only eligibility for display. Submission rechecks this under locks.
     */
    public function findEligibleOrderItem(Product $product, User $user): ?OrderItem
    {
        return $this->eligibleOrderItemQuery($product, $user)->first();
    }

    public function eligibility(Product $product, ?User $user): array
    {
        $settings = app(ProductReviewSettingService::class)->current();
        $activeUser = $user?->is_active === true;
        $existing = $activeUser
            ? ProductReview::query()->where('product_id', $product->id)->where('user_id', $user->id)->first()
            : null;
        $moduleEnabled = (bool) $settings->accept_new_reviews;
        $productEnabled = (bool) $product->allow_reviews && (bool) $product->is_active;
        $eligible = false;
        $reason = 'login_required';

        if ($activeUser) {
            $reason = 'module_closed';
            if ($moduleEnabled) {
                $reason = 'product_disabled';
                if ($productEnabled) {
                    $reason = 'purchase_not_delivered';
                    $eligible = $this->findEligibleOrderItem($product, $user) !== null;
                    if ($eligible) {
                        $reason = $existing ? 'already_reviewed' : 'eligible';
                    }
                }
            }
        }

        return [
            'module_enabled' => $moduleEnabled,
            'product_enabled' => $productEnabled,
            'authenticated' => $user !== null,
            'eligible' => $eligible && $existing === null,
            'reason' => $reason,
            'existing_review' => $existing ? [
                'id' => (int) $existing->id,
                'rating' => (int) $existing->rating,
                'title' => $existing->title,
                'comment' => $existing->comment,
                'status' => $existing->status,
                'rejection_reason' => $existing->status === ProductReview::REJECTED ? $existing->rejection_reason : null,
            ] : null,
        ];
    }

    public function submit(Product $product, User $user, array $data, ?ProductReview $existing = null): ProductReview
    {
        if (! $user->is_active) {
            abort(403, 'No se pudo registrar la opinión.');
        }

        // This is only a candidate snapshot. The transaction below locks and
        // revalidates each parent order before it locks the corresponding item.
        $candidate = $this->locateEligibleOrderItemCandidate($product, $user);

        return DB::transaction(function () use ($product, $user, $data, $existing, $candidate): ProductReview {
            $orderItem = $this->lockEligibleCandidate($candidate, $product, $user);

            // Order/order-item locks precede product locks, matching fulfillment's
            // order -> inventory/product hierarchy. The singleton setting is an
            // independent lock and is acquired after the business rows.
            $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $settings = ProductReviewSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            if (! $settings->accept_new_reviews || ! $lockedProduct->is_active || ! $lockedProduct->allow_reviews) {
                abort(403, 'Las opiniones no están disponibles para este producto.');
            }
            if (! $orderItem) {
                abort(403, 'Se requiere una compra entregada para opinar.');
            }

            if ($existing) {
                $review = ProductReview::query()->whereKey($existing->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                $review->fill($data);
                $review->status = ProductReview::PENDING;
                $review->moderated_by = null;
                $review->moderated_at = null;
                $review->rejection_reason = null;
                $review->save();
            } else {
                $review = new ProductReview($data);
                $review->product_id = $lockedProduct->id;
                $review->user_id = $user->id;
                $review->verified_order_item_id = $orderItem->id;
                $review->verified_purchase_at = now();
                $review->status = $settings->require_moderation ? ProductReview::PENDING : ProductReview::PUBLISHED;
                $review->save();
            }

            return $review->fresh();
        }, 3);
    }

    /**
     * Candidate lookup is deliberately non-authoritative and happens before
     * the transaction so it cannot establish a stale consistent-read snapshot
     * before the locking read of the order.
     */
    protected function locateEligibleOrderItemCandidate(Product $product, User $user): ?OrderItem
    {
        return $this->eligibleOrderItemQuery($product, $user)->first(['order_items.id', 'order_id']);
    }

    private function eligibleOrderItemQuery(Product $product, User $user): Builder
    {
        return OrderItem::query()
            ->where('product_id', $product->id)
            ->whereHas('order', function (Builder $orders) use ($user): void {
                $this->applyEligibleOrderConditions($orders, $user);
            })
            ->whereDoesntHave('order.paymentSubmission', function (Builder $submissions): void {
                $this->applyRefundedSubmissionCondition($submissions);
            })
            ->orderByDesc('order_items.id');
    }

    private function applyEligibleOrderConditions(Builder $orders, User $user): void
    {
        $orders->where('user_id', $user->id)
            ->where('fulfillment_status', Order::FULFILLMENT_DELIVERED)
            ->whereNotNull('delivered_at')
            ->whereIn('tracking_status', ['delivered', 'picked_up'])
            ->whereNotIn('status', ['canceled', 'rejected'])
            ->where(function (Builder $payment): void {
                $payment->whereNull('payment_status')->orWhere('payment_status', '<>', 'refunded');
            });
    }

    private function applyRefundedSubmissionCondition(Builder $submissions): void
    {
        $submissions->where('status', 'refunded')
            ->orWhereHas('resolutionCase', fn (Builder $cases) => $cases->where('status', 'refunded'));
    }

    /**
     * The parent order is the serialization point shared with refund and
     * fulfillment flows. Revalidate it after the lock, then lock the item.
     */
    private function lockEligibleCandidate(?OrderItem $candidate, Product $product, User $user): ?OrderItem
    {
        if (! $candidate) {
            return null;
        }

        $order = Order::query()->whereKey($candidate->order_id)->lockForUpdate()->first();
        if (! $order || ! $this->orderIsEligibleNow($order, $user)) {
            return null;
        }

        return OrderItem::query()
            ->whereKey($candidate->id)
            ->where('order_id', $order->id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();
    }

    private function orderIsEligibleNow(Order $order, User $user): bool
    {
        if ((int) $order->user_id !== (int) $user->id
            || $order->fulfillment_status !== Order::FULFILLMENT_DELIVERED
            || ! $order->delivered_at
            || ! in_array($order->tracking_status, ['delivered', 'picked_up'], true)
            || in_array($order->status, ['canceled', 'rejected'], true)
            || $order->payment_status === 'refunded') {
            return false;
        }

        return ! PaymentSubmission::query()
            ->where('order_id', $order->id)
            ->where(function (Builder $submissions): void {
                $this->applyRefundedSubmissionCondition($submissions);
            })
            ->exists();
    }
}
