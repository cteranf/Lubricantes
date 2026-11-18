<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerPaymentOptionResource;
use App\Models\Order;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSubmission;
use App\Services\CustomerTransferPaymentOptionService;
use App\Support\TransferPaymentInstallments;
use Illuminate\Support\Facades\Storage;

class CustomerTransferPaymentOptionController extends Controller
{
    public function __construct(private CustomerTransferPaymentOptionService $options) {}

    public function index(Order $order)
    {
        $this->options->eligibleOrder($order, request()->user()->id);

        $submissions = PaymentSubmission::query()->where('order_id', $order->id)->orderBy('id')->get();
        $accounting = TransferPaymentInstallments::summary($order, $submissions);
        if ($accounting['requires_manual_reconciliation']) {
            return response()->json([
                'message' => 'Hay un abono reportado que requiere revisión de Tesorería. No realices otra transferencia hasta recibir indicaciones.',
                'code' => 'payment_reconciliation_required',
            ], 409)->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
        }
        $hasObservedSubmission = $submissions->contains('status', PaymentSubmission::OBSERVED);
        if (($accounting['slots_left'] <= 0 || TransferPaymentInstallments::cents($accounting['remaining_amount']) <= 0) && ! $hasObservedSubmission) {
            return response()->json([
                'message' => 'No queda saldo o cupo disponible para registrar otro abono.',
                'code' => 'payment_installments_complete',
            ], 422)->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
        }

        return CustomerPaymentOptionResource::collection($this->options->options($order))
            ->additional(['accounting' => $accounting])
            ->response()
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    public function qr(Order $order, PaymentReceivingAccount $account)
    {
        $this->options->eligibleOrder($order, request()->user()->id);
        $this->options->account($order, $account);
        abort_unless(Storage::disk($account->qr_disk)->exists($account->qr_path), 404);
        $mime = Storage::disk($account->qr_disk)->mimeType($account->qr_path);
        abort_unless(in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true), 404);

        return Storage::disk($account->qr_disk)->response($account->qr_path, null, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }
}
