<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductModel;
use App\Models\ProductPromotion;
use App\Models\ShippingRate;
use App\Models\User;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesShippingCoverage;
use Tests\TestCase;

class ProductPromotionsTest extends TestCase
{
    use CreatesShippingCoverage, RefreshDatabase;

    public function test_admin_creates_timed_offer_and_rejects_enabled_overlaps_and_invalid_prices(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $product = $this->product('Filtro ABC-00123', 'SKU-PROMO-1', '100.00');
        $starts = now()->addHour()->format('Y-m-d\\TH:i');
        $ends = now()->addHours(4)->format('Y-m-d\\TH:i');

        $created = $this->postJson('/api/v1/admin/product-promotions', [
            'product_id' => $product->id,
            'promotion_price' => '75.50',
            'starts_at' => $starts,
            'ends_at' => $ends,
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('business_timezone', config('app.timezone'));
        $promotionId = $created->json('id');

        $disabled = $this->postJson('/api/v1/admin/product-promotions', [
            'product_id' => $product->id,
            'promotion_price' => '70.00',
            'starts_at' => now()->addHours(2)->format('Y-m-d\\TH:i'),
            'ends_at' => now()->addHours(5)->format('Y-m-d\\TH:i'),
            'is_active' => false,
        ])->assertCreated();
        $this->patchJson('/api/v1/admin/product-promotions/'.$disabled->json('id').'/status', ['is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('starts_at');

        $this->postJson('/api/v1/admin/product-promotions', [
            'product_id' => $product->id,
            'promotion_price' => '70.00',
            'starts_at' => now()->addHours(3)->format('Y-m-d\\TH:i'),
            'ends_at' => now()->addHours(5)->format('Y-m-d\\TH:i'),
            'is_active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('starts_at');

        $this->putJson('/api/v1/admin/product-promotions/'.$promotionId, [
            'product_id' => $product->id,
            'promotion_price' => '100.00',
            'starts_at' => $starts,
            'ends_at' => $ends,
            'is_active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('promotion_price');

        $this->assertDatabaseCount('product_promotions', 2);
    }

    public function test_public_catalog_and_detail_apply_offer_only_at_inclusive_start_and_exclusive_end(): void
    {
        $now = Carbon::parse('2026-11-17 10:00:00', config('app.timezone'));
        $product = $this->product('Filtro especial', 'SKU-PROMO-2', '100.00');
        ProductPromotion::create([
            'product_id' => $product->id,
            'promotion_price' => '70.00',
            'starts_at' => $now->copy()->addMinute(),
            'ends_at' => $now->copy()->addMinutes(2),
            'is_active' => true,
        ]);

        $this->travelTo($now);
        $listing = $this->getJson('/api/v1/products?search=SKU-PROMO-2')->assertOk()->assertJsonPath('data.0.sale_price', null);
        $this->assertMoney('100.00', $listing->json('data.0.price'));
        $detail = $this->getJson('/api/v1/products/'.$product->slug)->assertOk();
        $this->assertMoney('100.00', $detail->json('price'));

        $this->travelTo($now->copy()->addMinute());
        $listing = $this->getJson('/api/v1/products?search=SKU-PROMO-2')->assertOk();
        $this->assertMoney('70.00', $listing->json('data.0.sale_price'));
        $detail = $this->getJson('/api/v1/products/'.$product->slug)->assertOk();
        $this->assertMoney('70.00', $detail->json('sale_price'));

        $this->travelTo($now->copy()->addMinutes(2));
        $this->getJson('/api/v1/products?search=SKU-PROMO-2')->assertOk()->assertJsonPath('data.0.sale_price', null);
    }

    public function test_product_lookup_matches_model_code_and_offer_can_be_deactivated(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $brand = Brand::create(['name' => 'Marca Promoción', 'slug' => 'marca-promo', 'is_active' => true]);
        $model = ProductModel::create(['brand_id' => $brand->id, 'name' => 'ABC-00123', 'normalized_name' => 'ABC-00123', 'is_active' => true]);
        $product = Product::create(['brand_id' => $brand->id, 'product_model_id' => $model->id, 'name' => 'Filtro de cabina', 'slug' => 'filtro-cabina-promo', 'sku' => 'SKU-PROMO-3', 'price' => '50.00', 'is_active' => true]);
        $this->getJson('/api/v1/admin/product-promotions/products?search=ABC-00123')->assertOk()->assertJsonPath('0.id', $product->id);
        $promotion = ProductPromotion::create(['product_id' => $product->id, 'promotion_price' => '40.00', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'is_active' => true]);

        $this->patchJson('/api/v1/admin/product-promotions/'.$promotion->id.'/status', ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        $this->getJson('/api/v1/products?search=SKU-PROMO-3')->assertOk()->assertJsonPath('data.0.sale_price', null);
    }

    public function test_checkout_requires_confirmation_for_changed_price_and_preserves_order_snapshot_after_offer_expires(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        Sanctum::actingAs($customer);
        $product = $this->product('Producto checkout promo', 'SKU-PROMO-CHECKOUT', '10.00');
        app(InventoryService::class)->initializeProduct($product, 8, app(InventoryService::class)->defaultWarehouse());
        $endsAt = now()->addMinutes(3);
        $promotion = ProductPromotion::create([
            'product_id' => $product->id,
            'promotion_price' => '8.00',
            'starts_at' => now()->subMinute(),
            'ends_at' => $endsAt,
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'code' => 'PROMO-'.Str::upper(Str::random(5)),
            'name' => 'Sede Promoción', 'address' => 'Av. Prueba 123', 'district' => 'Lima', 'province' => 'Lima',
            'department' => 'Lima', 'business_hours' => 'Lunes a viernes', 'pickup_instructions' => 'Presentar pedido',
            'allows_pickup' => true, 'serves_public' => true, 'is_active' => true,
        ]);
        $payload = [
            'checkout_token' => (string) Str::uuid(), 'delivery_type' => 'pickup', 'pickup_branch_id' => $branch->id,
            'payment_method' => 'transferencia', 'expected_total' => '20.00',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ];

        $changed = $this->postJson('/api/v1/orders', $payload)->assertStatus(409)
            ->assertJsonPath('code', 'price_changed')->assertJsonPath('total', '16.00')
            ->assertJsonPath('items.0.price', '8.00');
        $this->assertDatabaseCount('orders', 0);

        $payload['expected_total'] = $changed->json('total');
        $payload['price_confirmation_token'] = $changed->json('confirmation_token');
        $order = Order::find($this->postJson('/api/v1/orders', $payload)->assertCreated()->json('id'));
        $item = $order->items()->firstOrFail();
        $this->assertMoney('16.00', $order->total);
        $this->assertMoney('8.00', $item->price);
        $this->assertMoney('10.00', $item->regular_price);
        $this->assertSame($promotion->id, $item->promotion_id);

        $this->travelTo($endsAt->copy()->addSecond());
        $product->update(['price' => '12.00']);
        $this->assertMoney('16.00', $order->fresh()->total);
        $this->assertMoney('8.00', $item->fresh()->price);
        $this->assertSame($promotion->id, $item->fresh()->promotion_id);

        $detail = $this->getJson('/api/v1/orders/'.$order->id)->assertOk();
        $detail->assertJsonPath('total', '16.00')
            ->assertJsonPath('subtotal', '16.00')
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonPath('items.0.promotion_id', $promotion->id);
        $this->assertSame('8.00', number_format((float) $detail->json('items.0.price'), 2, '.', ''));
        $this->assertSame('10.00', number_format((float) $detail->json('items.0.regular_price'), 2, '.', ''));
    }

    public function test_expired_offer_in_cart_is_repriced_and_confirmed_before_order_creation(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'customer', 'is_active' => true]));
        $product = $this->product('Producto con oferta vencida', 'SKU-PROMO-EXPIRED', '100.00');
        app(InventoryService::class)->initializeProduct($product, 5, app(InventoryService::class)->defaultWarehouse());
        $endsAt = now()->addMinute();
        ProductPromotion::create([
            'product_id' => $product->id,
            'promotion_price' => '80.00',
            'starts_at' => now()->subHour(),
            'ends_at' => $endsAt,
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'code' => 'EXPIRED-'.Str::upper(Str::random(5)),
            'name' => 'Sede Expirada', 'address' => 'Av. Prueba 456', 'district' => 'Lima', 'province' => 'Lima',
            'department' => 'Lima', 'business_hours' => 'Lunes a viernes', 'pickup_instructions' => 'Presentar pedido',
            'allows_pickup' => true, 'serves_public' => true, 'is_active' => true,
        ]);
        $payload = [
            'checkout_token' => (string) Str::uuid(), 'delivery_type' => 'pickup', 'pickup_branch_id' => $branch->id,
            'payment_method' => 'transferencia', 'expected_total' => '80.00',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];

        $this->travelTo($endsAt->copy()->addSecond());
        $changed = $this->postJson('/api/v1/orders', $payload)->assertStatus(409)
            ->assertJsonPath('code', 'price_changed')->assertJsonPath('total', '100.00');
        $this->assertDatabaseCount('orders', 0);

        $payload['expected_total'] = $changed->json('total');
        $payload['price_confirmation_token'] = $changed->json('confirmation_token');
        $order = Order::find($this->postJson('/api/v1/orders', $payload)->assertCreated()->json('id'));
        $item = $order->items()->firstOrFail();
        $this->assertMoney('100.00', $item->price);
        $this->assertNull($item->promotion_id);
    }

    public function test_order_detail_keeps_offer_snapshots_and_saved_shipping_in_final_total(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        Sanctum::actingAs($customer);
        $product = $this->product('Aceite con envío y oferta', 'SKU-PROMO-SHIPPING', '109.00');
        app(InventoryService::class)->initializeProduct($product, 10, app(InventoryService::class)->defaultWarehouse());
        ProductPromotion::create([
            'product_id' => $product->id,
            'promotion_price' => '100.00',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'is_active' => true,
        ]);
        $payload = $this->withShippingCoverage([
            'checkout_token' => (string) Str::uuid(),
            'delivery_type' => 'delivery',
            'shipping_info' => ['address' => 'Av. Oferta 123'],
            'payment_method' => 'transferencia',
            'expected_total' => '615.00',
            'items' => [['product_id' => $product->id, 'quantity' => 6]],
        ], $customer);
        ShippingRate::where('active_district_key', app(\App\Services\TerritoryNormalizer::class)->identityKey('Lima', 'Lima', 'Distrito de pruebas'))
            ->firstOrFail()->update(['amount' => '15.00']);

        $orderId = $this->postJson('/api/v1/orders', $payload)->assertCreated()
            ->assertJsonPath('subtotal', '600.00')
            ->assertJsonPath('shipping_amount', '15.00')
            ->assertJsonPath('total', '615.00')
            ->json('id');

        $detail = $this->getJson('/api/v1/orders/'.$orderId)->assertOk()
            ->assertJsonPath('subtotal', '600.00')
            ->assertJsonPath('shipping_amount', '15.00')
            ->assertJsonPath('total', '615.00')
            ->assertJsonPath('items.0.quantity', 6);
        $this->assertSame('109.00', number_format((float) $detail->json('items.0.regular_price'), 2, '.', ''));
        $this->assertSame('100.00', number_format((float) $detail->json('items.0.price'), 2, '.', ''));
    }

    private function product(string $name, string $sku, string $price): Product
    {
        return Product::create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)), 'sku' => $sku, 'price' => $price, 'is_active' => true]);
    }

    private function assertMoney(string $expected, mixed $actual): void
    {
        $this->assertSame($expected, number_format((float) $actual, 2, '.', ''));
    }

}
