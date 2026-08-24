<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'card_enabled',
        'card_gateway',
        'card_title',
        'card_description',
        'mercadopago_public_key',
        'mercadopago_access_token',
        'mercadopago_sandbox',
        'transfer_enabled',
        'transfer_title',
        'transfer_description',
        'transfer_instructions',
        'transfer_bank_name',
        'transfer_account_number',
        'transfer_cci',
        'transfer_account_holder',
        'transfer_yape_phone',
        'transfer_plin_phone',
        'cash_on_delivery_enabled',
        'cash_on_delivery_title',
        'cash_on_delivery_description',
        'cash_on_delivery_instructions',
        'cash_at_pickup_enabled',
        'cash_at_pickup_title',
        'cash_at_pickup_description',
        'cash_at_pickup_instructions',
    ];

    protected $casts = [
        'card_enabled' => 'boolean',
        'mercadopago_sandbox' => 'boolean',
        'mercadopago_access_token' => 'encrypted',
        'transfer_enabled' => 'boolean',
        'cash_on_delivery_enabled' => 'boolean',
        'cash_at_pickup_enabled' => 'boolean',
    ];

    protected $hidden = [
        'mercadopago_access_token',
    ];

    public static function current(): self
    {
        return static::firstOrCreate([], [
            'card_enabled' => true,
            'card_gateway' => 'mock',
            'card_title' => 'Tarjeta de crédito o débito',
            'card_description' => 'Pago seguro en línea mediante pasarela',
            'mercadopago_sandbox' => true,
            'transfer_enabled' => true,
            'transfer_title' => 'Transferencia bancaria / Yape / Plin',
            'transfer_description' => 'Paga mediante transferencia bancaria o billetera digital',
            'cash_on_delivery_enabled' => true,
            'cash_on_delivery_title' => 'Pago contra entrega',
            'cash_on_delivery_description' => 'Paga en efectivo al recibir tu pedido en tu domicilio',
            'cash_at_pickup_enabled' => true,
            'cash_at_pickup_title' => 'Pago al recoger en sede',
            'cash_at_pickup_description' => 'Paga en efectivo o POS al retirar tus productos en la sede seleccionada',
        ]);
    }

    public function isMethodEnabled(string $method): bool
    {
        return match ($method) {
            'card' => (bool) $this->card_enabled,
            'transferencia', 'transfer' => (bool) $this->transfer_enabled,
            'contra_entrega', 'cash_on_delivery' => (bool) $this->cash_on_delivery_enabled,
            'pago_en_sede', 'cash_at_pickup' => (bool) $this->cash_at_pickup_enabled,
            default => false,
        };
    }

    public function isMethodCompatibleWithFulfillment(string $method, string $deliveryType): bool
    {
        $normalizedMethod = match ($method) {
            'card' => 'card',
            'transferencia', 'transfer' => 'transferencia',
            'contra_entrega', 'cash_on_delivery' => 'contra_entrega',
            'pago_en_sede', 'cash_at_pickup' => 'pago_en_sede',
            default => null,
        };

        if ($normalizedMethod === null) {
            return false;
        }

        if (! $this->isMethodEnabled($normalizedMethod)) {
            return false;
        }

        if ($deliveryType === 'delivery') {
            return in_array($normalizedMethod, ['card', 'transferencia', 'contra_entrega'], true);
        }

        if ($deliveryType === 'pickup') {
            return in_array($normalizedMethod, ['card', 'transferencia', 'pago_en_sede'], true);
        }

        return false;
    }
}
