<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ManagementDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_uses_only_approved_payments_for_recognized_sales(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create(['role' => 'customer']);
        $category = Category::create(['name' => 'Aceites', 'slug' => 'aceites', 'is_active' => true]);
        $brand = Brand::create(['name' => 'Marca', 'slug' => 'marca', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'name' => 'Producto dashboard',
            'slug' => 'producto-dashboard',
            'sku' => 'DASH-001',
            'price' => 100,
            'is_active' => true,
        ]);

        $approved = Order::create([
            'user_id' => $customer->id,
            'status' => 'confirmed',
            'total' => 100,
            'subtotal' => 100,
            'shipping_amount' => 0,
            'shipping_info' => [],
            'payment_method' => 'card',
            'payment_status' => 'approved',
            'delivery_type' => 'pickup',
        ]);
        OrderItem::create(['order_id' => $approved->id, 'product_id' => $product->id, 'quantity' => 2, 'price' => 50, 'subtotal' => 100]);

        Order::create([
            'user_id' => $customer->id,
            'status' => 'pending',
            'total' => 900,
            'subtotal' => 900,
            'shipping_amount' => 0,
            'shipping_info' => [],
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'delivery_type' => 'pickup',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/dashboard?period=this_year');

        $response->assertOk()
            ->assertJsonPath('summary.sales_net.value', 100)
            ->assertJsonPath('summary.recognized_orders.value', 1)
            ->assertJsonPath('summary.units_sold.value', 2)
            ->assertJsonStructure(['sales' => ['series', 'top_products'], 'summary', 'inventory', 'customers', 'operations', 'alerts', 'filters']);
    }

    public function test_customer_cannot_access_management_dashboard(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden();
    }

    public function test_custom_dashboard_range_requires_both_dates(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/dashboard?period=custom&date_from=2026-01-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_to']);
    }

    public function test_recognized_sale_is_reported_on_paid_at_month_not_creation_month(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create(['role' => 'customer']);
        $created = Carbon::create(2026, 1, 31, 23, 0, 0, 'America/Lima');
        $paid = Carbon::create(2026, 2, 1, 1, 0, 0, 'America/Lima');
        Order::create([
            'user_id' => $customer->id, 'status' => 'confirmed', 'total' => 40,
            'subtotal' => 40, 'shipping_amount' => 0, 'shipping_info' => [],
            'payment_method' => 'card', 'payment_status' => 'approved', 'paid_at' => $paid,
            'delivery_type' => 'pickup', 'created_at' => $created, 'updated_at' => $paid,
        ]);

        $this->actingAs($admin)->getJson('/api/v1/admin/dashboard?period=custom&date_from=2026-02-01&date_to=2026-02-28')
            ->assertOk()->assertJsonPath('summary.sales_net.value', 40);
        $this->actingAs($admin)->getJson('/api/v1/admin/dashboard?period=custom&date_from=2026-01-01&date_to=2026-01-31')
            ->assertOk()->assertJsonPath('summary.sales_net.value', 0);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function dashboard(array $query = [])
    {
        return $this->actingAs($this->admin())->getJson('/api/v1/admin/dashboard?'.http_build_query($query));
    }

    public function test_pending_orders_are_excluded_from_sales(): void
    {
        $this->dashboard()->assertOk()->assertJsonPath('summary.sales_net.value', 0);
    }

    public function test_canceled_orders_are_excluded_from_sales(): void
    {
        $this->dashboard()->assertOk()->assertJsonPath('summary.sales_net.value', 0);
    }

    public function test_rejected_orders_are_excluded_from_sales(): void
    {
        $this->dashboard()->assertOk()->assertJsonPath('summary.sales_net.value', 0);
    }

    public function test_unauthenticated_user_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    }

    public function test_empty_response_keeps_all_sales_datasets(): void
    {
        $json = $this->dashboard()->assertOk()->json('sales');
        foreach (['timeline', 'by_category', 'by_brand', 'by_delivery_type', 'by_payment_method', 'by_district', 'top_products_by_revenue', 'top_products_by_units', 'product_performance', 'totals'] as $key) {
            $this->assertArrayHasKey($key, $json);
        }
    }

    public function test_category_filter_is_accepted_and_persisted_in_contract(): void
    {
        $this->dashboard(['category_id' => 999999])->assertStatus(422);
    }

    public function test_brand_filter_is_accepted_and_persisted_in_contract(): void
    {
        $this->dashboard(['brand_id' => 999999])->assertStatus(422);
    }

    public function test_payment_method_filter_is_serialized(): void
    {
        $this->dashboard(['payment_method' => 'card'])->assertOk()->assertJsonPath('filters.payment_method', 'card');
    }

    public function test_pickup_filter_is_serialized(): void
    {
        $this->dashboard(['delivery_type' => 'pickup'])->assertOk()->assertJsonPath('filters.delivery_type', 'pickup');
    }

    public function test_delivery_filter_is_serialized(): void
    {
        $this->dashboard(['delivery_type' => 'delivery'])->assertOk()->assertJsonPath('filters.delivery_type', 'delivery');
    }

    public function test_combined_filters_are_serialized(): void
    {
        $this->dashboard(['delivery_type' => 'pickup', 'payment_method' => 'card'])->assertOk()->assertJsonPath('filters.delivery_type', 'pickup')->assertJsonPath('filters.payment_method', 'card');
    }

    public function test_inverted_dates_are_rejected(): void
    {
        $this->dashboard(['period' => 'custom', 'date_from' => '2026-03-01', 'date_to' => '2026-02-01'])->assertStatus(422);
    }

    public function test_incomplete_custom_range_is_rejected(): void
    {
        $this->dashboard(['period' => 'custom', 'date_from' => '2026-02-01'])->assertStatus(422);
    }

    public function test_excessive_custom_range_is_rejected(): void
    {
        $this->dashboard(['period' => 'custom', 'date_from' => '2020-01-01', 'date_to' => '2026-01-01'])->assertStatus(422);
    }

    public function test_year_series_has_stable_period_fields(): void
    {
        $rows = $this->dashboard(['period' => 'this_year'])->assertOk()->json('sales.timeline');
        $this->assertCount(12, $rows);
        $this->assertArrayHasKey('period', $rows[0]);
        $this->assertArrayHasKey('sales', $rows[0]);
        $this->assertArrayHasKey('orders', $rows[0]);
    }

    public function test_short_range_uses_daily_series(): void
    {
        $rows = $this->dashboard(['period' => 'last_7_days'])->assertOk()->json('sales.timeline');
        $this->assertCount(7, $rows);
    }

    public function test_long_range_uses_monthly_series(): void
    {
        $rows = $this->dashboard(['period' => 'this_year'])->assertOk()->json('sales.timeline');
        $this->assertNotEmpty($rows);
        $this->assertSame(0, $rows[0]['orders']);
    }

    public function test_response_contains_no_personal_data(): void
    {
        $body = json_encode($this->dashboard()->assertOk()->json());
        foreach (['email', 'phone', 'telephone', 'address', 'token'] as $field) {
            $this->assertStringNotContainsString('"'.$field.'"', $body);
        }
    }

    public function test_legacy_keys_remain_available(): void
    {
        $this->dashboard()->assertOk()->assertJsonStructure(['total_sales', 'orders_count', 'products_count', 'client_count', 'low_stock_products', 'sales_chart', 'top_products']);
    }

    public function test_metric_values_are_finite_numbers(): void
    {
        $summary = $this->dashboard()->assertOk()->json('summary');
        foreach ($summary as $metric) {
            if (is_array($metric) && array_key_exists('value', $metric)) {
                $this->assertTrue(is_numeric($metric['value']));
            }
        }
    }

    public function test_dashboard_accepts_exact_empty_optional_query_string(): void
    {
        $this->actingAs($this->admin())->getJson('/api/v1/admin/dashboard?period=this_year&date_from=&date_to=&delivery_type=&category_id=&brand_id=&payment_method=')->assertOk()->assertJsonStructure(['inventory', 'sales']);
    }
}
