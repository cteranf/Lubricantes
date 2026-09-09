<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReplacePaymentReceivingAccountQrRequest;
use App\Http\Requests\Admin\StorePaymentReceivingAccountRequest;
use App\Http\Requests\Admin\UpdatePaymentReceivingAccountRequest;
use App\Http\Requests\Admin\UpdatePaymentReceivingAccountStatusRequest;
use App\Http\Resources\PaymentReceivingAccountResource;
use App\Models\PaymentReceivingAccount;
use App\Services\TreasuryReceivingAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PaymentReceivingAccountController extends Controller
{
    public function __construct(private TreasuryReceivingAccountService $service) {}

    public function index(Request $request)
    {
        $data = $request->validate(['channel' => ['nullable', 'in:yape,plin,bank_transfer'], 'is_active' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $q = PaymentReceivingAccount::query()->select(['id', 'code', 'channel', 'display_name', 'holder_name', 'currency', 'phone', 'bank_name', 'account_number', 'cci', 'qr_path', 'qr_disk', 'is_active', 'is_default', 'sort_order', 'created_at', 'updated_at']);
        if (isset($data['channel'])) {
            $q->where('channel', $data['channel']);
        } if (array_key_exists('is_active', $data)) {
            $q->where('is_active', $data['is_active']);
        }

        return PaymentReceivingAccountResource::collection($q->orderBy('channel')->orderBy('sort_order')->paginate($data['per_page'] ?? 20));
    }

    public function store(StorePaymentReceivingAccountRequest $request)
    {
        $data = $request->safe()->except('qr');
        $path = null;
        if ($request->hasFile('qr')) {
            $data['qr_disk'] = config('treasury.qr_disk');
            $path = Storage::disk($data['qr_disk'])->putFile('receiving-accounts', $request->file('qr'));
            $data['qr_path'] = $path;
        }
        try {
            return (new PaymentReceivingAccountResource($this->service->create($data, $request->user())))->response()->setStatusCode(201);
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk($data['qr_disk'])->delete($path);
            }
            throw $exception;
        }
    }

    public function show(PaymentReceivingAccount $account)
    {
        return new PaymentReceivingAccountResource($account);
    }

    public function update(UpdatePaymentReceivingAccountRequest $request, PaymentReceivingAccount $account)
    {
        return new PaymentReceivingAccountResource($this->service->update($account, $request->validated(), $request->user()));
    }

    public function status(UpdatePaymentReceivingAccountStatusRequest $request, PaymentReceivingAccount $account)
    {
        return new PaymentReceivingAccountResource($this->service->changeStatus($account, $request->boolean('is_active'), $request->user()));
    }

    public function replaceQr(ReplacePaymentReceivingAccountQrRequest $request, PaymentReceivingAccount $account)
    {
        return new PaymentReceivingAccountResource($this->service->replaceQr($account, $request->file('qr'), $request->user()));
    }

    public function qr(PaymentReceivingAccount $account)
    {
        abort_unless($account->qr_path && $account->qr_disk, 404);
        abort_unless(Storage::disk($account->qr_disk)->exists($account->qr_path), 404);

        return Storage::disk($account->qr_disk)->response($account->qr_path, null, ['X-Content-Type-Options' => 'nosniff']);
    }
}
