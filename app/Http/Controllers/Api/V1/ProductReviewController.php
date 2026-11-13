<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreProductReviewRequest;
use App\Http\Requests\Api\UpdateProductReviewRequest;
use App\Http\Resources\ProductReviewResource;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Services\ProductReviewService;
use App\Services\ProductReviewSettingService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProductReviewController extends Controller
{
    public function __construct(private ProductReviewService $reviews, private ProductReviewSettingService $settings) {}

    public function summary(string $slug)
    {
        $product = Product::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();
        if (! $this->settings->current()->show_reviews_publicly || ! $product->allow_reviews) {
            return response()->json(['enabled' => false]);
        }

        $rows = ProductReview::query()->where('product_id', $product->id)->where('status', ProductReview::PUBLISHED)
            ->selectRaw('rating, COUNT(*) as total')->groupBy('rating')->get();
        $distribution = array_fill_keys([1, 2, 3, 4, 5], 0);
        $total = 0;
        $weighted = 0;
        foreach ($rows as $row) {
            $rating = (int) $row->rating;
            $count = (int) $row->total;
            $distribution[$rating] = $count;
            $total += $count;
            $weighted += $rating * $count;
        }

        return response()->json([
            'enabled' => true,
            'average' => $total === 0 ? 0 : round($weighted / $total, 2),
            'total' => $total,
            'distribution' => $distribution,
        ]);
    }

    public function index(Request $request, string $slug)
    {
        $product = Product::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();
        if (! $this->settings->current()->show_reviews_publicly || ! $product->allow_reviews) {
            return response()->json(['enabled' => false, 'data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 0]]);
        }

        $filters = $request->validate([
            'sort' => ['sometimes', 'string', 'in:newest,highest,lowest'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $query = ProductReview::query()->with(['user:id,name'])->where('product_id', $product->id)->where('status', ProductReview::PUBLISHED);
        match ($filters['sort'] ?? 'newest') {
            'highest' => $query->orderByDesc('rating')->orderByDesc('created_at'),
            'lowest' => $query->orderBy('rating')->orderByDesc('created_at'),
            default => $query->orderByDesc('created_at'),
        };
        $query->orderByDesc('id');
        $page = $query->paginate((int) ($filters['per_page'] ?? 10))->appends($request->query());

        return response()->json([
            'enabled' => true,
            'data' => ProductReviewResource::collection($page->getCollection())->resolve($request),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function eligibility(string $slug)
    {
        $product = Product::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();
        $user = Auth::guard('sanctum')->user();

        return response()->json($this->reviews->eligibility($product, $user instanceof User ? $user : null));
    }

    public function store(StoreProductReviewRequest $request, string $slug)
    {
        $product = Product::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();
        $user = $request->user();
        if (! $user instanceof User || ! $user->is_active) {
            return response()->json(['message' => 'No se pudo registrar la opinión.', 'code' => 'review_not_available'], 403);
        }
        if (ProductReview::query()->where('product_id', $product->id)->where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'Ya existe una opinión para este producto.', 'code' => 'review_already_exists'], 409);
        }

        try {
            $review = $this->reviews->submit($product, $user, $request->validated());
        } catch (QueryException $exception) {
            if (in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true)) {
                return response()->json(['message' => 'Ya existe una opinión para este producto.', 'code' => 'review_already_exists'], 409);
            }
            throw $exception;
        }

        return response()->json(['data' => [
            'id' => (int) $review->id,
            'rating' => (int) $review->rating,
            'title' => $review->title,
            'comment' => $review->comment,
            'status' => $review->status,
            'rejection_reason' => null,
        ]], 201);
    }

    public function update(UpdateProductReviewRequest $request, string $slug, ProductReview $review)
    {
        $product = Product::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();
        if ((int) $review->product_id !== (int) $product->id || (int) $review->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $updated = $this->reviews->submit($product, $request->user(), $request->validated(), $review);

        return response()->json(['data' => [
            'id' => (int) $updated->id,
            'rating' => (int) $updated->rating,
            'title' => $updated->title,
            'comment' => $updated->comment,
            'status' => $updated->status,
            'rejection_reason' => null,
        ]]);
    }
}
