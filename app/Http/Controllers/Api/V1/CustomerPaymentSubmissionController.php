<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CorrectCustomerPaymentSubmissionRequest;
use App\Http\Requests\StoreCustomerPaymentSubmissionRequest;
use App\Http\Resources\CustomerPaymentSubmissionResource;
use App\Models\Order;
use App\Services\CustomerPaymentSubmissionCorrectionService;
use App\Services\CustomerPaymentSubmissionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerPaymentSubmissionController extends Controller
{
    public function __construct(private CustomerPaymentSubmissionService $submissions, private CustomerPaymentSubmissionCorrectionService $corrections) {}

    public function store(StoreCustomerPaymentSubmissionRequest $request, Order $order)
    {
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['La cabecera Idempotency-Key es obligatoria y no puede superar 100 caracteres.']]);
        }
        [$submission, $created] = $this->submissions->submit($order, $request->user()->id, $request->validated(), $idempotencyKey);

        return (new CustomerPaymentSubmissionResource($submission))
            ->response()
            ->setStatusCode($created ? 201 : 200)
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    public function show(Request $request, Order $order)
    {
        abort_unless((int) $order->user_id === (int) $request->user()->id, 404);
        $submission = \App\Models\PaymentSubmission::query()
            ->where('order_id', $order->id)
            ->where('submitted_by', $request->user()->id)
            ->first();

        if (! $submission) {
            return response()->json([
                'message' => 'Aún no se ha registrado una presentación de pago para este pedido.',
                'code' => 'payment_submission_not_found',
            ], 404)
                ->header('Cache-Control', 'no-store, private')
                ->header('Pragma', 'no-cache');
        }

        return (new CustomerPaymentSubmissionResource($submission))
            ->response()
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    public function correct(CorrectCustomerPaymentSubmissionRequest $request, Order $order)
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 100) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['La cabecera Idempotency-Key es obligatoria y no puede superar 100 caracteres.']]);
        }
        $submission = $this->corrections->correct($order, $request->user()->id, $request->validated(), $key);

        return (new CustomerPaymentSubmissionResource($submission))->response()
            ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
