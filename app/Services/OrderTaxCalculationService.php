<?php

namespace App\Services;

use App\Models\BusinessTaxConfiguration;
use Illuminate\Validation\ValidationException;

class OrderTaxCalculationService
{
    public const AFFECTATIONS = [
        '10' => ['label' => 'Gravado: operación onerosa', 'kind' => 'taxable'],
        '20' => ['label' => 'Exonerado: operación onerosa', 'kind' => 'exempt'],
        '30' => ['label' => 'Inafecto: operación onerosa', 'kind' => 'unaffected'],
    ];

    public function __construct(private MoneyService $money) {}

    public function calculate(iterable $items, int $shippingMinor, int $discountMinor = 0, bool $lockConfiguration = false): array
    {
        $items = collect($items)->values();
        $configuration = $this->configuration($lockConfiguration);
        $configured = $configuration?->is_configured === true
            && $configuration->prices_include_tax === true
            && is_int($configuration->igv_rate_basis_points)
            && is_int($configuration->ipm_rate_basis_points)
            && $configuration->effective_from !== null
            && $configuration->effective_from->startOfDay()->lessThanOrEqualTo(now()->startOfDay());
        $rateVersion = $configuration?->version;
        $igvBps = $configured ? $configuration->igv_rate_basis_points : null;
        $ipmBps = $configured ? $configuration->ipm_rate_basis_points : null;
        $totalRateBps = $configured ? $igvBps + $ipmBps : null;
        $missing = [];
        $lineSnapshots = [];
        $totals = $this->emptyTotals();
        $affectationKinds = [];

        if (! $configured) {
            $missing[] = 'business_tax_configuration';
        }

        foreach ($items as $index => $item) {
            $product = data_get($item, 'product');
            $affectation = is_object($product) ? $product->tax_affectation : data_get($item, 'tax_affectation');
            $quantity = (int) data_get($item, 'quantity', 0);
            $appliedUnitMinor = $this->minor(data_get($item, 'price'));
            $regularUnitMinor = $this->minor(data_get($item, 'regular_price'));
            $grossMinor = $this->minor(data_get($item, 'subtotal'));
            if ($quantity < 1 || $appliedUnitMinor < 0 || $regularUnitMinor < 0 || $grossMinor !== $appliedUnitMinor * $quantity) {
                throw ValidationException::withMessages(['items' => ['Los importes guardados de una línea no coinciden con su precio y cantidad. No se ajustó ningún céntimo.']]);
            }

            $discountLineMinor = max(0, ($regularUnitMinor - $appliedUnitMinor) * $quantity);
            $lineMissing = [];
            $affectationInfo = self::AFFECTATIONS[$affectation] ?? null;
            if (! $affectationInfo) {
                $lineMissing[] = 'product_tax_affectation';
                $missing[] = 'items.'.$index.'.tax_affectation';
            }
            if (! $configured) {
                $lineMissing[] = 'business_tax_configuration';
            }

            $lineTax = ['base_minor' => null, 'tax_minor' => null, 'igv_minor' => null, 'ipm_minor' => null];
            if ($configured && $affectationInfo) {
                $affectationKinds[] = $affectationInfo['kind'];
                if ($affectationInfo['kind'] === 'taxable') {
                    $baseMinor = $this->roundRatio($grossMinor * 10000, 10000 + $totalRateBps);
                    $taxMinor = $grossMinor - $baseMinor;
                    $igvMinor = $this->roundRatio($baseMinor * $igvBps, 10000);
                    $lineTax = [
                        'base_minor' => $baseMinor,
                        'tax_minor' => $taxMinor,
                        'igv_minor' => $igvMinor,
                        'ipm_minor' => $taxMinor - $igvMinor,
                    ];
                    $totals['taxable_base_minor'] += $baseMinor;
                    $totals['igv_minor'] += $igvMinor;
                    $totals['ipm_minor'] += $taxMinor - $igvMinor;
                    $totals['tax_minor'] += $taxMinor;
                } else {
                    $lineTax = ['base_minor' => $grossMinor, 'tax_minor' => 0, 'igv_minor' => 0, 'ipm_minor' => 0];
                    $bucket = $affectationInfo['kind'] === 'exempt' ? 'exempt_base_minor' : 'unaffected_base_minor';
                    $totals[$bucket] += $grossMinor;
                }
            }

            $totals['gross_minor'] += $grossMinor;
            $totals['promotion_discount_minor'] += $discountLineMinor;
            $lineSnapshots[] = [
                'product_id' => (int) data_get($product, 'id', data_get($item, 'product_id', 0)),
                'calculation_status' => $lineMissing === [] ? 'complete' : 'pending_configuration',
                'tax_affectation' => $affectationInfo ? ['code' => $affectation, 'label' => $affectationInfo['label']] : null,
                'tax_rate_basis_points' => $affectationInfo && $affectationInfo['kind'] === 'taxable' ? $totalRateBps : 0,
                'igv_rate_basis_points' => $affectationInfo && $affectationInfo['kind'] === 'taxable' ? $igvBps : 0,
                'ipm_rate_basis_points' => $affectationInfo && $affectationInfo['kind'] === 'taxable' ? $ipmBps : 0,
                'quantity' => $quantity,
                'regular_unit_gross' => $this->money->fromMinor($regularUnitMinor),
                'applied_unit_gross' => $this->money->fromMinor($appliedUnitMinor),
                'promotion_discount_gross' => $this->money->fromMinor($discountLineMinor),
                'gross' => $this->money->fromMinor($grossMinor),
                'base' => $lineTax['base_minor'] === null ? null : $this->money->fromMinor($lineTax['base_minor']),
                'tax' => $lineTax['tax_minor'] === null ? null : $this->money->fromMinor($lineTax['tax_minor']),
                'igv' => $lineTax['igv_minor'] === null ? null : $this->money->fromMinor($lineTax['igv_minor']),
                'ipm' => $lineTax['ipm_minor'] === null ? null : $this->money->fromMinor($lineTax['ipm_minor']),
                'source' => ['price' => 'order_item.price', 'regular_price' => 'order_item.regular_price', 'subtotal' => 'order_item.subtotal', 'tax_affectation' => 'product.tax_affectation'],
                'missing_fields' => array_values(array_unique($lineMissing)),
            ];
        }

        if ($discountMinor !== 0) {
            $missing[] = 'global_discount_allocation';
        }

        $shippingSnapshot = $this->shippingSnapshot($shippingMinor, $affectationKinds, $configured, $igvBps, $ipmBps, $totalRateBps, $missing, $totals);
        $totals['gross_minor'] += $shippingMinor;
        $totals['gross_before_global_discount_minor'] = $totals['gross_minor'];
        $totals['global_discount_minor'] = $discountMinor;
        $totals['gross_minor'] -= $discountMinor;
        $complete = $configured && $missing === [] && $discountMinor === 0;
        $status = $complete ? 'complete' : 'pending_configuration';
        if (in_array('shipping_mixed_affectations', $missing, true) || in_array('shipping_treatment_unresolved', $missing, true) || in_array('global_discount_allocation', $missing, true)) {
            $status = 'review_required';
        }

        return [
            'status' => $status,
            'calculation_version' => 'peruvian-inclusive-tax-v1',
            'configuration_version' => $rateVersion,
            'currency' => 'PEN',
            'prices_include_tax' => true,
            'effective_from' => $configured ? $configuration->effective_from->format('Y-m-d') : null,
            'rate_basis_points' => $totalRateBps,
            'igv_rate_basis_points' => $igvBps,
            'ipm_rate_basis_points' => $ipmBps,
            'rounding' => 'per_line_extended_gross_half_up_to_cent; components reconcile to rounded total tax',
            'lines' => $lineSnapshots,
            'shipping' => $shippingSnapshot,
            'totals' => $this->formatTotals($totals),
            'missing_fields' => array_values(array_unique($missing)),
            'source' => 'product_and_order_snapshots; rates from business_tax_configurations',
        ];
    }

