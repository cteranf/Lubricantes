<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerPaymentOptionResource;
use App\Models\Order;
use App\Models\PaymentReceivingAccount;
use App\Services\CustomerTransferPaymentOptionService;
use Illuminate\Support\Facades\Storage;

class CustomerTransferPaymentOptionController extends Controller
{
    public function __construct(private CustomerTransferPaymentOptionService $options) {}

    public function index(Order $order)
    {
        $this->options->eligibleOrder($order, request()->user()->id);

        return CustomerPaymentOptionResource::collection($this->options->options($order))
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
