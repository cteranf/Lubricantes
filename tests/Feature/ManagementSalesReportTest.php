<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ManagementSalesReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function sale(float $total, float $shipping, ?string $paidAt, array $items, string $method = 'card'): Order
    {
        $order = Order::create(['status' => 'confirmed', 'total' => $total, 'subtotal' => $total - $shipping, 'shipping_amount' => $shipping, 'shipping_info' => [], 'payment_method' => $method, 'delivery_type' => 'delivery', 'payment_status' => 'approved', 'paid_at' => $paidAt]);
        foreach ($items as $item) {
            OrderItem::create(['order_id' => $order->id, 'product_id' => $item['product_id'], 'quantity' => $item['quantity'], 'price' => $item['price'], 'subtotal' => $item['subtotal']]);
        }

        return $order;
    }

    public function test_sales_totals_are_reconciled_and_fallback_to_created_at(): void
    {
        $category = Category::create(['name' => 'Motor', 'slug' => 'motor']);
        $brand = Brand::create(['name' => 'Marca', 'slug' => 'marca']);
        $product = Product::create(['name' => 'Aceite', 'slug' => 'aceite', 'price' => 40, 'category_id' => $category->id, 'brand_id' => $brand->id]);
        $order = $this->sale(110, 10, null, [['product_id' => $product->id, 'quantity' => 2, 'price' => 50, 'subtotal' => 100]]);
        $order->created_at = now()->subDay();
        $order->saveQuietly();
        $response = $this->actingAs($this->admin())->getJson('/api/v1/admin/reports/management/sales?period=this_year');
        $response->assertOk()->assertJsonPath('totals.sales', 110)->assertJsonPath('totals.recognized_merchandise_sales', 100)->assertJsonPath('totals.recognized_shipping_revenue', 10)->assertJsonPath('totals.orders', 1)->assertJsonPath('totals.units', 2)->assertJsonPath('rows.0.recognized_at', $order->created_at->toIso8601String());
    }

    public function test_filters_and_limit_are_applied_without_customer_data(): void
    {
        $category = Category::create(['name' => 'Filt', 'slug' => 'filt']);
        $brand = Brand::create(['name' => 'Marca F', 'slug' => 'marca-f']);
        $product = Product::create(['name' => 'Producto F', 'slug' => 'producto-f', 'price' => 20, 'category_id' => $category->id, 'brand_id' => $brand->id]);
        $this->sale(20, 0, now()->subHour(), [['product_id' => $product->id, 'quantity' => 1, 'price' => 20, 'subtotal' => 20]], 'transfer');
        $response = $this->actingAs($this->admin())->getJson('/api/v1/admin/reports/management/sales?category_id='.$category->id.'&brand_id='.$brand->id.'&payment_method=transfer&date_from='.now()->subDay()->toDateString().'&date_to='.now()->toDateString());
        $response->assertOk()->assertJsonPath('metadata.row_count', 1)->assertJsonPath('metadata.data_freshness', 'recognized_sales')->assertJsonMissingPath('rows.0.customer_email');
    }

    public function test_customer_cannot_access_sales_report_and_invalid_section_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))->getJson('/api/v1/admin/reports/management/sales')->assertForbidden();
        $this->actingAs($this->admin())->getJson('/api/v1/admin/reports/management/unknown')->assertNotFound();
    }

    public function test_pending_orders_are_excluded_and_ranges_are_validated(): void
    {
        $product = Product::create(['name' => 'Pendiente', 'slug' => 'pendiente', 'price' => 5]);
        $this->sale(5, 0, now(), [['product_id' => $product->id, 'quantity' => 1, 'price' => 5, 'subtotal' => 5]])->update(['payment_status' => 'pending']);
        $this->getJson('/api/v1/admin/reports/management/sales')->assertUnauthorized();
        $this->actingAs($this->admin())->getJson('/api/v1/admin/reports/management/sales?date_from=2026-01-02')->assertStatus(422);
        $this->actingAs($this->admin())->getJson('/api/v1/admin/reports/management/sales?date_from=2020-01-01&date_to=2026-01-01')->assertStatus(422);
    }

    public function test_two_lines_and_two_orders_are_aggregated_once_with_historical_prices(): void
    {
        $a = Product::create(['name' => 'A', 'slug' => 'a', 'price' => 999]);
        $b = Product::create(['name' => 'B', 'slug' => 'b', 'price' => 999]);
        $this->sale(30, 5, now(), [['product_id' => $a->id, 'quantity' => 2, 'price' => 10, 'subtotal' => 20], ['product_id' => $b->id, 'quantity' => 1, 'price' => 5, 'subtotal' => 5]]);
        $this->sale(15, 0, now(), [['product_id' => $a->id, 'quantity' => 1, 'price' => 15, 'subtotal' => 15]]);
        $json = $this->actingAs($this->admin())->getJson('/api/v1/admin/reports/management/sales');
        $json->assertOk()->assertJsonPath('metadata.row_count', 3)->assertJsonPath('totals.sales', 45)->assertJsonPath('totals.orders', 2)->assertJsonPath('totals.units', 4)->assertJsonPath('totals.line_subtotal', 40)->assertJsonPath('totals.average_ticket', 22.5);
        $rows = $json->json('rows');
        $this->assertEquals(10.0, $rows[0]['historical_unit_price']);
        $this->assertIsInt($rows[0]['quantity']);
        $this->assertIsNumeric($rows[0]['line_subtotal']);
    }

    public function test_empty_contract_and_sql_limit_are_stable(): void
    {
        Config::set('management_reports.query_limit', 1);
        $response = $this->actingAs($this->admin())->getJson('/api/v1/admin/reports/management/sales?period=this_year');
        $response->assertOk()->assertJsonStructure(['report', 'generated_at', 'applied_filters', 'metadata', 'totals', 'rows'])->assertJsonPath('metadata.limit', 1)->assertJsonPath('metadata.row_count', 0)->assertJsonPath('metadata.returned_count', 0)->assertJsonPath('metadata.truncated', false);
        $this->assertNull($response->json('totals.average_ticket'));
    }

    public function test_delivery_filter_changes_rows_and_totals_without_duplication(): void
    {
        $p = Product::create(['name' => 'P', 'slug' => 'p', 'price' => 10]);
        $this->sale(10, 0, now(), [['product_id' => $p->id, 'quantity' => 1, 'price' => 10, 'subtotal' => 10]]);
        $pickup = $this->sale(20, 0, now(), [['product_id' => $p->id, 'quantity' => 2, 'price' => 10, 'subtotal' => 20]]);
        $pickup->update(['delivery_type' => 'pickup']);
        $this->actingAs($this->admin())->getJson('/api/v1/admin/reports/management/sales?delivery_type=pickup')->assertOk()->assertJsonPath('totals.sales', 20)->assertJsonPath('totals.orders', 1)->assertJsonPath('totals.units', 2);
    }

    public function test_each_filter_changes_rows_and_all_totals_exactly(): void
    {
        $c1 = Category::create(['name' => 'C1', 'slug' => 'c1']);
        $c2 = Category::create(['name' => 'C2', 'slug' => 'c2']);
        $b1 = Brand::create(['name' => 'B1', 'slug' => 'b1']);
        $b2 = Brand::create(['name' => 'B2', 'slug' => 'b2']);
        $p1 = Product::create(['name' => 'P1', 'slug' => 'p1', 'price' => 10, 'category_id' => $c1->id, 'brand_id' => $b1->id]);
        $p2 = Product::create(['name' => 'P2', 'slug' => 'p2', 'price' => 20, 'category_id' => $c2->id, 'brand_id' => $b2->id]);
        $this->sale(10, 0, now(), [['product_id' => $p1->id, 'quantity' => 1, 'price' => 10, 'subtotal' => 10]], 'card');
        $second = $this->sale(20, 0, now(), [['product_id' => $p2->id, 'quantity' => 2, 'price' => 10, 'subtotal' => 20]], 'transfer');
        $second->update(['delivery_type' => 'pickup']);
        $admin = $this->admin();
        foreach (["category_id={$c1->id}" => [10, 1, 1], "brand_id={$b2->id}" => [20, 1, 2], 'delivery_type=pickup' => [20, 1, 2], 'payment_method=transfer' => [20, 1, 2], "category_id={$c2->id}&brand_id={$b2->id}&delivery_type=pickup&payment_method=transfer" => [20, 1, 2]] as $query => $expected) {
            $r = $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/sales?'.$query);
            $r->assertOk()->assertJsonPath('totals.sales', $expected[0])->assertJsonPath('totals.orders', $expected[1])->assertJsonPath('totals.units', $expected[2])->assertJsonPath('metadata.row_count', 1)->assertJsonPath('metadata.returned_count', 1);
        }
    }

    public function test_sales_reconciles_exactly_with_dashboard_for_all_supported_filters_and_dates(): void
    {
        $category = Category::create(['name' => 'RC', 'slug' => 'rc']);
        $brand = Brand::create(['name' => 'RB', 'slug' => 'rb']);
        $product = Product::create(['name' => 'RP', 'slug' => 'rp', 'price' => 10, 'category_id' => $category->id, 'brand_id' => $brand->id]);
        $inside = $this->sale(25, 5, now()->subHour(), [['product_id' => $product->id, 'quantity' => 2, 'price' => 10, 'subtotal' => 20]], 'transfer');
        $inside->update(['delivery_type' => 'pickup']);
        $outside = $this->sale(30, 0, now()->subDays(3), [['product_id' => $product->id, 'quantity' => 3, 'price' => 10, 'subtotal' => 30]]);
        $outside->update(['created_at' => now()->subDays(5), 'updated_at' => now()->subDays(3)]);
        $admin = $this->admin();
        foreach (['', 'category_id='.$category->id, 'brand_id='.$brand->id, 'delivery_type=pickup', 'payment_method=transfer', 'category_id='.$category->id.'&brand_id='.$brand->id.'&delivery_type=pickup&payment_method=transfer'] as $query) {
            $dashboard = $this->actingAs($admin)->getJson('/api/v1/admin/dashboard?period=this_year'.($query ? '&'.$query : ''))->json();
            $report = $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/sales?period=this_year'.($query ? '&'.$query : ''))->json();
            $this->assertSame((float) $dashboard['summary']['sales_net']['value'], (float) $report['totals']['sales'], $query);
            $this->assertSame((int) $dashboard['summary']['recognized_orders']['value'], (int) $report['totals']['orders'], $query);
            $this->assertSame((int) $dashboard['summary']['units_sold']['value'], (int) $report['totals']['units'], $query);
        }
        $from = now()->subDay()->toDateString();
        $this->actingAs($admin)->getJson('/api/v1/admin/dashboard?date_from='.$from.'&date_to='.now()->toDateString())->assertJsonPath('summary.recognized_orders.value', 1);
        $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/sales?date_from='.$from.'&date_to='.now()->toDateString())->assertJsonPath('totals.orders', 1);
    }

    public function test_pending_and_rejected_payments_are_excluded_but_approved_canceled_matches_dashboard(): void
    {
        $product = Product::create(['name' => 'Estados', 'slug' => 'estados', 'price' => 1]);
        $this->sale(10, 0, now(), [['product_id' => $product->id, 'quantity' => 1, 'price' => 10, 'subtotal' => 10]])->update(['status' => 'canceled']);
        $this->sale(20, 0, now(), [['product_id' => $product->id, 'quantity' => 1, 'price' => 20, 'subtotal' => 20]])->update(['payment_status' => 'pending']);
        $this->sale(30, 0, now(), [['product_id' => $product->id, 'quantity' => 1, 'price' => 30, 'subtotal' => 30]])->update(['payment_status' => 'rejected']);
        $admin = $this->admin();
        $dashboard = $this->actingAs($admin)->getJson('/api/v1/admin/dashboard')->json();
        $report = $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/sales')->json();
        $this->assertSame(10.0, (float) $report['totals']['sales']);
        $this->assertSame((float) $dashboard['summary']['sales_net']['value'], (float) $report['totals']['sales']);
    }

    public function test_sensitive_fixture_values_never_appear_in_json_or_csv(): void
    {
        $customer = User::factory()->create(['role' => 'customer', 'name' => 'CLIENTE-PRIVADO-XYZ', 'email' => 'privado-xyz@example.test', 'phone' => '999111222']);
        $product = Product::create(['name' => 'Producto privado', 'slug' => 'privado', 'price' => 1]);
        $order = $this->sale(1, 0, now(), [['product_id' => $product->id, 'quantity' => 1, 'price' => 1, 'subtotal' => 1]]);
        $order->user_id = $customer->id;
        $order->shipping_info = ['address' => 'DIRECCION-PRIVADA-XYZ', 'document' => 'DOC-XYZ'];
        $order->saveQuietly();
        $admin = $this->admin();
        $json = json_encode($this->actingAs($admin)->getJson('/api/v1/admin/reports/management/sales')->json(), JSON_UNESCAPED_UNICODE);
        $csv = $this->actingAs($admin)->get('/api/v1/admin/reports/management/sales/export')->streamedContent();
        foreach (['CLIENTE-PRIVADO-XYZ', 'privado-xyz@example.test', '999111222', 'DIRECCION-PRIVADA-XYZ', 'DOC-XYZ'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
            $this->assertStringNotContainsString($secret, $csv);
        }
    }
}
