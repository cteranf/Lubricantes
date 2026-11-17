<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductBarcodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductBarcodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    }

    public function test_commercial_formats_normalize_equivalent_upc_and_ean_without_losing_leading_zeroes(): void
    {
        $normalizer = app(ProductBarcodeService::class);

        $this->assertSame('gtin13:4006381333931', $normalizer->normalize('4006381333931', 'ean13'));
        $this->assertSame('gtin8:96385074', $normalizer->normalize('96385074', 'ean8'));
        $this->assertSame('gtin13:0036000291452', $normalizer->normalize('036000291452', 'upca'));
        $this->assertSame($normalizer->normalize('042100005264', 'upca'), $normalizer->normalize('04252614', 'upce'));
        $this->assertSame('code128:LS-CODE-128-1', $normalizer->normalize('LS-CODE-128-1', 'code128'));
        $this->assertNull($normalizer->normalize('4006381333932', 'ean13'));
        $this->assertNull($normalizer->normalize('036000291453', 'upca'));
        $this->assertNull($normalizer->normalize('123', 'ean8'));
    }

    public function test_admin_resolution_supports_qr_sku_ean_upc_and_code128_without_mutating_inventory(): void
    {
        $product = $this->product(['sku' => 'LUB-001234', 'stock' => 27]);
        foreach ([
            ['4006381333931', 'ean13'],
            ['96385074', 'ean8'],
            ['036000291452', 'upca'],
            ['04252614', 'upce'],
            ['LS-CODE-128-1', 'code128'],
        ] as [$code, $format]) {
            $this->associate($product, $code, $format);
        }

        $scans = [
            ['LS1:PRODUCT:'.$product->id, 'QR_CODE', 'qr_internal'],
            ['LUB-001234', null, 'sku'],
            ['4006381333931', 'EAN_13', 'ean13'],
            ['96385074', 'EAN_8', 'ean8'],
            ['036000291452', 'UPC_A', 'upca'],
            ['0036000291452', 'EAN_13', 'ean13'],
            ['04252614', 'UPC_E', 'upce'],
            ['LS-CODE-128-1', 'CODE_128', 'code128'],
        ];

        foreach ($scans as [$code, $format, $expectedFormat]) {
            $response = $this->postJson('/api/v1/admin/inventory-receipts/resolve-product-code', array_filter([
                'code' => $code,
                'format' => $format,
            ], fn ($value) => $value !== null))->assertOk();

            $response->assertJsonPath('data.id', $product->id)
                ->assertJsonPath('data.matched_code', $code)
                ->assertJsonPath('data.matched_format', $expectedFormat);
        }

        $this->assertSame(27, (int) $product->fresh()->getRawOriginal('stock'));
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_invalid_unknown_inactive_and_ambiguous_codes_are_rejected(): void
    {
        $active = $this->product(['sku' => '4006381333931']);
        $other = $this->product(['sku' => 'SKU-OTHER']);
        $this->associate($other, '4006381333931', 'ean13');

        $this->postJson('/api/v1/admin/inventory-receipts/resolve-product-code', [
            'code' => '4006381333931', 'format' => 'EAN_13',
        ])->assertStatus(409);

        $this->postJson('/api/v1/admin/inventory-receipts/resolve-product-code', [
            'code' => '4006381333932', 'format' => 'EAN_13',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/admin/inventory-receipts/resolve-product-code', ['code' => 'UNKNOWN-000'])->assertNotFound();

        $inactive = $this->product(['is_active' => false]);
        $this->associate($inactive, '96385074', 'ean8');
        $this->postJson('/api/v1/admin/inventory-receipts/resolve-product-code', [
            'code' => '96385074', 'format' => 'EAN_8',
        ])->assertNotFound();

        $this->assertDatabaseHas('products', ['id' => $active->id, 'is_active' => true]);
    }

    public function test_admin_product_form_synchronizes_barcodes_and_preserves_them_when_field_is_absent(): void
    {
        $product = $this->product();
        $this->associate($product, '4006381333931', 'ean13', true);

        $this->getJson('/api/v1/admin/products/'.$product->id)->assertOk()
            ->assertJsonPath('barcodes.0.code', '4006381333931')
            ->assertJsonPath('barcodes.0.format', 'ean13')
            ->assertJsonPath('barcodes.0.is_primary', true);

        $this->putJson('/api/v1/admin/products/'.$product->id, ['name' => 'Nombre actualizado'])->assertOk();
        $this->assertDatabaseCount('product_barcodes', 1);

        $this->putJson('/api/v1/admin/products/'.$product->id, ['barcodes' => [
            ['code' => '96385074', 'format' => 'ean8', 'is_primary' => true],
        ]])->assertOk()->assertJsonPath('barcodes.0.code', '96385074');

        $this->assertDatabaseMissing('product_barcodes', ['normalized_code' => 'gtin13:4006381333931']);
        $this->assertDatabaseHas('product_barcodes', ['product_id' => $product->id, 'code' => '96385074', 'format' => 'ean8']);
    }

    public function test_product_creation_validates_duplicate_and_invalid_commercial_codes(): void
    {
        $existing = $this->product();
        $this->associate($existing, '4006381333931', 'ean13');

        $this->postJson('/api/v1/admin/products', [
            'name' => 'Nuevo producto duplicado',
            'sku' => 'NEW-PRODUCT-01',
            'price' => 12,
            'cantidad_inicial' => 0,
            'barcodes' => [['code' => '4006381333931', 'format' => 'ean13', 'is_primary' => true]],
        ])->assertUnprocessable()->assertJsonValidationErrors('barcodes.0.code');

        $this->postJson('/api/v1/admin/products', [
            'name' => 'Nuevo producto inválido',
            'sku' => 'NEW-PRODUCT-02',
            'price' => 12,
            'cantidad_inicial' => 0,
            'barcodes' => [['code' => '4006381333932', 'format' => 'ean13', 'is_primary' => true]],
        ])->assertUnprocessable()->assertJsonValidationErrors('barcodes.0.code');

        $this->postJson('/api/v1/admin/products', [
            'name' => 'Nuevo producto válido',
            'sku' => 'NEW-PRODUCT-03',
            'price' => 12,
            'cantidad_inicial' => 0,
            'barcodes' => [['code' => '0036000291452', 'format' => 'ean13', 'is_primary' => true]],
        ])->assertCreated()->assertJsonPath('barcodes.0.code', '0036000291452');
    }

    public function test_database_uses_unique_normalized_codes_and_cascades_with_product(): void
    {
        $product = $this->product();
        $this->associate($product, '036000291452', 'upca');
        $this->assertTrue(Schema::hasColumn('product_barcodes', 'normalized_code'));
        $this->assertDatabaseHas('product_barcodes', ['normalized_code' => 'gtin13:0036000291452']);

        $other = $this->product();
        try {
            $this->associate($other, '0036000291452', 'ean13');
            $this->fail('La normalización única debe impedir el mismo GTIN UPC-A/EAN-13 en dos productos.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertDatabaseCount('product_barcodes', 1);
        }

        \Illuminate\Support\Facades\DB::table('products')->where('id', $product->id)->delete();
        $this->assertDatabaseCount('product_barcodes', 0);
    }

    private function product(array $attributes = []): Product
    {
        $product = Product::query()->create(array_merge([
            'name' => 'Producto escaneable',
            'slug' => 'producto-'.uniqid(),
            'sku' => 'SKU-'.strtoupper(bin2hex(random_bytes(4))),
            'price' => '10.00',
            'stock' => 0,
            'is_active' => true,
        ], $attributes));
        if (array_key_exists('stock', $attributes)) {
            $product->forceFill(['stock' => $attributes['stock']])->saveQuietly();
        }

        return $product;
    }

    private function associate(Product $product, string $code, string $format, bool $primary = false): void
    {
        $normalized = app(ProductBarcodeService::class)->normalize($code, $format);
        $this->assertNotNull($normalized, 'El fixture debe usar un código válido.');
        $product->barcodes()->create([
            'code' => $code,
            'format' => $format,
            'normalized_code' => $normalized,
            'is_primary' => $primary,
        ]);
    }
}
