<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductReviewSetting;
use App\Models\User;
use App\Services\ProductReviewService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductReviewsTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => 'customer'], $attributes));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function product(array $attributes = []): Product
    {
        static $n = 0;
        $n++;

        return Product::query()->create(array_merge(['name' => 'Aceite opinión '.$n, 'slug' => 'aceite-opinion-'.$n, 'price' => 32, 'is_active' => true, 'allow_reviews' => true], $attributes));
    }

    private function deliveredItem(User $customer, Product $product, array $orderAttributes = [], int $quantity = 1): OrderItem
    {
        $order = Order::query()->create(array_merge([
            'user_id' => $customer->id,
            'status' => 'delivered',
            'total' => 32 * $quantity,
            'shipping_info' => ['address' => 'fixture', 'city' => 'Lima'],
            'payment_method' => 'contra_entrega',
            'payment_status' => 'approved',
            'delivery_type' => 'delivery',
            'tracking_status' => 'delivered',
            'fulfillment_status' => 'delivered',
            'delivered_at' => now(),
        ], $orderAttributes));

        return OrderItem::query()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => $quantity, 'price' => 32, 'subtotal' => 32 * $quantity]);
    }

    private function enableReviews(array $values = []): void
    {
        ProductReviewSetting::query()->whereKey(1)->update(array_merge(['accept_new_reviews' => true, 'show_reviews_publicly' => true, 'require_moderation' => true], $values));
    }

    private function payload(array $replace = []): array
    {
        return array_merge(['rating' => 5, 'title' => 'Excelente producto', 'comment' => 'Lo usé durante varias semanas y funcionó muy bien.'], $replace);
    }

    private function insertReview(array $attributes): ProductReview
    {
        $review = new ProductReview;
        $review->forceFill($attributes)->save();

        return $review;
    }

    private function reviewServiceAfterCandidateLookup(callable $mutation): ProductReviewService
    {
        $service = new class extends ProductReviewService
        {
            public $afterCandidateLookup;

            protected function locateEligibleOrderItemCandidate(Product $product, User $user): ?OrderItem
            {
                $candidate = parent::locateEligibleOrderItemCandidate($product, $user);
                ($this->afterCandidateLookup)($candidate);

                return $candidate;
            }
        };
        $service->afterCandidateLookup = $mutation;

        return $service;
    }

    public function test_secure_defaults_and_product_switch_keep_reviews_closed(): void
    {
        $settings = ProductReviewSetting::query()->findOrFail(1);
        $this->assertFalse($settings->show_reviews_publicly);
        $this->assertFalse($settings->accept_new_reviews);
        $this->assertTrue($settings->require_moderation);
        $this->assertTrue($this->product()->allow_reviews);
    }

    public function test_only_customer_owned_delivered_nonrefunded_orders_are_eligible(): void
    {
        $this->enableReviews();
        $customer = $this->customer();
        $product = $this->product();
        $url = '/api/v1/products/'.$product->slug.'/review-eligibility';
        Sanctum::actingAs($customer);

        $this->getJson($url)->assertOk()->assertJsonPath('eligible', false)->assertJsonPath('reason', 'purchase_not_delivered');
        $this->deliveredItem($this->customer(), $product);
        $this->getJson($url)->assertOk()->assertJsonPath('eligible', false)->assertJsonPath('reason', 'purchase_not_delivered');
        $item = $this->deliveredItem($customer, $product, [], 4);
        $this->getJson($url)->assertOk()->assertJsonPath('eligible', true)->assertJsonPath('reason', 'eligible');
        $this->assertNotNull($item->id);

        $this->assertDatabaseMissing('product_reviews', ['product_id' => $product->id]);
    }

    public function test_global_acceptance_and_product_permission_block_submission_without_deleting_reviews(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $item = $this->deliveredItem($customer, $product);
        Sanctum::actingAs($customer);
        $url = '/api/v1/products/'.$product->slug.'/reviews';
        $this->postJson($url, $this->payload())->assertForbidden();
        $this->enableReviews();
        $id = $this->postJson($url, $this->payload())->assertCreated()->json('data.id');
        ProductReviewSetting::query()->whereKey(1)->update(['accept_new_reviews' => false]);
        $this->putJson($url.'/'.$id, $this->payload())->assertForbidden();
        ProductReviewSetting::query()->whereKey(1)->update(['accept_new_reviews' => true]);
        $product->update(['allow_reviews' => false]);
        Sanctum::actingAs($this->customer());
        $this->deliveredItem(auth()->user(), $product);
        $this->postJson($url, $this->payload())->assertForbidden();
        $this->assertDatabaseHas('product_reviews', ['id' => $id]);
        $this->assertNotNull($item->id);
    }

    public function test_pending_cancelled_rejected_and_refunded_orders_do_not_qualify(): void
    {
        $this->enableReviews();
        $customer = $this->customer();
        $product = $this->product();
        Sanctum::actingAs($customer);
        $url = '/api/v1/products/'.$product->slug.'/review-eligibility';

        foreach ([
            ['status' => 'confirmed', 'fulfillment_status' => 'ready', 'tracking_status' => 'shipped', 'delivered_at' => null],
            ['status' => 'canceled', 'fulfillment_status' => 'canceled', 'tracking_status' => 'canceled'],
            ['status' => 'rejected', 'fulfillment_status' => 'canceled', 'tracking_status' => 'canceled'],
            ['payment_status' => 'refunded'],
        ] as $attributes) {
            $this->deliveredItem($customer, $product, $attributes);
            $this->getJson($url)->assertOk()->assertJsonPath('eligible', false)->assertJsonPath('reason', 'purchase_not_delivered');
        }
    }

    public function test_server_selects_order_item_and_unique_review_covers_multiple_units_and_repurchase(): void
    {
        $this->enableReviews();
        $customer = $this->customer(['name' => '  Ana   María   Pérez  ']);
        $product = $this->product();
        $firstItem = $this->deliveredItem($customer, $product, [], 5);
        $latestItem = $this->deliveredItem($customer, $product, [], 1);
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/products/'.$product->slug.'/reviews', $this->payload([
            'user_id' => 999, 'order_id' => 999, 'order_item_id' => 999, 'status' => 'published', 'moderated_by' => 999,
        ]))->assertCreated()->assertJsonPath('data.status', 'pending');
        $review = ProductReview::query()->findOrFail($response->json('data.id'));
        $this->assertNotSame($firstItem->id, $latestItem->id);
        $this->assertSame($latestItem->id, $review->verified_order_item_id);
        $this->assertSame($customer->id, $review->user_id);
        $this->assertSame(1, ProductReview::query()->where('user_id', $customer->id)->where('product_id', $product->id)->count());
        $this->postJson('/api/v1/products/'.$product->slug.'/reviews', $this->payload())->assertConflict();
    }

    public function test_tracking_review_access_requires_owner_final_order_and_enabled_product_and_module(): void
    {
        $this->enableReviews();
        $customer = $this->customer();
        $deliveredProduct = $this->product();
        $deliveredItem = $this->deliveredItem($customer, $deliveredProduct);
        Sanctum::actingAs($customer);
        $delivered = $this->getJson('/api/v1/orders/'.$deliveredItem->order_id.'/tracking')
            ->assertOk()
            ->json('order.items.0.review_access');

        $this->assertSame([
            'enabled' => true,
            'eligible' => true,
            'existing_review_status' => null,
            'product_slug' => $deliveredProduct->slug,
        ], $delivered);

        $pickedUpProduct = $this->product();
        $pickedUpItem = $this->deliveredItem($customer, $pickedUpProduct, ['tracking_status' => 'picked_up', 'picked_up_at' => now()]);
        $this->getJson('/api/v1/orders/'.$pickedUpItem->order_id.'/tracking')
            ->assertOk()
            ->assertJsonPath('order.items.0.review_access.eligible', true);

        $pendingProduct = $this->product();
        $pendingItem = $this->deliveredItem($customer, $pendingProduct, [
            'status' => 'confirmed',
            'fulfillment_status' => 'ready',
            'tracking_status' => 'shipped',
            'delivered_at' => null,
        ]);
        $this->getJson('/api/v1/orders/'.$pendingItem->order_id.'/tracking')
            ->assertOk()
            ->assertJsonPath('order.items.0.review_access.enabled', false)
            ->assertJsonPath('order.items.0.review_access.eligible', false);

        $other = $this->customer();
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/orders/'.$deliveredItem->order_id.'/tracking')
            ->assertNotFound()
            ->assertJsonMissingPath('order.items.0.review_access');

        Sanctum::actingAs($customer);
        ProductReviewSetting::query()->whereKey(1)->update(['accept_new_reviews' => false]);
        $this->getJson('/api/v1/orders/'.$deliveredItem->order_id.'/tracking')
            ->assertOk()
            ->assertJsonPath('order.items.0.review_access.enabled', false);

        ProductReviewSetting::query()->whereKey(1)->update(['accept_new_reviews' => true]);
        $deliveredProduct->update(['allow_reviews' => false]);
        $this->getJson('/api/v1/orders/'.$deliveredItem->order_id.'/tracking')
            ->assertOk()
            ->assertJsonPath('order.items.0.review_access.enabled', false)
            ->assertJsonPath('order.items.0.review_access.eligible', false);
    }

    public function test_active_admin_who_owns_an_eligible_purchase_can_review_it(): void
    {
        $this->enableReviews();
        $admin = $this->admin();
        $product = $this->product();
        $item = $this->deliveredItem($admin, $product);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/orders/'.$item->order_id.'/tracking')
            ->assertOk()
            ->assertJsonPath('order.items.0.review_access', [
                'enabled' => true,
                'eligible' => true,
                'existing_review_status' => null,
                'product_slug' => $product->slug,
            ]);

        $response = $this->postJson('/api/v1/products/'.$product->slug.'/reviews', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', ProductReview::PENDING);

        $review = ProductReview::query()->findOrFail($response->json('data.id'));
        $this->assertSame($admin->id, $review->user_id);
        $this->assertSame($item->id, $review->verified_order_item_id);
        $this->assertSame(ProductReview::PENDING, $review->status);
    }

    public function test_admin_cannot_access_tracking_or_review_another_users_purchase(): void
    {
        $this->enableReviews();
        $owner = $this->customer();
        $admin = $this->admin();
        $product = $this->product();
        $item = $this->deliveredItem($owner, $product);
        $ownerReview = $this->insertReview([
            'product_id' => $product->id,
            'user_id' => $owner->id,
            'rating' => 4,
            'comment' => 'Opinión del propietario con longitud suficiente.',
            'status' => ProductReview::PENDING,
            'verified_order_item_id' => $item->id,
            'verified_purchase_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/orders/'.$item->order_id.'/tracking')->assertNotFound();
        $this->postJson('/api/v1/products/'.$product->slug.'/reviews', $this->payload())
            ->assertForbidden()
            ->assertExactJson(['message' => 'Se requiere una compra entregada para opinar.']);
        $this->putJson('/api/v1/products/'.$product->slug.'/reviews/'.$ownerReview->id, $this->payload())
            ->assertNotFound();

        $this->assertDatabaseHas('product_reviews', [
            'id' => $ownerReview->id,
            'user_id' => $owner->id,
            'status' => ProductReview::PENDING,
        ]);
        $this->assertDatabaseMissing('product_reviews', ['user_id' => $admin->id, 'product_id' => $product->id]);
    }

    public function test_admin_owner_sees_existing_review_state_and_can_only_edit_own_review(): void
    {
        $this->enableReviews();
        $adminOwner = $this->admin();
        $otherAdmin = $this->admin();
        $product = $this->product();
        $item = $this->deliveredItem($adminOwner, $product);
        $review = $this->insertReview([
            'product_id' => $product->id,
            'user_id' => $adminOwner->id,
            'rating' => 3,
            'comment' => 'Opinión pendiente del administrador propietario.',
            'status' => ProductReview::PENDING,
            'verified_order_item_id' => $item->id,
            'verified_purchase_at' => now(),
        ]);

        Sanctum::actingAs($adminOwner);
        $this->getJson('/api/v1/orders/'.$item->order_id.'/tracking')
            ->assertOk()
            ->assertJsonPath('order.items.0.review_access.eligible', false)
            ->assertJsonPath('order.items.0.review_access.existing_review_status', ProductReview::PENDING);
        $this->getJson('/api/v1/products/'.$product->slug.'/review-eligibility')
            ->assertOk()
            ->assertJsonPath('eligible', false)
            ->assertJsonPath('existing_review.status', ProductReview::PENDING);
        $this->putJson('/api/v1/products/'.$product->slug.'/reviews/'.$review->id, $this->payload(['rating' => 5]))
            ->assertOk()
            ->assertJsonPath('data.status', ProductReview::PENDING);

        Sanctum::actingAs($otherAdmin);
        $this->putJson('/api/v1/products/'.$product->slug.'/reviews/'.$review->id, $this->payload())
            ->assertNotFound();
        $this->assertDatabaseHas('product_reviews', [
            'id' => $review->id,
            'user_id' => $adminOwner->id,
            'rating' => 5,
            'status' => ProductReview::PENDING,
        ]);
    }

    public function test_tracking_projects_review_statuses_without_internal_ids_or_per_product_queries(): void
    {
        $this->enableReviews();
        $customer = $this->customer();
        $order = Order::query()->create([
            'user_id' => $customer->id,
            'status' => 'delivered',
            'total' => 100,
            'shipping_info' => ['address' => 'fixture', 'city' => 'Lima'],
            'payment_method' => 'contra_entrega',
            'payment_status' => 'approved',
            'delivery_type' => 'delivery',
            'tracking_status' => 'delivered',
            'fulfillment_status' => 'delivered',
            'delivered_at' => now(),
        ]);
        $statuses = [ProductReview::PENDING, ProductReview::PUBLISHED, ProductReview::REJECTED, ProductReview::HIDDEN, null];
        $products = [];
        foreach ($statuses as $index => $status) {
            $product = $this->product();
            $products[] = $product;
            $item = OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 20,
                'subtotal' => 20,
            ]);
            if ($status !== null) {
                $this->insertReview([
                    'product_id' => $product->id,
                    'user_id' => $customer->id,
                    'rating' => 5,
                    'comment' => 'Opinión asociada a una compra entregada.',
                    'status' => $status,
                    'verified_order_item_id' => $item->id,
                    'verified_purchase_at' => now(),
                ]);
            }
        }
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $products[0]->id,
            'quantity' => 1,
            'price' => 20,
            'subtotal' => 20,
        ]);

        $reviewQueries = 0;
        $submissionQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$reviewQueries, &$submissionQueries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'from "product_reviews"') || str_contains($sql, 'from `product_reviews`')) {
                $reviewQueries++;
            }
            if (str_contains($sql, 'from "payment_submissions"') || str_contains($sql, 'from `payment_submissions`')) {
                $submissionQueries++;
            }
        });

        $response = $this->actingAs($customer, 'sanctum')->getJson('/api/v1/orders/'.$order->id.'/tracking')->assertOk();
        $items = collect($response->json('order.items'))->keyBy('review_access.product_slug');
        foreach ($products as $index => $product) {
            $access = $items->get($product->slug)['review_access'];
            $this->assertSame([
                'enabled' => true,
                'eligible' => $statuses[$index] === null,
                'existing_review_status' => $statuses[$index],
                'product_slug' => $product->slug,
            ], $access);
            $this->assertSame(['enabled', 'eligible', 'existing_review_status', 'product_slug'], array_keys($access));
        }
        $this->assertCount(6, $response->json('order.items'));
        $repeatedProductAccess = collect($response->json('order.items'))
            ->filter(fn (array $item) => $item['review_access']['product_slug'] === $products[0]->slug)
            ->pluck('review_access');
        $this->assertCount(2, $repeatedProductAccess);
        $this->assertSame($repeatedProductAccess->first(), $repeatedProductAccess->last());
        $this->assertSame(1, $reviewQueries, 'Tracking should load all current user review statuses in one query.');
        $this->assertSame(1, $submissionQueries, 'Refund eligibility should be checked once per order, not once per product.');
        foreach (['review_id', 'order_item_id', 'verified_order_item_id', 'email', 'phone', 'user_id', 'product_id'] as $private) {
            $this->assertStringNotContainsString('"'.$private.'"', $response->getContent());
        }
    }

    public function test_order_is_revalidated_after_candidate_lookup_when_refund_or_cancellation_wins(): void
    {
        $this->enableReviews();
        $customer = $this->customer();
        $product = $this->product();
        Sanctum::actingAs($customer);

        foreach ([
            'refund' => ['payment_status' => 'refunded'],
            'cancellation' => ['status' => 'canceled', 'fulfillment_status' => 'canceled', 'tracking_status' => 'canceled'],
        ] as $transition => $changes) {
            $item = $this->deliveredItem($customer, $product);
            $this->app->instance(ProductReviewService::class, $this->reviewServiceAfterCandidateLookup(
                fn (?OrderItem $candidate) => Order::query()->whereKey($candidate->order_id)->update($changes)
            ));

            $response = $this->postJson('/api/v1/products/'.$product->slug.'/reviews', $this->payload())
                ->assertForbidden()
                ->assertExactJson(['message' => 'Se requiere una compra entregada para opinar.']);

            $this->assertSame(403, $response->status());
            $this->assertDatabaseMissing('product_reviews', ['product_id' => $product->id, 'user_id' => $customer->id]);
            $this->assertDatabaseHas('order_items', ['id' => $item->id, 'order_id' => $item->order_id]);
        }
    }

    public function test_order_lock_precedes_order_item_lock_and_review_uses_revalidated_item(): void
    {
        $this->enableReviews();
        $customer = $this->customer();
        $product = $this->product();
        $item = $this->deliveredItem($customer, $product);
        Sanctum::actingAs($customer);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower(str_replace(['`', '"', '[', ']'], '', $query->sql));
        });

        $response = $this->postJson('/api/v1/products/'.$product->slug.'/reviews', $this->payload())->assertCreated();

        $orderLockIndex = null;
        $itemLockIndex = null;
        foreach ($queries as $index => $sql) {
            if ($orderLockIndex === null && preg_match('/select \* from orders where .*id.*limit 1/', $sql)) {
                $orderLockIndex = $index;
            }
            if ($itemLockIndex === null && preg_match('/select \* from order_items where .*id.*order_id.*product_id.*limit 1/', $sql)) {
                $itemLockIndex = $index;
            }
        }

        $this->assertNotNull($orderLockIndex, 'The submission must lock the parent order.');
        $this->assertNotNull($itemLockIndex, 'The submission must lock the exact order item after its parent.');
        $this->assertLessThan($itemLockIndex, $orderLockIndex, 'The parent order must be locked before the order item.');
        $review = ProductReview::query()->findOrFail($response->json('data.id'));
        $this->assertSame($item->id, $review->verified_order_item_id);
        $this->assertSame(ProductReview::PENDING, $review->status);
    }

    public function test_edit_revalidates_the_purchase_under_the_order_lock_without_partial_changes(): void
    {
        $this->enableReviews();
        $customer = $this->customer();
        $product = $this->product();
        $item = $this->deliveredItem($customer, $product);
        $review = $this->insertReview([
            'product_id' => $product->id,
            'user_id' => $customer->id,
            'rating' => 3,
            'title' => 'Opinión original',
            'comment' => 'Comentario original suficientemente largo.',
            'status' => ProductReview::PUBLISHED,
            'verified_order_item_id' => $item->id,
            'verified_purchase_at' => now(),
        ]);
        Sanctum::actingAs($customer);
        $this->app->instance(ProductReviewService::class, $this->reviewServiceAfterCandidateLookup(
            fn (?OrderItem $candidate) => Order::query()->whereKey($candidate->order_id)->update([
                'status' => 'canceled',
                'fulfillment_status' => 'canceled',
                'tracking_status' => 'canceled',
            ])
        ));

        $this->putJson('/api/v1/products/'.$product->slug.'/reviews/'.$review->id, $this->payload())
            ->assertForbidden()
            ->assertExactJson(['message' => 'Se requiere una compra entregada para opinar.']);

        $this->assertDatabaseHas('product_reviews', [
            'id' => $review->id,
            'rating' => 3,
            'title' => 'Opinión original',
            'comment' => 'Comentario original suficientemente largo.',
            'status' => ProductReview::PUBLISHED,
            'verified_order_item_id' => $item->id,
        ]);
    }

    public function test_review_validation_rejects_invalid_rating_html_and_comment_lengths(): void
    {
        $this->enableReviews();
        $customer = $this->customer();
        $product = $this->product();
        $this->deliveredItem($customer, $product);
        Sanctum::actingAs($customer);
        $url = '/api/v1/products/'.$product->slug.'/reviews';
        foreach ([0, 6, '4.5'] as $rating) {
            $this->postJson($url, $this->payload(['rating' => $rating]))->assertUnprocessable()->assertJsonValidationErrors('rating');
        }
        $this->postJson($url, $this->payload(['comment' => '<b>Contenido con etiquetas</b> que supera veinte caracteres']))->assertUnprocessable()->assertJsonValidationErrors('comment');
        $this->postJson($url, $this->payload(['comment' => 'corto']))->assertUnprocessable()->assertJsonValidationErrors('comment');
    }

    public function test_edit_is_author_only_and_returns_review_to_moderation(): void
    {
        $this->enableReviews();
        $owner = $this->customer();
        $other = $this->customer();
        $product = $this->product();
        $this->deliveredItem($owner, $product);
        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/v1/products/'.$product->slug.'/reviews', $this->payload())->assertCreated()->json('data.id');
        $review = ProductReview::query()->findOrFail($id);
        $review->forceFill(['status' => 'published', 'moderated_by' => $this->admin()->id, 'moderated_at' => now()])->save();
        Sanctum::actingAs($other);
        $this->putJson('/api/v1/products/'.$product->slug.'/reviews/'.$id, $this->payload())->assertNotFound();
        Sanctum::actingAs($owner);
        $this->putJson('/api/v1/products/'.$product->slug.'/reviews/'.$id, $this->payload(['rating' => 4]))->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertNull($review->fresh()->moderated_by);
    }

    public function test_global_and_product_switches_remove_public_comments_and_aggregates_without_deleting_rows(): void
    {
        $this->enableReviews();
        $customer = $this->customer(['name' => 'Cristian Torres López']);
        $product = $this->product();
        $this->deliveredItem($customer, $product);
        Sanctum::actingAs($customer);
        $id = $this->postJson('/api/v1/products/'.$product->slug.'/reviews', $this->payload())->assertCreated()->json('data.id');
        $review = ProductReview::query()->findOrFail($id);
        $review->status = 'published';
        $review->save();
        $this->getJson('/api/v1/products/'.$product->slug.'/reviews/summary')->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('total', 1);
        $this->getJson('/api/v1/products/'.$product->slug.'/reviews')->assertOk()->assertJsonPath('data.0.display_name', 'Cristian T.')->assertJsonPath('data.0.verified_purchase', true)->assertJsonMissingPath('data.0.user_id')->assertJsonMissingPath('data.0.email');
        $this->assertSame(['id', 'display_name', 'rating', 'title', 'comment', 'verified_purchase', 'created_at'], array_keys($this->getJson('/api/v1/products/'.$product->slug.'/reviews')->json('data.0')));
        ProductReviewSetting::query()->whereKey(1)->update(['show_reviews_publicly' => false]);
        $this->getJson('/api/v1/products/'.$product->slug.'/reviews/summary')->assertOk()->assertExactJson(['enabled' => false]);
        $this->getJson('/api/v1/products/'.$product->slug.'/reviews')->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('data', []);
        $this->assertDatabaseHas('product_reviews', ['id' => $id, 'status' => 'published']);
        ProductReviewSetting::query()->whereKey(1)->update(['show_reviews_publicly' => true]);
        $product->update(['allow_reviews' => false]);
        $this->getJson('/api/v1/products/'.$product->slug.'/reviews/summary')->assertJsonPath('enabled', false);
    }

    public function test_public_summary_distribution_order_and_pagination_use_only_published_rows(): void
    {
        $this->enableReviews();
        $product = $this->product();
        foreach ([5, 3, 1] as $rating) {
            $customer = $this->customer();
            $this->deliveredItem($customer, $product);
            $this->insertReview(['product_id' => $product->id, 'user_id' => $customer->id, 'rating' => $rating, 'title' => null, 'comment' => 'Comentario público suficientemente extenso.', 'status' => $rating === 1 ? 'pending' : 'published', 'verified_order_item_id' => OrderItem::query()->where('product_id', $product->id)->latest('id')->value('id'), 'verified_purchase_at' => now()]);
        }
        $this->getJson('/api/v1/products/'.$product->slug.'/reviews/summary')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('average', 4)->assertJsonPath('distribution.5', 1)->assertJsonPath('distribution.3', 1)->assertJsonPath('distribution.1', 0);
        $this->getJson('/api/v1/products/'.$product->slug.'/reviews?sort=highest&per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.rating', 5);
        $this->getJson('/api/v1/products/'.$product->slug.'/reviews?sort=lowest')->assertOk()->assertJsonPath('data.0.rating', 3);
        $this->getJson('/api/v1/products/'.$product->slug.'/reviews?sort=rating')->assertUnprocessable();
    }

    public function test_admin_moderation_enforces_transitions_and_rejection_reason_and_audits_without_text(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $item = $this->deliveredItem($customer, $product);
        $review = $this->insertReview(['product_id' => $product->id, 'user_id' => $customer->id, 'rating' => 4, 'comment' => 'Comentario sensible de fixture con longitud suficiente.', 'status' => 'pending', 'verified_order_item_id' => $item->id, 'verified_purchase_at' => now()]);
        Sanctum::actingAs($this->admin());
        $this->patchJson('/api/v1/admin/product-reviews/'.$review->id.'/moderation', ['status' => 'rejected'])->assertUnprocessable()->assertJsonValidationErrors('rejection_reason');
        $this->patchJson('/api/v1/admin/product-reviews/'.$review->id.'/moderation', ['status' => 'published'])->assertOk();
        $this->patchJson('/api/v1/admin/product-reviews/'.$review->id.'/moderation', ['status' => 'hidden'])->assertOk();
        $this->patchJson('/api/v1/admin/product-reviews/'.$review->id.'/moderation', ['status' => 'rejected', 'rejection_reason' => 'Motivo interno'])->assertUnprocessable();
        $secondCustomer = $this->customer();
        $secondItem = $this->deliveredItem($secondCustomer, $product);
        $pending = $this->insertReview(['product_id' => $product->id, 'user_id' => $secondCustomer->id, 'rating' => 3, 'comment' => 'Otra opinión suficientemente extensa para moderación.', 'status' => 'pending', 'verified_order_item_id' => $secondItem->id, 'verified_purchase_at' => now()]);
        $this->patchJson('/api/v1/admin/product-reviews/'.$pending->id.'/moderation', ['status' => 'rejected', 'rejection_reason' => 'No cumple la política'])->assertOk();
        $this->patchJson('/api/v1/admin/product-reviews/'.$pending->id.'/moderation', ['status' => 'pending'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'product_review.reopened']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product_review.published']);
        $this->assertStringNotContainsString('Comentario sensible', json_encode(DB::table('audit_logs')->get()->toArray()));
        Sanctum::actingAs($this->customer());
        $this->getJson('/api/v1/admin/product-reviews')->assertForbidden();
    }

    public function test_admin_settings_are_strictly_validated_and_audited_without_review_content(): void
    {
        Sanctum::actingAs($this->admin());
        $this->putJson('/api/v1/admin/product-reviews/settings', ['show_reviews_publicly' => 'yes', 'accept_new_reviews' => true, 'require_moderation' => true])->assertUnprocessable();
        $this->putJson('/api/v1/admin/product-reviews/settings', ['show_reviews_publicly' => true, 'accept_new_reviews' => true, 'require_moderation' => true])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'product_review.settings_updated']);
    }

    public function test_admin_review_list_is_paginated_and_does_not_load_users_per_row(): void
    {
        $product = $this->product();
        foreach (range(1, 4) as $i) {
            $customer = $this->customer();
            $item = $this->deliveredItem($customer, $product);
            $this->insertReview(['product_id' => $product->id, 'user_id' => $customer->id, 'rating' => $i + 1, 'comment' => 'Comentario de cliente con suficiente longitud.', 'status' => 'pending', 'verified_order_item_id' => $item->id, 'verified_purchase_at' => now()]);
        }
        $userQueries = [];
        DB::listen(static function (QueryExecuted $query) use (&$userQueries): void {
            if (preg_match('/select .* from ["`]?users["`]?/i', $query->sql) === 1) {
                $userQueries[] = $query->sql;
            }
        });
        Sanctum::actingAs($this->admin());
        $response = $this->getJson('/api/v1/admin/product-reviews?per_page=2')->assertOk()->assertJsonPath('meta.total', 4)->assertJsonCount(2, 'data');
        $this->assertCount(1, $userQueries, 'Admin list must eager-load names in one query.');
        $this->assertArrayNotHasKey('email', $response->json('data.0'));
        $this->assertArrayNotHasKey('phone', $response->json('data.0'));
    }

    public function test_public_review_list_eager_loads_names_in_a_bounded_query(): void
    {
        $this->enableReviews();
        $product = $this->product();
        foreach (range(1, 4) as $rating) {
            $customer = $this->customer();
            $item = $this->deliveredItem($customer, $product);
            $this->insertReview(['product_id' => $product->id, 'user_id' => $customer->id, 'rating' => $rating, 'comment' => 'Opinión pública con contenido de longitud suficiente.', 'status' => 'published', 'verified_order_item_id' => $item->id, 'verified_purchase_at' => now()]);
        }
        $userQueries = [];
        DB::listen(static function (QueryExecuted $query) use (&$userQueries): void {
            if (preg_match('/select .* from ["`]?users["`]?/i', $query->sql) === 1) {
                $userQueries[] = $query->sql;
            }
        });

        $this->getJson('/api/v1/products/'.$product->slug.'/reviews?per_page=10')->assertOk()->assertJsonCount(4, 'data');
        $this->assertCount(1, $userQueries, 'Public review list must eager-load display names once.');
    }

    public function test_review_submission_is_rate_limited_per_customer(): void
    {
        $this->enableReviews();
        $customer = $this->customer();
        $product = $this->product();
        $this->deliveredItem($customer, $product);
        Sanctum::actingAs($customer);
        $url = '/api/v1/products/'.$product->slug.'/reviews';
        $this->postJson($url, $this->payload())->assertCreated();
        foreach (range(1, 4) as $_) {
            $this->postJson($url, $this->payload())->assertConflict();
        }
        $this->postJson($url, $this->payload())->assertStatus(429);
    }
}
