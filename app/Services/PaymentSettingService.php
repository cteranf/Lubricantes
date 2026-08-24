<?php

namespace App\Services;

use App\Models\PaymentSetting;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PaymentSettingService
{
    public function current(): PaymentSetting
    {
        return PaymentSetting::current();
    }

    public function isMockActive(): bool
    {
        if (! app()->environment(['local', 'testing'])) {
            return false;
        }

        return (bool) config('payment.mock.enabled', false);
    }

    public function getActiveGateway(): string
    {
        $setting = $this->current();
        $configuredGateway = $setting->card_gateway ?: config('payment.default_gateway', 'mock');

        if ($configuredGateway === 'mock') {
            if ($this->isMockActive()) {
                return 'mock';
            }

            return 'mercadopago';
        }

        return $configuredGateway;
    }

    public function isMethodAllowed(string $method, string $deliveryType): bool
    {
        return $this->current()->isMethodCompatibleWithFulfillment($method, $deliveryType);
    }

    public function getPublicMethods(string $deliveryType = 'delivery'): array
    {
        $setting = $this->current();
        $methods = [];
        $isSimulated = $this->getActiveGateway() === 'mock';

        // 1. Card (Online Gateway)
        if ($setting->card_enabled && in_array($deliveryType, ['delivery', 'pickup'], true)) {
            $methods[] = [
                'value' => 'card',
                'label' => $setting->card_title ?: 'Tarjeta de crédito o débito',
                'description' => $setting->card_description ?: 'Pago seguro en línea con tarjeta Visa o Mastercard',
                'gateway' => $this->getActiveGateway(),
                'is_simulated' => $isSimulated,
                'badge' => $isSimulated ? 'Simulador Visa Demo' : null,
            ];
        }

        // 2. Bank Transfer / Digital Wallets
        if ($setting->transfer_enabled && in_array($deliveryType, ['delivery', 'pickup'], true)) {
            $bankAccounts = [];
            if ($setting->transfer_bank_name || $setting->transfer_account_number) {
                $bankAccounts[] = [
                    'bank' => $setting->transfer_bank_name,
                    'account' => $setting->transfer_account_number,
                    'cci' => $setting->transfer_cci,
                    'holder' => $setting->transfer_account_holder,
                ];
            }

            $wallets = [];
            if ($setting->transfer_yape_phone) {
                $wallets[] = [
                    'type' => 'Yape',
                    'phone' => $setting->transfer_yape_phone,
                    'holder' => $setting->transfer_account_holder,
                ];
            }
            if ($setting->transfer_plin_phone) {
                $wallets[] = [
                    'type' => 'Plin',
                    'phone' => $setting->transfer_plin_phone,
                    'holder' => $setting->transfer_account_holder,
                ];
            }

            $methods[] = [
                'value' => 'transferencia',
                'label' => $setting->transfer_title ?: 'Transferencia bancaria / Yape / Plin',
                'description' => $setting->transfer_description ?: 'Paga mediante transferencia o billetera digital',
                'instructions' => $setting->transfer_instructions ?: 'Realiza el pago y envía tu constancia por WhatsApp indicando el número de pedido.',
                'bank_accounts' => $bankAccounts,
                'wallets' => $wallets,
            ];
        }

        // 3. Cash on Delivery (Delivery only)
        if ($setting->cash_on_delivery_enabled && $deliveryType === 'delivery') {
            $methods[] = [
                'value' => 'contra_entrega',
                'label' => $setting->cash_on_delivery_title ?: 'Pago contra entrega',
                'description' => $setting->cash_on_delivery_description ?: 'Paga en efectivo al recibir tu pedido en tu domicilio',
                'instructions' => $setting->cash_on_delivery_instructions ?: 'Ten el monto exacto en efectivo al momento de la entrega.',
            ];
        }

        // 4. Cash at Pickup (Pickup only)
        if ($setting->cash_at_pickup_enabled && $deliveryType === 'pickup') {
            $methods[] = [
                'value' => 'pago_en_sede',
                'label' => $setting->cash_at_pickup_title ?: 'Pago al recoger en sede',
                'description' => $setting->cash_at_pickup_description ?: 'Paga en efectivo o POS al retirar tus productos en la sede',
                'instructions' => $setting->cash_at_pickup_instructions ?: 'Podrás pagar en caja de la sede mediante efectivo, tarjeta o transferencia.',
            ];
        }

        return $methods;
    }

    public function getAdminSettings(): array
    {
        $setting = $this->current();
        $hasToken = ! empty($setting->getRawOriginal('mercadopago_access_token'));

        return [
            'card_enabled' => (bool) $setting->card_enabled,
            'card_gateway' => $setting->card_gateway ?: 'mock',
            'card_title' => $setting->card_title,
            'card_description' => $setting->card_description,
            'mercadopago_public_key' => $setting->mercadopago_public_key,
            'mercadopago_access_token_masked' => $hasToken ? 'APP_USR-••••••••••••••••' : '',
            'has_mercadopago_access_token' => $hasToken,
            'mercadopago_sandbox' => (bool) $setting->mercadopago_sandbox,
            'transfer_enabled' => (bool) $setting->transfer_enabled,
            'transfer_title' => $setting->transfer_title,
            'transfer_description' => $setting->transfer_description,
            'transfer_instructions' => $setting->transfer_instructions,
            'transfer_bank_name' => $setting->transfer_bank_name,
            'transfer_account_number' => $setting->transfer_account_number,
            'transfer_cci' => $setting->transfer_cci,
            'transfer_account_holder' => $setting->transfer_account_holder,
            'transfer_yape_phone' => $setting->transfer_yape_phone,
            'transfer_plin_phone' => $setting->transfer_plin_phone,
            'cash_on_delivery_enabled' => (bool) $setting->cash_on_delivery_enabled,
            'cash_on_delivery_title' => $setting->cash_on_delivery_title,
            'cash_on_delivery_description' => $setting->cash_on_delivery_description,
            'cash_on_delivery_instructions' => $setting->cash_on_delivery_instructions,
            'cash_at_pickup_enabled' => (bool) $setting->cash_at_pickup_enabled,
            'cash_at_pickup_title' => $setting->cash_at_pickup_title,
            'cash_at_pickup_description' => $setting->cash_at_pickup_description,
            'cash_at_pickup_instructions' => $setting->cash_at_pickup_instructions,
            'mock_environment_allowed' => $this->isMockActive(),
            'active_gateway' => $this->getActiveGateway(),
        ];
    }

    public function updateSettings(array $data, ?User $admin = null): PaymentSetting
    {
        $setting = $this->current();

        $cardEnabled = isset($data['card_enabled']) ? (bool) $data['card_enabled'] : $setting->card_enabled;
        $transferEnabled = isset($data['transfer_enabled']) ? (bool) $data['transfer_enabled'] : $setting->transfer_enabled;
        $cashOnDeliveryEnabled = isset($data['cash_on_delivery_enabled']) ? (bool) $data['cash_on_delivery_enabled'] : $setting->cash_on_delivery_enabled;
        $cashAtPickupEnabled = isset($data['cash_at_pickup_enabled']) ? (bool) $data['cash_at_pickup_enabled'] : $setting->cash_at_pickup_enabled;

        if (! $cardEnabled && ! $transferEnabled && ! $cashOnDeliveryEnabled && ! $cashAtPickupEnabled) {
            throw ValidationException::withMessages([
                'payment_settings' => ['Al menos un método de pago debe permanecer habilitado en el sistema.'],
            ]);
        }

        $fieldsToUpdate = [
            'card_enabled' => $cardEnabled,
            'card_gateway' => $data['card_gateway'] ?? $setting->card_gateway,
            'card_title' => $data['card_title'] ?? $setting->card_title,
            'card_description' => $data['card_description'] ?? $setting->card_description,
            'mercadopago_public_key' => array_key_exists('mercadopago_public_key', $data) ? $data['mercadopago_public_key'] : $setting->mercadopago_public_key,
            'mercadopago_sandbox' => isset($data['mercadopago_sandbox']) ? (bool) $data['mercadopago_sandbox'] : $setting->mercadopago_sandbox,
            'transfer_enabled' => $transferEnabled,
            'transfer_title' => $data['transfer_title'] ?? $setting->transfer_title,
            'transfer_description' => $data['transfer_description'] ?? $setting->transfer_description,
            'transfer_instructions' => array_key_exists('transfer_instructions', $data) ? $data['transfer_instructions'] : $setting->transfer_instructions,
            'transfer_bank_name' => array_key_exists('transfer_bank_name', $data) ? $data['transfer_bank_name'] : $setting->transfer_bank_name,
            'transfer_account_number' => array_key_exists('transfer_account_number', $data) ? $data['transfer_account_number'] : $setting->transfer_account_number,
            'transfer_cci' => array_key_exists('transfer_cci', $data) ? $data['transfer_cci'] : $setting->transfer_cci,
            'transfer_account_holder' => array_key_exists('transfer_account_holder', $data) ? $data['transfer_account_holder'] : $setting->transfer_account_holder,
            'transfer_yape_phone' => array_key_exists('transfer_yape_phone', $data) ? $data['transfer_yape_phone'] : $setting->transfer_yape_phone,
            'transfer_plin_phone' => array_key_exists('transfer_plin_phone', $data) ? $data['transfer_plin_phone'] : $setting->transfer_plin_phone,
            'cash_on_delivery_enabled' => $cashOnDeliveryEnabled,
            'cash_on_delivery_title' => $data['cash_on_delivery_title'] ?? $setting->cash_on_delivery_title,
            'cash_on_delivery_description' => $data['cash_on_delivery_description'] ?? $setting->cash_on_delivery_description,
            'cash_on_delivery_instructions' => array_key_exists('cash_on_delivery_instructions', $data) ? $data['cash_on_delivery_instructions'] : $setting->cash_on_delivery_instructions,
            'cash_at_pickup_enabled' => $cashAtPickupEnabled,
            'cash_at_pickup_title' => $data['cash_at_pickup_title'] ?? $setting->cash_at_pickup_title,
            'cash_at_pickup_description' => $data['cash_at_pickup_description'] ?? $setting->cash_at_pickup_description,
            'cash_at_pickup_instructions' => array_key_exists('cash_at_pickup_instructions', $data) ? $data['cash_at_pickup_instructions'] : $setting->cash_at_pickup_instructions,
        ];

        // Handle access token securely:
        // If the submitted token is non-empty and is NOT the masked placeholder, encrypt and save it.
        // If it's empty, null, or masked, keep the existing encrypted token in the database.
        if (array_key_exists('mercadopago_access_token', $data)) {
            $newToken = trim((string) $data['mercadopago_access_token']);
            if ($newToken !== '' && ! str_contains($newToken, '••••') && ! str_contains($newToken, '***')) {
                $fieldsToUpdate['mercadopago_access_token'] = $newToken;
            }
        }

        $setting->update($fieldsToUpdate);

        return $setting->fresh();
    }
}
