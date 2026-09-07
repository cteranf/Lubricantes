<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementSalesCsvExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_export_has_bom_spanish_headers_and_neutralizes_formula_text(): void
    {
        $product = Product::create(['name' => '=Producto', 'slug' => 'formula', 'price' => 10]);
        $order = Order::create(['status' => 'confirmed', 'total' => 10, 'subtotal' => 10, 'shipping_amount' => 0, 'shipping_info' => [], 'payment_method' => 'card', 'delivery_type' => 'pickup', 'payment_status' => 'approved', 'paid_at' => now()]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 10, 'subtotal' => 10]);
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/api/v1/admin/reports/management/sales/export?period=this_year');
        $response->assertOk();
        $content = method_exists($response, 'streamedContent') ? $response->streamedContent() : $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Fecha reconocida', $content);
        $this->assertStringContainsString("'=Producto", $content);
    }

    public function test_export_authorization_and_empty_csv_contract(): void
    {
        $this->withHeaders(['Accept' => 'application/json'])->get('/api/v1/admin/reports/management/sales/export')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'customer']))->get('/api/v1/admin/reports/management/sales/export')->assertForbidden();
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/api/v1/admin/reports/management/sales/export');
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $header = str_getcsv(strtok(substr($content, 3), "\n"));
        $this->assertSame(['Fecha reconocida', 'Pedido', 'Modalidad', 'Método de pago', 'Estado de pago', 'Producto', 'SKU', 'Categoría', 'Marca', 'Cantidad', 'Precio unitario', 'Subtotal de línea', 'Total del pedido'], $header);
    }

    public function test_adversarial_text_is_neutralized_and_structural_csv_is_preserved(): void
    {
        $values = ['=1+1', '+SUM(A1:A2)', '-CMD', '@SUM(A1:A2)', "\t=tab", "\r=cr", '  =spaces', '"comillas"', 'coma, valor', "salto\nlinea", 'Árbol Perú'];
        $order = Order::create(['status' => 'confirmed', 'total' => -55, 'subtotal' => -55, 'shipping_amount' => 0, 'shipping_info' => [], 'payment_method' => 'card', 'delivery_type' => 'delivery', 'payment_status' => 'approved', 'paid_at' => now()]);
        foreach ($values as $index => $value) {
            $product = Product::create(['name' => $value, 'slug' => 'adv-'.$index, 'price' => -5]);
            OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => -5, 'subtotal' => -5]);
        }
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/api/v1/admin/reports/management/sales/export');
        $content = $response->streamedContent();
        $this->assertSame("\xEF\xBB\xBF", substr($content, 0, 3));
        foreach ($values as $value) {
            if (preg_match('/^\s*[=+\-@\t\r]/', $value)) {
                $this->assertStringContainsString("'".$value, $content);
            } else {
                $this->assertStringContainsString($value, $content);
            }
        }
        $this->assertStringContainsString('-5', $content);
        $this->assertStringNotContainsString("'-5", $content);
    }

    public function test_export_limit_rejects_without_streaming_partial_csv(): void
    {
        config(['management_reports.export_limit' => 0]);
        $product = Product::create(['name' => 'Limit', 'slug' => 'limit', 'price' => 1]);
        $order = Order::create(['status' => 'confirmed', 'total' => 1, 'subtotal' => 1, 'shipping_amount' => 0, 'shipping_info' => [], 'payment_method' => 'card', 'delivery_type' => 'delivery', 'payment_status' => 'approved', 'paid_at' => now()]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 1, 'subtotal' => 1]);
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson('/api/v1/admin/reports/management/sales/export');
        $response->assertStatus(422)->assertJsonPath('message', 'El reporte supera el límite de exportación.');
    }

    public function test_csv_filters_match_json_rows_and_no_files_are_created(): void
    {
        $before = collect(glob(storage_path('app/*')) ?: [])->map(fn ($p) => basename($p))->sort()->values()->all();
        $category = Category::create(['name' => 'CSV C', 'slug' => 'csv-c']);
        $brand = Brand::create(['name' => 'CSV B', 'slug' => 'csv-b']);
        $p = Product::create(['name' => 'CSV P', 'slug' => 'csv-p', 'price' => 2, 'category_id' => $category->id, 'brand_id' => $brand->id]);
        $order = Order::create(['status' => 'confirmed', 'total' => 2, 'subtotal' => 2, 'shipping_amount' => 0, 'shipping_info' => [], 'payment_method' => 'transfer', 'delivery_type' => 'pickup', 'payment_status' => 'approved', 'paid_at' => now()]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $p->id, 'quantity' => 1, 'price' => 2, 'subtotal' => 2]);
        $admin = User::factory()->create(['role' => 'admin']);
        $query = 'category_id='.$category->id.'&brand_id='.$brand->id.'&delivery_type=pickup&payment_method=transfer';
        $json = $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/sales?'.$query)->json();
        $csvResponse = $this->actingAs($admin)->get('/api/v1/admin/reports/management/sales/export?'.$query);
        $csvResponse->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertMatchesRegularExpression('/attachment;\s*filename=reporte-sales-[0-9_]+\.csv/', (string) $csvResponse->headers->get('content-disposition'));
        $content = $csvResponse->streamedContent();
        $lines = preg_split('/\r?\n/', trim(substr($content, 3)));
        $parsed = array_map(fn ($line) => str_getcsv($line), array_values(array_filter($lines, fn ($line) => $line !== '')));
        $this->assertCount(count($json['rows']) + 1, $parsed);
        $this->assertSame($json['rows'][0]['product_name'], $parsed[1][5]);
        $this->assertSame($json['rows'][0]['sku'], $parsed[1][6]);
        $after = collect(glob(storage_path('app/*')) ?: [])->map(fn ($p) => basename($p))->sort()->values()->all();
        $this->assertSame($before, $after);
    }

    public function test_export_equal_limit_succeeds_and_json_limit_remains_independent(): void
    {
        config(['management_reports.export_limit' => 1, 'management_reports.query_limit' => 1]);
        $p = Product::create(['name' => 'Exact', 'slug' => 'exact', 'price' => 1]);
        $o = Order::create(['status' => 'confirmed', 'total' => 1, 'subtotal' => 1, 'shipping_amount' => 0, 'shipping_info' => [], 'payment_method' => 'card', 'delivery_type' => 'delivery', 'payment_status' => 'approved', 'paid_at' => now()]);
        OrderItem::create(['order_id' => $o->id, 'product_id' => $p->id, 'quantity' => 1, 'price' => 1, 'subtotal' => 1]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/api/v1/admin/reports/management/sales/export')->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/admin/reports/management/sales')->assertJsonPath('metadata.limit', 1)->assertJsonPath('metadata.truncated', false);
    }
}
