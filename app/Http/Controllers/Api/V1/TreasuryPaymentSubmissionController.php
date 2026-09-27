<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveTreasuryPaymentSubmissionRequest;
use App\Http\Requests\TreasuryPaymentSubmissionDecisionRequest;
use App\Http\Requests\TreasuryPaymentSubmissionIndexRequest;
use App\Http\Resources\TreasuryPaymentApprovalResource;
use App\Http\Resources\TreasuryPaymentSubmissionDetailResource;
use App\Http\Resources\TreasuryPaymentSubmissionHistoryResource;
use App\Http\Resources\TreasuryPaymentSubmissionListResource;
use App\Models\PaymentSubmission;
use App\Services\TreasuryPaymentApprovalService;
use App\Services\TreasuryPaymentReviewService;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class TreasuryPaymentSubmissionController extends Controller
{
    public function __construct(private TreasuryPaymentReviewService $reviews, private TreasuryPaymentApprovalService $approvals) {}

    public function index(TreasuryPaymentSubmissionIndexRequest $request)
    {
        $data = $request->validated();
        $query = PaymentSubmission::query()->with([
            'order:id,user_id,reserved_until',
            'order.user:id,name,email',
            'order.reservations:id,order_id,status,expires_at',
        ]);

        if (! empty($data['search'])) {
            $term = trim($data['search']);
            $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper($term));
            $orderId = preg_replace('/^0+/', '', ltrim($term, '#'));
            if (($orderId !== '' && ctype_digit($orderId)) || $normalized !== '') {
                $query->where(function ($subQuery) use ($normalized, $orderId): void {
                    if ($orderId !== '' && ctype_digit($orderId)) {
                        $subQuery->orWhere('order_id', (int) $orderId);
                    }
                    if ($normalized !== '') {
                        $subQuery->orWhere('normalized_operation_number', 'like', '%'.$normalized.'%');
                    }
                });
            } else {
                $query->where('id', 0);
            }
        }

        foreach (['status', 'channel'] as $filter) {
            if (isset($data[$filter])) {
                $query->where($filter, $data[$filter]);
            }
        }
        if (isset($data['receiving_account_id'])) {
            $accountId = (int) $data['receiving_account_id'];
            if (DB::connection()->getDriverName() === 'sqlite') {
                $query->whereRaw("json_extract(receiving_account_snapshot, '$.id') = ?", [$accountId]);
            } else {
                $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(receiving_account_snapshot, '$.id')) = ?", [(string) $accountId]);
            }
        }
        if (isset($data['date_from'])) {
            $query->where('submitted_at', '>=', Carbon::parse($data['date_from'])->startOfDay());
        }
        if (isset($data['date_to'])) {
            $query->where('submitted_at', '<=', Carbon::parse($data['date_to'])->endOfDay());
        }

        $submissions = $query
            ->orderByRaw('CASE status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [PaymentSubmission::PENDING_REVIEW, PaymentSubmission::OBSERVED])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->paginate($data['per_page'] ?? 20)
            ->withQueryString();

        return TreasuryPaymentSubmissionListResource::collection($submissions)
            ->response()
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    public function show(string $submission)
    {
        $model = $this->submission($submission, ['order.user', 'order.reservations', 'reviewer', 'histories.actor', 'paymentTransaction.confirmer']);

        return (new TreasuryPaymentSubmissionDetailResource($model))
            ->response()
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    public function history(string $submission)
    {
        $model = $this->submission($submission, ['histories.actor']);

        return TreasuryPaymentSubmissionHistoryResource::collection($model->histories)
            ->response()
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    public function observe(TreasuryPaymentSubmissionDecisionRequest $request, string $submission)
    {
        $model = $this->submission($submission);
        $updated = $this->reviews->observe($model->id, $request->user()->id, $request->validated('reason'));

        return (new TreasuryPaymentSubmissionDetailResource($updated))
            ->response()
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    public function reject(TreasuryPaymentSubmissionDecisionRequest $request, string $submission)
    {
        $model = $this->submission($submission);
        $updated = $this->reviews->reject($model->id, $request->user()->id, $request->validated('reason'));

        return (new TreasuryPaymentSubmissionDetailResource($updated))
            ->response()
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    public function approve(ApproveTreasuryPaymentSubmissionRequest $request, string $submission)
    {
        $model = $this->submission($submission);
        $approved = $this->approvals->approve($model->id, $request->user()->id);

        return (new TreasuryPaymentApprovalResource($approved))
            ->response()
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    private function submission(string $id, array $relations = []): PaymentSubmission
    {
        $submission = PaymentSubmission::query()->with($relations)->whereKey($id)->first();
        if (! $submission) {
            throw new HttpResponseException(response()->json([
                'message' => 'La presentación de pago no existe.',
                'code' => 'payment_submission_not_found',
            ], 404)->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache'));
        }

        return $submission;
    }
}
