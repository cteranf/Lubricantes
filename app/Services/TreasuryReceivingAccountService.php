<?php

namespace App\Services;

use App\Models\PaymentReceivingAccount;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TreasuryReceivingAccountService
{
    public function create(array $data, User $actor): PaymentReceivingAccount
    {
        return DB::transaction(function () use ($data, $actor) {
            $data = $this->prepare($data);
            $this->validateRules($data);
            $this->lockChannel($data['channel']);
            $this->clearOtherDefaults($data['channel'], (bool) ($data['is_default'] ?? false));

            return PaymentReceivingAccount::create($data + ['created_by' => $actor->id, 'updated_by' => $actor->id]);
        });
    }

    public function update(PaymentReceivingAccount $account, array $data, User $actor): PaymentReceivingAccount
    {
        return DB::transaction(function () use ($account, $data, $actor) {
            if (isset($data['code']) && $data['code'] !== $account->code) {
                $this->invalid('El código no puede modificarse.');
            }
            if (isset($data['channel']) && $data['channel'] !== $account->channel) {
                $this->invalid('El canal no puede modificarse.');
            }
            $data = $this->prepare($data + ['channel' => $account->channel], $account);
            $candidate = array_merge($account->only(['channel', 'phone', 'bank_name', 'account_number', 'cci', 'qr_path', 'is_active', 'is_default']), $data);
            $this->validateRules($candidate);
            $this->lockChannel($account->channel);
            $this->clearOtherDefaults($account->channel, (bool) ($data['is_default'] ?? $account->is_default), $account->id);
            $account->fill($data + ['updated_by' => $actor->id])->save();

            return $account->refresh();
        });
    }

    public function changeStatus(PaymentReceivingAccount $account, bool $active, User $actor): PaymentReceivingAccount
    {
        return DB::transaction(function () use ($account, $active, $actor) {
            $candidate = $account->only(['channel', 'phone', 'bank_name', 'account_number', 'cci', 'qr_path', 'is_default']);
            $candidate['is_active'] = $active;
            $candidate['is_default'] = $active ? $account->is_default : false;
            $this->validateRules($candidate);
            $this->lockChannel($account->channel);
            // Deactivating the default deliberately leaves no default. Promotion
            // would be an implicit commercial decision.
            $account->update(['is_active' => $active, 'is_default' => $active ? $account->is_default : false, 'updated_by' => $actor->id]);

            return $account->refresh();
        });
    }

    public function replaceQr(PaymentReceivingAccount $account, UploadedFile $file, User $actor): PaymentReceivingAccount
    {
        $disk = config('treasury.qr_disk');
        $newPath = null;
        $oldPath = $account->qr_path;
        $oldDisk = $account->qr_disk;
        try {
            $image = @getimagesize($file->getRealPath());
            $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$image['mime'] ?? ''] ?? null;
            if (! $extension) {
                $this->invalid('El QR debe ser una imagen PNG, JPEG o WebP válida.');
            }
            $newPath = Storage::disk($disk)->putFileAs('receiving-accounts', $file, Str::uuid().'.'.$extension);

            return DB::transaction(function () use ($account, $newPath, $disk, $actor) {
                $account->update(['qr_path' => $newPath, 'qr_disk' => $disk, 'updated_by' => $actor->id]);

                return $account->refresh();
            });
        } catch (\Throwable $exception) {
            if ($newPath) {
                Storage::disk($disk)->delete($newPath);
            }
            throw $exception;
        } finally {
            if ($newPath && $oldPath && $account->fresh()?->qr_path === $newPath) {
                Storage::disk($oldDisk ?: $disk)->delete($oldPath);
            }
        }
    }

    public function fingerprintIdentifier(PaymentReceivingAccount $account): string
    {
        return 'receiving-account:'.$account->code;
    }

    public function snapshot(PaymentReceivingAccount $account, string $channel): array
    {
        if (! $account->is_active || $account->channel !== $channel) {
            $this->invalid('La cuenta receptora no está disponible para este canal.');
        }

        return ['receiving_account_id' => $account->id, 'code' => $account->code, 'channel' => $account->channel, 'display_name' => $account->display_name, 'holder_name' => $account->holder_name, 'currency' => $account->currency, 'bank_name' => $account->bank_name, 'snapshot_version' => 1];
    }

    private function prepare(array $data, ?PaymentReceivingAccount $current = null): array
    {
        foreach (['phone', 'account_number', 'cci'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $data[$field] = preg_replace('/[^0-9]/', '', trim($data[$field]));
            }
        }
        if (isset($data['phone']) && str_starts_with($data['phone'], '51') && strlen($data['phone']) === 11) {
            $data['phone'] = substr($data['phone'], 2);
        }
        foreach (['code', 'display_name', 'holder_name', 'bank_name'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }
        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        return $data;
    }

    private function validateRules(array $data): void
    {
        if (! in_array($data['channel'], PaymentReceivingAccount::CHANNELS, true) || ($data['currency'] ?? 'PEN') !== 'PEN') {
            $this->invalid('Canal o moneda inválidos.');
        }
        $active = $data['is_active'] ?? true;
        if (($data['is_default'] ?? false) && ! $active) {
            $this->invalid('Una cuenta predeterminada debe estar activa.');
        }
        if (isset($data['code']) && ! preg_match('/^[A-Z0-9][A-Z0-9_-]{1,49}$/', $data['code'])) {
            $this->invalid('El código de cuenta no tiene un formato seguro.');
        }
        if (filled($data['cci'] ?? null) && ! preg_match('/^\d{20}$/', $data['cci'])) {
            $this->invalid('El CCI debe tener veinte dígitos.');
        }
        if (filled($data['account_number'] ?? null) && (strlen($data['account_number']) < config('treasury.bank_account_min_length') || strlen($data['account_number']) > config('treasury.bank_account_max_length'))) {
            $this->invalid('La cuenta bancaria tiene una longitud inválida.');
        }
        if (in_array($data['channel'], ['yape', 'plin'], true) && $active && (! preg_match('/^9\d{8}$/', (string) ($data['phone'] ?? '')) || ! filled($data['qr_path'] ?? null))) {
            $this->invalid('Yape y Plin activos requieren teléfono peruano y QR.');
        }
        if ($data['channel'] === 'bank_transfer' && $active && (! filled($data['bank_name'] ?? null) || (! filled($data['account_number'] ?? null) && ! filled($data['cci'] ?? null)))) {
            $this->invalid('La transferencia activa requiere banco y cuenta o CCI.');
        }
    }

    private function lockChannel(string $channel): void
    {
        PaymentReceivingAccount::where('channel', $channel)->lockForUpdate()->get();
    }

    private function clearOtherDefaults(string $channel, bool $default, ?int $except = null): void
    {
        if ($default) {
            PaymentReceivingAccount::where('channel', $channel)->where('is_default', true)->when($except, fn ($q) => $q->whereKeyNot($except))->update(['is_default' => false, 'active_default_channel' => null]);
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['payment_receiving_account' => [$message]]);
    }
}
