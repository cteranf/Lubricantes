<?php

namespace Tests\Feature;

use App\Models\BusinessTaxConfiguration;
use App\Services\MoneyService;
use App\Services\OrderTaxCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderTaxCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inclusive_tax_uses_saved_applied_price_and_reconciles_components_per_line(): void
    {
        $this->configureRates();
        $snapshot = $this->calculator()->calculate([
            ['product_id' => 41, 'tax_affectation' => '10', 'quantity' => 1, 'regular_price' => '130.00', 'price' => '118.00', 'subtotal' => '118.00'],
        ], 0);

        $this->assertSame('complete', $snapshot['status']);
        $this->assertSame('100.00', $snapshot['lines'][0]['base']);
        $this->assertSame('18.00', $snapshot['lines'][0]['tax']);
        $this->assertSame('15.50', $snapshot['lines'][0]['igv']);
        $this->assertSame('2.50', $snapshot['lines'][0]['ipm']);
        $this->assertSame('12.00', $snapshot['lines'][0]['promotion_discount_gross']);
        $this->assertSame('118.00', $snapshot['totals']['gross_minor']);
        $this->assertSame('0.00', $snapshot['shipping']['gross']);
    }

    public function test_multiple_quantity_rounding_is_reproducible_and_shipping_is_added_once(): void
    {
        $this->configureRates();
        $snapshot = $this->calculator()->calculate([
            ['product_id' => 42, 'tax_affectation' => '10', 'quantity' => 3, 'regular_price' => '0.05', 'price' => '0.05', 'subtotal' => '0.15'],
        ], 1000);

        $this->assertSame('0.13', $snapshot['lines'][0]['base']);
        $this->assertSame('0.02', $snapshot['lines'][0]['tax']);
        $this->assertSame('10.00', $snapshot['shipping']['gross']);
        $this->assertSame('10.15', $snapshot['totals']['gross_minor']);
        $this->assertSame(
            $this->toMinor($snapshot['lines'][0]['gross']) + $this->toMinor($snapshot['shipping']['gross']),
            $this->toMinor($snapshot['totals']['gross_minor'])
        );
    }

    public function test_ordinary_exempt_and_unaffected_lines_are_classified_and_mixed_shipping_requires_review(): void
    {
        $this->configureRates();
        $items = [
            ['product_id' => 1, 'tax_affectation' => '10', 'quantity' => 1, 'regular_price' => '118.00', 'price' => '118.00', 'subtotal' => '118.00'],
            ['product_id' => 2, 'tax_affectation' => '20', 'quantity' => 1, 'regular_price' => '20.00', 'price' => '20.00', 'subtotal' => '20.00'],
            ['product_id' => 3, 'tax_affectation' => '30', 'quantity' => 1, 'regular_price' => '7.00', 'price' => '7.00', 'subtotal' => '7.00'],
        ];

        $freeShipping = $this->calculator()->calculate($items, 0);
        $this->assertSame('complete', $freeShipping['status']);
        $this->assertSame('20.00', $freeShipping['totals']['exempt_base_minor']);
        $this->assertSame('7.00', $freeShipping['totals']['unaffected_base_minor']);
        $this->assertSame('0.00', $freeShipping['shipping']['gross']);

        $paidShipping = $this->calculator()->calculate($items, 500);
        $this->assertSame('review_required', $paidShipping['status']);
        $this->assertContains('shipping_mixed_affectations', $paidShipping['missing_fields']);
        $this->assertSame('5.00', $paidShipping['shipping']['gross']);
        $this->assertNull($paidShipping['shipping']['tax']);
    }

    public function test_missing_or_future_configuration_and_unclassified_products_remain_pending(): void
    {
        $item = ['product_id' => 1, 'tax_affectation' => null, 'quantity' => 2, 'regular_price' => '10.00', 'price' => '8.00', 'subtotal' => '16.00'];
        $withoutConfiguration = $this->calculator()->calculate([$item], 0);
        $this->assertSame('pending_configuration', $withoutConfiguration['status']);
        $this->assertContains('business_tax_configuration', $withoutConfiguration['missing_fields']);
        $this->assertContains('items.0.tax_affectation', $withoutConfiguration['missing_fields']);

        $this->configureRates(now()->addDay()->toDateString());
        $scheduled = $this->calculator()->calculate([$item], 0);
        $this->assertSame('pending_configuration', $scheduled['status']);
        $this->assertNull($scheduled['rate_basis_points']);
    }

    public function test_inconsistent_saved_line_amount_is_rejected_instead_of_adjusted(): void
    {
        $this->configureRates();
        $this->expectException(ValidationException::class);
        $this->calculator()->calculate([
            ['product_id' => 9, 'tax_affectation' => '10', 'quantity' => 2, 'regular_price' => '10.00', 'price' => '9.00', 'subtotal' => '17.99'],
        ], 0);
    }

    private function configureRates(?string $effectiveFrom = null): void
    {
        BusinessTaxConfiguration::create([
            'singleton_key' => BusinessTaxConfiguration::SINGLETON_KEY,
            'igv_rate_basis_points' => 1550,
            'ipm_rate_basis_points' => 250,
            'effective_from' => $effectiveFrom ?? now()->toDateString(),
            'prices_include_tax' => true,
            'shipping_treatment' => 'ancillary_to_goods',
            'is_configured' => true,
            'version' => 2,
        ]);
    }

    private function calculator(): OrderTaxCalculationService
    {
        return new OrderTaxCalculationService(new MoneyService);
    }

    private function toMinor(string $value): int
    {
        return app(MoneyService::class)->toMinor($value);
    }
}
