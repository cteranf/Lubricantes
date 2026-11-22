<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\ElectronicDocument;
use App\Models\ElectronicDocumentAttempt;
use App\Models\ElectronicDocumentEvent;
use App\Models\ElectronicInvoiceSetting;
use App\Models\Order;
use App\Models\User;
use App\Support\AuditEventContract;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ElectronicDocumentService
{
    public function __construct(private AuditService $audit, private MoneyService $money) {}

    public function enabled(): bool
    {
        return (bool) config('electronic_invoicing.enabled', false);
    }

    public function settings(): array
    {
        $setting = ElectronicInvoiceSetting::query()->where('singleton_key', 'primary')->first();

        return [
            'connection_type' => $setting?->connection_type ?? 'direct_sunat',
            'provider_name' => $setting?->provider_name,
            'environment' => $setting?->environment ?? 'testing',
            'version' => (int) ($setting?->version ?? 1),
        ];
    }

    public function updateSettings(array $input, User $actor): ElectronicInvoiceSetting
    {
        $model = new ElectronicInvoiceSetting;
        $connection = DB::connection($model->getConnectionName());
        $changed = [];

        return $connection->transaction(function () use ($input, $actor, &$changed): ElectronicInvoiceSetting {
            ElectronicInvoiceSetting::query()->insertOrIgnore([
                'singleton_key' => 'primary', 'connection_type' => 'direct_sunat',
                'environment' => 'testing', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $locked = ElectronicInvoiceSetting::query()->where('singleton_key', 'primary')->lockForUpdate()->firstOrFail();
            $changed = [];
            foreach (['connection_type', 'provider_name', 'environment'] as $field) {
                if (($locked->{$field} ?? null) !== ($input[$field] ?? null)) {
                    $changed[] = $field;
                }
            }
            if (ElectronicDocumentAttempt::query()->whereIn('status', ['queued', 'processing', 'submitted', 'uncertain'])->exists()
                && (in_array('connection_type', $changed, true) || in_array('environment', $changed, true))) {
                abort(409, 'No se puede cambiar la conexión o el entorno mientras haya una operación con resultado incierto.');
            }
            $locked->fill($input);
            $locked->version++;
            $locked->save();
            if ($changed !== []) {
                $this->audit->recordUser(
                    AuditEventContract::ELECTRONIC_INVOICE_SETTINGS_UPDATED,
                    $actor,
                    AuditEventContract::RESULT_SUCCEEDED,
                    auditableType: 'electronic_invoice_settings',
                    auditableId: 'primary',
                    metadata: ['changed_fields' => implode(',', $changed)]
                );
            }

            return $locked;
        });
    }

    public function prepare(Order $candidate, User $actor, string $preparationKey): ElectronicDocument
    {
        abort_unless($this->enabled(), 409, 'La facturación electrónica no está habilitada comercialmente.');

        $connection = DB::connection($candidate->getConnectionName());
        try {
            return $connection->transaction(function () use ($candidate, $actor, $preparationKey): ElectronicDocument {
                $order = Order::query()->whereKey($candidate->getKey())->lockForUpdate()->firstOrFail();
                $existing = ElectronicDocument::query()->where('order_id', $order->id)->where('preparation_key', $preparationKey)->first();
                if ($existing) {
                    return $existing->load(['events.actor:id,name']);
                }

                $profile = BusinessProfile::query()->where('singleton_key', BusinessProfile::SINGLETON_KEY)->first();
                abort_unless(in_array($order->requested_receipt_type, ['boleta', 'factura'], true), 422, 'El pedido no tiene una solicitud de comprobante boleta o factura que pueda preparar.');
                $setting = $this->settings();
                $items = $order->items()->orderBy('id')->get(['id', 'order_id', 'product_name', 'quantity', 'regular_price', 'price', 'subtotal', 'tax_snapshot']);
                $seller = [
                    'commercial_name' => $profile?->commercial_name,
                    'legal_name' => $profile?->legal_name,
                    'tax_id_type' => $profile?->tax_id_type,
                    'tax_id_number' => $profile?->tax_id_number,
                    'country_code' => $profile?->country_code,
                    'fiscal_address' => $profile?->fiscal_address,
                ];
                $recipient = [
                    'document_type' => $order->requested_identity_type,
                    'document_number' => $order->requested_identity_number,
                    'name' => $order->requested_recipient_name,
                    'billing_address' => $order->requested_billing_address,
                    'email' => $order->requested_receipt_email,
                ];
                $lines = $items->map(fn ($item): array => [
                    'name' => $item->product_name,
                    'quantity' => (int) $item->quantity,
                    'regular_unit_price' => $this->moneyValue($item->regular_price),
                    'unit_price' => $this->moneyValue($item->price),
                    'subtotal' => $this->moneyValue($item->subtotal),
                    'tax' => $item->tax_snapshot,
                    'source' => 'order_item_snapshot',
                ])->all();
                $taxStatus = $order->tax_calculation_status ?: 'pending_configuration';
                $missing = $this->missingFields($seller, $recipient, $lines, $taxStatus, $order->tax_snapshot);

                $document = ElectronicDocument::query()->create([
                    'order_id' => $order->id,
                    'draft_reference' => (string) Str::uuid(),
                    'preparation_key' => $preparationKey,
                    'document_type' => $order->requested_receipt_type,
                    'status' => 'draft',
                    'environment' => $setting['environment'],
                    'connection_type' => $setting['connection_type'],
                    'seller_snapshot' => $seller + ['source' => 'current_business_profile'],
                    'recipient_snapshot' => $recipient + ['source' => 'order_checkout_snapshot'],
                    'lines_snapshot' => ['items' => $lines, 'source' => 'order_item_snapshots'],
                    'amounts_snapshot' => [
                        'subtotal' => $this->moneyValue($order->subtotal),
                        'discount_total' => $this->moneyValue($order->discount_total),
                        'shipping_amount' => $this->moneyValue($order->shipping_amount),
                        'total' => $this->moneyValue($order->total),
                        'currency' => config('payment.currency', 'PEN'),
                        'operation_type' => '0101',
                        'payment_terms' => [
                            'type' => $order->payment_status === 'approved' ? 'cash' : 'unsupported',
                            'amount' => $this->moneyValue($order->total),
                            'source' => 'order_payment_status',
                        ],
                        'source' => 'order_snapshot',
                        'missing_fields' => $missing,
                        'tax_calculation_status' => $taxStatus,
                        'tax_snapshot' => $order->tax_snapshot,
                    ],
                    'currency' => config('payment.currency', 'PEN'),
                    'tax_calculation_status' => $taxStatus,
                    'version' => 1,
                    'prepared_by' => $actor->id,
                    'last_modified_by' => $actor->id,
                    'prepared_at' => now(),
                ]);
                $this->event($document, $actor, 'prepared', null, 1, []);
                $this->audit->recordUser(
                    AuditEventContract::ELECTRONIC_DOCUMENT_PREPARED,
                    $actor,
                    AuditEventContract::RESULT_SUCCEEDED,
                    auditableType: 'electronic_document',
                    auditableId: (string) $document->id,
                    metadata: ['changed_fields' => 'document_type']
                );

                return $document->load(['events.actor:id,name']);
            });
        } catch (QueryException $exception) {
            $existing = ElectronicDocument::query()->where('order_id', $candidate->id)->where('preparation_key', $preparationKey)->first();
            if ($existing) {
                return $existing->load(['events.actor:id,name']);
            }
            throw $exception;
        }
    }

    public function updateDraft(ElectronicDocument $candidate, User $actor, int $expectedVersion, array $seller, array $recipient): ElectronicDocument
    {
        abort_unless($this->enabled(), 409, 'La facturación electrónica no está habilitada comercialmente.');

        $connection = DB::connection($candidate->getConnectionName());

        return $connection->transaction(function () use ($candidate, $actor, $expectedVersion, $seller, $recipient): ElectronicDocument {
            $document = ElectronicDocument::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            abort_if($document->status !== 'draft' || $document->attempts()->exists(), 409, 'Este documento ya no admite cambios.');
            abort_if($document->version !== $expectedVersion, 409, 'El borrador cambió. Carga la versión vigente antes de editar.');
            $oldVersion = $document->version;
            $allowedSeller = ['commercial_name', 'legal_name', 'tax_id_type', 'tax_id_number', 'country_code', 'fiscal_address'];
            $allowedRecipient = ['document_type', 'document_number', 'name', 'billing_address', 'email'];
            $changed = [];
            $sellerSnapshot = $document->seller_snapshot;
            $recipientSnapshot = $document->recipient_snapshot;
            foreach (array_intersect(array_keys($seller), $allowedSeller) as $field) {
                if (($sellerSnapshot[$field] ?? null) !== $seller[$field]) {
                    $changed[] = 'seller.'.$field;
                }
                $sellerSnapshot[$field] = $seller[$field];
            }
            foreach (array_intersect(array_keys($recipient), $allowedRecipient) as $field) {
                if (($recipientSnapshot[$field] ?? null) !== $recipient[$field]) {
                    $changed[] = 'recipient.'.$field;
                }
                $recipientSnapshot[$field] = $recipient[$field];
            }
            if ($changed === []) {
                return $document->load(['events.actor:id,name']);
            }
            $document->seller_snapshot = $sellerSnapshot;
            $document->recipient_snapshot = $recipientSnapshot;
            $amountsSnapshot = $document->amounts_snapshot;
            $amountsSnapshot['missing_fields'] = $this->missingFields(
                $sellerSnapshot,
                $recipientSnapshot,
                $document->lines_snapshot['items'] ?? [],
                $document->tax_calculation_status,
                $amountsSnapshot['tax_snapshot'] ?? null
            );
            $document->amounts_snapshot = $amountsSnapshot;
            $document->version++;
            $document->last_modified_by = $actor->id;
            $document->last_modified_at = now();
            $document->save();
            $this->event($document, $actor, 'updated', $oldVersion, $document->version, $changed);
            $safeChangedFields = array_values(array_unique(array_map(static fn (string $field): string => substr($field, strpos($field, '.') + 1), $changed)));
            if ($safeChangedFields !== []) {
                $this->audit->recordUser(
                    AuditEventContract::ELECTRONIC_DOCUMENT_UPDATED,
                    $actor,
                    AuditEventContract::RESULT_SUCCEEDED,
                    auditableType: 'electronic_document',
                    auditableId: (string) $document->id,
                    metadata: ['changed_fields' => implode(',', $safeChangedFields)]
                );
            }

            return $document->load(['events.actor:id,name']);
        });
    }

    private function event(ElectronicDocument $document, User $actor, string $type, ?int $from, int $to, array $fields): void
    {
        ElectronicDocumentEvent::query()->create([
            'electronic_document_id' => $document->id,
            'actor_id' => $actor->id,
            'event_type' => $type,
            'version_from' => $from,
            'version_to' => $to,
            'changed_fields' => $fields,
            'created_at' => now(),
        ]);
    }

    private function missingFields(array $seller, array $recipient, array $lines, ?string $taxStatus = null, ?array $taxSnapshot = null): array
    {
        $missing = [];
        foreach ($seller as $field => $value) {
            if ($field !== 'source' && blank($value)) {
                $missing[] = 'seller.'.$field;
            }
        }
        foreach ($recipient as $field => $value) {
            if ($field !== 'source' && blank($value) && in_array($field, ['document_type', 'document_number', 'name', 'email'], true)) {
                $missing[] = 'recipient.'.$field;
            }
        }
        if ($lines === []) {
            $missing[] = 'order.items';
        }
        foreach ($lines as $index => $line) {
            if ($line['name'] === null) {
                $missing[] = 'lines.'.$index.'.name';
            }
            if ($line['regular_unit_price'] === null || $line['unit_price'] === null || $line['subtotal'] === null) {
                $missing[] = 'lines.'.$index.'.amount';
            }
            if (($line['tax']['calculation_status'] ?? null) !== 'complete') {
                foreach ($line['tax']['missing_fields'] ?? ['tax_calculation_snapshot'] as $field) {
                    $missing[] = 'lines.'.$index.'.'.$field;
                }
            }
        }
        if ($taxStatus !== 'complete') {
            foreach ($taxSnapshot['missing_fields'] ?? ['tax_calculation_'.$taxStatus] as $field) {
                $missing[] = 'tax.'.$field;
            }
        }

        return array_values(array_unique($missing));
    }

    private function moneyValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->money->fromMinor($this->money->toMinor((string) $value));
    }
}
