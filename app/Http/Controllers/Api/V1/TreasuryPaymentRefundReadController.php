<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PaymentRefundReadConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TreasuryPaymentRefundWorklistRequest;
use App\Http\Resources\TreasuryPaymentRefundWorklistResource;
use App\Services\TreasuryPaymentRefundWorklistService;
use Illuminate\Http\Exceptions\HttpResponseException;

class TreasuryPaymentRefundReadController extends Controller
{
    public function __construct(private TreasuryPaymentRefundWorklistService $worklist) {}

    public function index(TreasuryPaymentRefundWorklistRequest $request)
    {
        try {
            $page = $this->worklist->paginate($request->validated())->withQueryString();
        } catch (PaymentRefundReadConflictException) {
            return $this->conflict();
        }

        return TreasuryPaymentRefundWorklistResource::collection($page)
            ->response()->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    public function show(TreasuryPaymentRefundWorklistRequest $request, string $orderId)
    {
        $normalized = ltrim($orderId, '0');
        if (! ctype_digit($orderId) || $normalized === '' || (string) (int) $normalized !== $normalized) {
            $this->notFound();
        }
        try {
            $row = $this->worklist->forOrder((int) $normalized);
        } catch (PaymentRefundReadConflictException) {
            return $this->conflict();
        }
        if ($row === null) {
            $this->notFound();
        }

        return (new TreasuryPaymentRefundWorklistResource($row))->response()->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    private function notFound(): never
    {
        throw new HttpResponseException(response()->json(['message' => 'El recurso no existe.'], 404)
            ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache'));
    }

    private function conflict()
    {
        return response()->json(['message' => 'No se pudo obtener la lectura. Ajusta los filtros e inténtalo nuevamente.', 'code' => 'payment_refund_read_conflict'], 409)
            ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
