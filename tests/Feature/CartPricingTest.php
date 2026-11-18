<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPromotion;
use App\Models\User;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CartPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_sync_returns_regular_and_applied_prices_for_mixed_items_and_reprices_an_expired_offer(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'customer', 'is_active' => true]));
        $instant = Carbon::parse('2026-11-18 12:00:00', config('app.timezone'));
        $this->travelTo($instant);

        $discounted = $this->product('Aceite en oferta', 'CART-OFFER-109', '109.00');
        $regular = $this->product('Producto sin oferta', 'CART-REGULAR-40', '40.00');
        $inventory = app(InventoryService::class);
        $warehouse = $inventory->defaultWarehouse();
        $inventory->initializeProduct($discounted, 20, $warehouse);
        $inventory->initializeProduct($regular, 20, $warehouse);
        $promotion = ProductPromotion::create([
            'product_id' => $discounted->id,
            'promotion_price' => '100.00',
            'starts_at' => $instant->copy()->subMinute(),
            'ends_at' => $instant->copy()->addMinute(),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/cart', ['items' => [
            ['product_id' => $discounted->id, 'quantity' => 6],
            ['product_id' => $regular->id, 'quantity' => 2],
        ]])->assertOk()->assertJsonPath('valid', true);

        $this->assertMoney('109.00', $response->json('items.0.regular_price'));
        $this->assertMoney('100.00', $response->json('items.0.applied_price'));
        $this->assertMoney('40.00', $response->json('items.1.regular_price'));
        $this->assertMoney('40.00', $response->json('items.1.applied_price'));
        $this->assertSame(6, $response->json('items.0.requested_quantity'));

        $this->travelTo($instant->copy()->addMinutes(2));
        $expired = $this->postJson('/api/v1/cart', ['items' => [
            ['product_id' => $discounted->id, 'quantity' => 6],
        ]])->assertOk();
        $this->assertMoney('109.00', $expired->json('items.0.regular_price'));
        $this->assertMoney('109.00', $expired->json('items.0.applied_price'));
        $this->assertDatabaseHas('product_promotions', ['id' => $promotion->id, 'is_active' => true]);
    }

    private function product(string $name, string $sku, string $price): Product
    {
        return Product::create([
            'name' => $name,
            'slug' => str_replace(' ', '-', strtolower($name)).'-'.bin2hex(random_bytes(4)),
            'sku' => $sku,
            'price' => $price,
            'is_active' => true,
        ]);
    }

    private function assertMoney(string $expected, mixed $actual): void
    {
        $this->assertSame($expected, number_format((float) $actual, 2, '.', ''));
    }
}
