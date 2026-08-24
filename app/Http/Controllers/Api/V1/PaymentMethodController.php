<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PaymentSettingService;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    public function __construct(private PaymentSettingService $paymentSettings) {}

    public function index(Request $request)
    {
        $deliveryType = $request->query('delivery_type', 'delivery');
        if (! in_array($deliveryType, ['delivery', 'pickup'], true)) {
            $deliveryType = 'delivery';
        }

        return response()->json([
            'methods' => $this->paymentSettings->getPublicMethods($deliveryType),
            'delivery_type' => $deliveryType,
            'active_gateway' => $this->paymentSettings->getActiveGateway(),
            'is_mock' => $this->paymentSettings->isMockActive(),
        ]);
    }
}
