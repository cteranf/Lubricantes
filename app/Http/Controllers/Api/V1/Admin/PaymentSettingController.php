<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\PaymentSettingService;
use Illuminate\Http\Request;

class PaymentSettingController extends Controller
{
    public function __construct(private PaymentSettingService $paymentSettings) {}

    public function show()
    {
        return response()->json($this->paymentSettings->getAdminSettings());
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'card_enabled' => 'nullable|boolean',
            'card_gateway' => 'nullable|string|in:mock,mercadopago',
            'card_title' => 'nullable|string|max:100',
            'card_description' => 'nullable|string|max:255',
            'mercadopago_public_key' => 'nullable|string|max:255',
            'mercadopago_access_token' => 'nullable|string|max:500',
            'mercadopago_sandbox' => 'nullable|boolean',

            'transfer_enabled' => 'nullable|boolean',
            'transfer_title' => 'nullable|string|max:100',
            'transfer_description' => 'nullable|string|max:255',
            'transfer_instructions' => 'nullable|string|max:2000',
            'transfer_bank_name' => 'nullable|string|max:100',
            'transfer_account_number' => 'nullable|string|max:100',
            'transfer_cci' => 'nullable|string|max:100',
            'transfer_account_holder' => 'nullable|string|max:150',
            'transfer_yape_phone' => 'nullable|string|max:30',
            'transfer_plin_phone' => 'nullable|string|max:30',

            'cash_on_delivery_enabled' => 'nullable|boolean',
            'cash_on_delivery_title' => 'nullable|string|max:100',
            'cash_on_delivery_description' => 'nullable|string|max:255',
            'cash_on_delivery_instructions' => 'nullable|string|max:2000',

            'cash_at_pickup_enabled' => 'nullable|boolean',
            'cash_at_pickup_title' => 'nullable|string|max:100',
            'cash_at_pickup_description' => 'nullable|string|max:255',
            'cash_at_pickup_instructions' => 'nullable|string|max:2000',
        ]);

        $this->paymentSettings->updateSettings($validated, $request->user());

        return response()->json([
            'message' => 'Configuración de pagos actualizada exitosamente.',
            'settings' => $this->paymentSettings->getAdminSettings(),
        ]);
    }
}