    public function configuration(bool $lock = false): ?BusinessTaxConfiguration
    {
        $query = BusinessTaxConfiguration::query()->where('singleton_key', BusinessTaxConfiguration::SINGLETON_KEY);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function configuredVersion(): ?int
    {
        $configuration = $this->configuration();
        $active = $configuration?->is_configured === true
            && $configuration->prices_include_tax === true
            && $configuration->effective_from !== null
            && $configuration->effective_from->startOfDay()->lessThanOrEqualTo(now()->startOfDay());

        return $active ? (int) $configuration->version : null;
    }

    private function shippingSnapshot(int $shippingMinor, array $affectationKinds, bool $configured, ?int $igvBps, ?int $ipmBps, ?int $totalRateBps, array &$missing, array &$totals): array
    {
        if ($shippingMinor < 0) {
            throw ValidationException::withMessages(['shipping_amount' => ['El importe de envío no puede ser negativo.']]);
        }

        if ($shippingMinor === 0) {
            return ['status' => 'complete', 'gross' => '0.00', 'base' => '0.00', 'tax' => '0.00', 'igv' => '0.00', 'ipm' => '0.00', 'tax_affectation' => null, 'source' => 'order.shipping_amount'];
        }

        if (! $configured) {
            $missing[] = 'shipping_business_tax_configuration';

            return ['status' => 'pending_configuration', 'gross' => $this->money->fromMinor($shippingMinor), 'base' => null, 'tax' => null, 'igv' => null, 'ipm' => null, 'tax_affectation' => null, 'source' => 'order.shipping_amount'];
        }

        $kinds = array_values(array_unique($affectationKinds));
        if (count($kinds) > 1) {
            $missing[] = 'shipping_mixed_affectations';

            return ['status' => 'review_required', 'gross' => $this->money->fromMinor($shippingMinor), 'base' => null, 'tax' => null, 'igv' => null, 'ipm' => null, 'tax_affectation' => null, 'source' => 'order.shipping_amount'];
        }
        if ($kinds === [] || $kinds[0] !== 'taxable') {
            $missing[] = 'shipping_treatment_unresolved';

            return ['status' => 'review_required', 'gross' => $this->money->fromMinor($shippingMinor), 'base' => null, 'tax' => null, 'igv' => null, 'ipm' => null, 'tax_affectation' => null, 'source' => 'order.shipping_amount'];
        }

        $baseMinor = $this->roundRatio($shippingMinor * 10000, 10000 + $totalRateBps);
        $taxMinor = $shippingMinor - $baseMinor;
        $igvMinor = $this->roundRatio($baseMinor * $igvBps, 10000);
        $ipmMinor = $taxMinor - $igvMinor;
        $totals['taxable_base_minor'] += $baseMinor;
        $totals['igv_minor'] += $igvMinor;
        $totals['ipm_minor'] += $ipmMinor;
        $totals['tax_minor'] += $taxMinor;

        return [
            'status' => 'complete', 'gross' => $this->money->fromMinor($shippingMinor),
            'base' => $this->money->fromMinor($baseMinor), 'tax' => $this->money->fromMinor($taxMinor),
            'igv' => $this->money->fromMinor($igvMinor), 'ipm' => $this->money->fromMinor($ipmMinor),
            'tax_affectation' => ['code' => '10', 'label' => self::AFFECTATIONS['10']['label']],
            'source' => 'order.shipping_amount; accessory_to_taxable_goods',
        ];
    }

    private function minor(mixed $value): int
    {
        if ($value === null) {
            throw ValidationException::withMessages(['items' => ['Falta un importe del pedido para el cálculo tributario.']]);
        }

        return $this->money->toMinor((string) $value);
    }

    private function roundRatio(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw ValidationException::withMessages(['tax_configuration' => ['La configuración tributaria tiene una tasa inválida.']]);
        }

        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }

    private function emptyTotals(): array
    {
        return [
            'gross_minor' => 0,
            'taxable_base_minor' => 0,
            'exempt_base_minor' => 0,
            'unaffected_base_minor' => 0,
            'tax_minor' => 0,
            'igv_minor' => 0,
            'ipm_minor' => 0,
            'promotion_discount_minor' => 0,
        ];
    }

    private function formatTotals(array $totals): array
    {
        return collect($totals)->mapWithKeys(fn (int $value, string $key): array => [$key => $this->money->fromMinor($value)])->all();
    }
}
