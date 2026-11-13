<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListProductReviewsRequest;
use App\Http\Requests\Admin\ModerateProductReviewRequest;
use App\Http\Resources\AdminProductReviewResource;
use App\Models\ProductReview;
use App\Services\AuditService;
use App\Support\AuditEventContract;
use Illuminate\Support\Facades\DB;

class ProductReviewController extends Controller
{
    public function index(ListProductReviewsRequest $request)
    {
        $filters = $request->validated();
        $query = ProductReview::query()->with(['product:id,name', 'user:id,name']);
        foreach (['status', 'product_id', 'rating'] as $key) {
            if (array_key_exists($key, $filters)) {
                $query->where($key, $filters[$key]);
            }
        }
        if (isset($filters['search'])) {
            $term = strtr($filters['search'], ['!' => '!!', '%' => '!%', '_' => '!_']);
            $query->where(function ($builder) use ($term): void {
                $builder->where('title', 'like', '%'.$term.'%')->orWhere('comment', 'like', '%'.$term.'%')
                    ->orWhereHas('product', fn ($products) => $products->where('name', 'like', '%'.$term.'%'))
                    ->orWhereHas('user', fn ($users) => $users->where('name', 'like', '%'.$term.'%'));
            });
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 15));

        return response()->json([
            'data' => AdminProductReviewResource::collection($page->getCollection())->resolve($request),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function moderate(ModerateProductReviewRequest $request, ProductReview $review, AuditService $audit)
    {
        $data = $request->validated();
        $target = $data['status'];

        return DB::transaction(function () use ($request, $review, $audit, $data, $target) {
            $locked = ProductReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            $allowed = match ($locked->status) {
                ProductReview::PENDING => [ProductReview::PUBLISHED, ProductReview::REJECTED],
                ProductReview::PUBLISHED => [ProductReview::HIDDEN],
                ProductReview::HIDDEN => [ProductReview::PUBLISHED],
                ProductReview::REJECTED => [ProductReview::PENDING, ProductReview::PUBLISHED],
                default => [],
            };
            if (! in_array($target, $allowed, true)) {
                return response()->json(['message' => 'La transición de moderación no está permitida.', 'code' => 'review_transition_invalid'], 422);
            }
            $oldStatus = $locked->status;
            $locked->status = $target;
            $locked->moderated_by = $request->user()->id;
            $locked->moderated_at = now();
            $locked->rejection_reason = $target === ProductReview::REJECTED ? trim((string) $data['rejection_reason']) : null;
            $locked->save();

            $action = match ($target) {
                ProductReview::PUBLISHED => AuditEventContract::PRODUCT_REVIEW_PUBLISHED,
                ProductReview::REJECTED => AuditEventContract::PRODUCT_REVIEW_REJECTED,
                ProductReview::HIDDEN => AuditEventContract::PRODUCT_REVIEW_HIDDEN,
                default => AuditEventContract::PRODUCT_REVIEW_REOPENED,
            };
            $audit->recordUser(
                action: $action,
                actor: $request->user(),
                result: 'succeeded',
                auditableType: 'product',
                auditableId: (string) $locked->product_id,
                metadata: ['review_id' => (string) $locked->id, 'from_status' => $oldStatus, 'to_status' => $target],
                request: $request,
            );

            return response()->json(['data' => AdminProductReviewResource::make($locked->load(['product:id,name', 'user:id,name']))->resolve($request)]);
        });
    }
}
