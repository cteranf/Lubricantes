<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BillingOrderResource;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class BillingOrderController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'receipt_type' => ['nullable', 'in:boleta,factura'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'missing_data' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = Order::query()
            ->select([
                'id', 'user_id', 'created_at', 'total', 'payment_status', 'requested_receipt_type',
                'requested_identity_type', 'requested_identity_number', 'requested_identity_required',
                'requested_recipient_name', 'requested_billing_address', 'requested_receipt_email',
            ])
            ->with('user:id,name');

        if (filled($filters['search'] ?? null)) {
            $term = trim($filters['search']);
            $digits = preg_replace('/\D+/', '', $term);
            $query->where(function ($nested) use ($term, $digits): void {
                if ($digits !== '') {
                    $nested->where('id', (int) $digits);
                }
                $nested->orWhereHas('user', fn ($user) => $user->where('name', 'like', '%'.$term.'%'));
            });
        }
        if (filled($filters['receipt_type'] ?? null)) {
            $query->where('requested_receipt_type', $filters['receipt_type']);
        }
        if (filled($filters['date_from'] ?? null)) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['date_from'])->startOfDay()->utc());
        }
        if (filled($filters['date_to'] ?? null)) {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['date_to'])->addDay()->startOfDay()->utc());
        }
        if ($request->boolean('missing_data')) {
            $query->where(function ($missing): void {
                $missing->whereNull('requested_receipt_type')
                    ->orWhereNull('requested_receipt_email')
                    ->orWhere(function ($identity): void {
                        $identity->where('requested_identity_required', true)->where(function ($values): void {
                            $values->whereNull('requested_identity_type')->orWhereNull('requested_identity_number')->orWhereNull('requested_recipient_name');
                        });
                    })
                    ->orWhere(function ($invoice): void {
                        $invoice->where('requested_receipt_type', 'factura')->whereNull('requested_billing_address');
                    });
            });
        }

        return BillingOrderResource::collection($query->orderByDesc('created_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 20)->withQueryString());
    }

    public function show(int $id): BillingOrderResource
    {
        $order = Order::query()
            ->select([
                'id', 'user_id', 'created_at', 'total', 'payment_status', 'requested_receipt_type',
                'requested_identity_type', 'requested_identity_number', 'requested_identity_required',
                'requested_recipient_name', 'requested_billing_address', 'requested_receipt_email',
            ])
            ->with(['user:id,name'])
            ->findOrFail($id);
        $order->load(['items:id,order_id,product_name,quantity,regular_price,price,subtotal']);

        return new BillingOrderResource($order);
    }
}
