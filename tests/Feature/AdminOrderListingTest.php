<?php

namespace Tests\Feature;

use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\SyntheticMercadoPagoLink;
use Tests\TestCase;

class AdminOrderListingTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as protected refreshDatabaseAfterIsolation;
    }
    use SyntheticMercadoPagoLink;

    public function refreshDatabase(): void
    {
        $this->assertSyntheticDatabaseIsolation();
        $this->refreshDatabaseAfterIsolation();
    }

    public function test_listing_defaults_to_fifteen_and_returns_only_explicit_row_fields(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $order = $this->order($user, ['total' => '31.50']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/admin/orders')->assertOk();

        $response->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.from', 1)
            ->assertJsonPath('meta.to', 1)
            ->assertJsonPath('links.first', null)
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.customer_name', $user->name)
            ->assertJsonPath('data.0.total', '31.50')
            ->assertJsonPath('data.0.has_payment_submission', false)
            ->assertJsonPath('data.0.payment_submission_status', null)
            ->assertJsonMissingPath('data.0.items')
            ->assertJsonMissingPath('data.0.user')
            ->assertJsonMissingPath('data.0.payment_data')
            ->assertJsonMissingPath('data.0.paymentSubmission');
    }

    /** @dataProvider supportedPageSizes */
    public function test_supported_page_sizes_are_used_exactly(string $size): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->order($admin);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/orders?per_page='.$size)
            ->assertOk()
            ->assertJsonPath('meta.per_page', (int) $size);
    }

    /** @dataProvider invalidPageSizes */
    public function test_unsupported_page_sizes_are_rejected_without_order_queries(string $size): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $response = $this->assertRejectedWithoutOrderQueries('/api/v1/admin/orders?per_page='.urlencode($size));
        if ($size === '') {
            $response->assertJsonValidationErrors('per_page');
        }
    }

    public function test_array_page_size_is_rejected_without_order_queries(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $this->assertRejectedWithoutOrderQueries('/api/v1/admin/orders?per_page%5B%5D=15');
    }

    /** @dataProvider invalidPages */
    public function test_noncanonical_or_out_of_range_page_values_are_rejected(string $page): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $this->getJson('/api/v1/admin/orders?page='.urlencode($page))->assertUnprocessable();
    }

    public function test_page_two_is_deterministic_and_has_no_duplicate_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $createdAt = Carbon::parse('2026-03-01 12:00:00');
        $ids = [];
        for ($index = 0; $index < 16; $index++) {
            $ids[] = $this->order($admin, ['created_at' => $createdAt, 'updated_at' => $createdAt])->id;
        }
        Sanctum::actingAs($admin);

        $firstPage = $this->getJson('/api/v1/admin/orders?per_page=15')->assertOk();
        $secondPage = $this->getJson('/api/v1/admin/orders?page=2&per_page=15')->assertOk();

        $this->assertSame(array_reverse(array_slice($ids, 1)), array_column($firstPage->json('data'), 'id'));
        $this->assertSame([$ids[0]], array_column($secondPage->json('data'), 'id'));
        $this->assertSame(2, $secondPage->json('meta.current_page'));
        $this->assertSame(16, $secondPage->json('meta.total'));
        $this->assertSame(1, $secondPage->json('links.previous'));
        $this->assertSame([], array_values(array_intersect(array_column($firstPage->json('data'), 'id'), array_column($secondPage->json('data'), 'id'))));
    }

    public function test_page_beyond_last_is_clamped_to_last_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->order($admin);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/orders?page=999&per_page=15')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.from', 1);
    }

    public function test_sort_is_allowlisted_and_id_breaks_ties_deterministically(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $first = $this->order($admin, ['total' => '20.00']);
        $second = $this->order($admin, ['total' => '20.00']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/orders?sort=total&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id);

        $this->getJson('/api/v1/admin/orders?sort=payment_data')->assertUnprocessable();
        $this->getJson('/api/v1/admin/orders?sort=status')->assertUnprocessable();
        $this->getJson('/api/v1/admin/orders?unknown=value')->assertUnprocessable();
        $this->getJson('/api/v1/admin/orders?direction=sideways')->assertUnprocessable();
    }

    public function test_order_list_keeps_query_count_bounded_as_rows_increase(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        for ($index = 0; $index < 8; $index++) {
            $this->order($admin);
        }
        Sanctum::actingAs($admin);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/v1/admin/orders?per_page=25')->assertOk();
        $queries = collect(DB::getQueryLog())->filter(function (array $query): bool {
            return preg_match('/\b(orders|users|order_items|warehouses|inventory_reservations|payment_submissions)\b/i', $query['query']) === 1;
        });
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(8, $queries->count());
    }

    public function test_current_reservation_queries_remain_bounded_with_multiple_orders_and_items(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $inventoryService = app(InventoryService::class);
        $warehouse = $inventoryService->defaultWarehouse();
        $product = Product::create([
            'name' => 'Listado paginado '.Str::random(8),
            'slug' => 'listado-paginado-'.Str::uuid(),
            'sku' => 'LP-'.Str::upper(Str::random(10)),
            'price' => '10.00',
            'is_active' => true,
        ]);
        $inventoryService->initializeProduct($product, 100, $warehouse);

        for ($index = 0; $index < 2; $index++) {
            $this->orderWithReservedItems($admin, $product, $warehouse->id, $index);
        }
        Sanctum::actingAs($admin);
        $small = $this->measureOrderListQueries();

        for ($index = 2; $index < 8; $index++) {
            $this->orderWithReservedItems($admin, $product, $warehouse->id, $index);
        }
        $large = $this->measureOrderListQueries();

        $this->assertSame(1, $small['reservation_queries']);
        $this->assertSame(1, $large['reservation_queries']);
        $this->assertSame(7, $small['relevant_queries']);
        $this->assertSame(7, $large['relevant_queries']);
        $this->assertSame($small['relevant_queries'], $large['relevant_queries']);
        $this->assertSame(8, $large['row_count']);
    }

    public function test_advanced_filters_search_and_date_range_are_applied_before_pagination(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true, 'name' => 'Cliente Filtro Alfa']);
        $warehouse = app(InventoryService::class)->defaultWarehouse();
        $target = $this->order($customer, [
            'total' => '47.25',
            'payment_method' => 'transferencia',
            'payment_status' => 'approved',
            'fulfillment_status' => Order::FULFILLMENT_PREPARING,
            'delivery_type' => 'pickup',
            'created_at' => Carbon::parse('2026-02-03 00:00:00', config('app.timezone')),
            'updated_at' => Carbon::parse('2026-02-03 00:00:00', config('app.timezone')),
        ]);
        $decoy = $this->order($admin, [
            'total' => '80.00',
            'payment_method' => 'card',
            'payment_status' => 'pending',
            'fulfillment_status' => Order::FULFILLMENT_RESERVED,
            'delivery_type' => 'delivery',
            'created_at' => Carbon::parse('2026-02-02 23:59:59', config('app.timezone')),
            'updated_at' => Carbon::parse('2026-02-02 23:59:59', config('app.timezone')),
        ]);
        $decoyIds = [$decoy->id];
        for ($index = 0; $index < 15; $index++) {
            $decoyIds[] = $this->order($admin, [
                'total' => '80.00',
                'payment_method' => 'card',
                'payment_status' => 'pending',
                'fulfillment_status' => Order::FULFILLMENT_RESERVED,
                'delivery_type' => 'delivery',
            ])->id;
        }
        $this->attachListingItem($target, $warehouse->id, InventoryReservation::ACTIVE);
        $this->assertSame('2026-02-02 23:59:59', DB::table('orders')->where('id', $decoy->id)->value('created_at'));
        $this->assertSame('2026-02-03 00:00:00', DB::table('orders')->where('id', $target->id)->value('created_at'));

        Sanctum::actingAs($admin);
        $filters = [
            'order_id' => (string) $target->id,
            'customer' => 'cliente filtro',
            'fulfillment_status' => 'preparing',
            'payment_method' => 'transferencia',
            'payment_status' => 'approved',
            'warehouse_id' => (string) $warehouse->id,
            'reservation_status' => 'active',
            'delivery_type' => 'pickup',
        ];

        foreach ($filters as $key => $value) {
            $response = $this->getJson('/api/v1/admin/orders?'.http_build_query([$key => $value]))->assertOk();
            $this->assertSame([$target->id], array_column($response->json('data'), 'id'), 'Filter failed: '.$key);
            $this->assertSame(1, $response->json('meta.total'), 'Filter total failed: '.$key);
        }

        foreach ([['search', (string) $target->id], ['search', 'Cliente Filtro'], ['customer', 'FILTRO ALFA']] as [$key, $value]) {
            $response = $this->getJson('/api/v1/admin/orders?'.http_build_query([$key => $value]))->assertOk();
            $this->assertSame([$target->id], array_column($response->json('data'), 'id'));
            $this->assertSame(1, $response->json('meta.total'));
        }

        $response = $this->getJson('/api/v1/admin/orders?fulfillment_status=reserved&payment_method=card&per_page=15&sort=id&direction=asc&page=2')->assertOk();
        $this->assertSame([$decoyIds[15]], array_column($response->json('data'), 'id'));
        $this->assertSame(16, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.current_page'));
    }

    public function test_search_preserves_raw_id_canonicality_and_validates_the_untrimmed_length(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true, 'name' => 'Cliente Filtro Alfa']);
        $target = $this->order($customer);
        Sanctum::actingAs($admin);

        $canonical = $this->getJson('/api/v1/admin/orders?'.http_build_query(['search' => (string) $target->id]))->assertOk();
        $this->assertSame([$target->id], array_column($canonical->json('data'), 'id'));
        $this->assertSame(1, $canonical->json('meta.total'));

        foreach ([
            'leading and trailing spaces' => ' '.(string) $target->id.' ',
            'positive sign' => '+'.(string) $target->id,
            'leading zero' => '0'.(string) $target->id,
            'leading tab' => "\t".(string) $target->id,
            'trailing newline' => (string) $target->id."\n",
            'internal control whitespace' => '1'."\t".(string) $target->id,
        ] as $case => $search) {
            $response = $this->getJson('/api/v1/admin/orders?'.http_build_query(['search' => $search]))->assertOk();
            $this->assertNotContains($target->id, array_column($response->json('data'), 'id'), 'Noncanonical ID search matched the order: '.$case);
        }

        $textSearch = $this->getJson('/api/v1/admin/orders?'.http_build_query(['search' => ' Cliente Filtro ']))->assertOk();
        $this->assertSame([$target->id], array_column($textSearch->json('data'), 'id'));

        $this->getJson('/api/v1/admin/orders?'.http_build_query(['search' => str_repeat('x', 120)]))->assertOk();
        $this->getJson('/api/v1/admin/orders?'.http_build_query(['search' => str_repeat('x', 121)]))
            ->assertUnprocessable()->assertJsonValidationErrors('search');
        $this->getJson('/api/v1/admin/orders?'.http_build_query(['search' => " \t "]))
            ->assertUnprocessable()->assertJsonValidationErrors('search');
    }

    public function test_date_filters_use_inclusive_civil_days_and_exclusive_next_day_boundary(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $previous = $this->order($admin, ['created_at' => Carbon::parse('2026-02-02 23:59:59', config('app.timezone'))]);
        $start = $this->order($admin, ['created_at' => Carbon::parse('2026-02-03 00:00:00', config('app.timezone'))]);
        $end = $this->order($admin, ['created_at' => Carbon::parse('2026-02-03 23:59:59', config('app.timezone'))]);
        $next = $this->order($admin, ['created_at' => Carbon::parse('2026-02-04 00:00:00', config('app.timezone'))]);
        Sanctum::actingAs($admin);

        $from = $this->getJson('/api/v1/admin/orders?date_from=2026-02-03')->assertOk();
        $this->assertEqualsCanonicalizing([$start->id, $end->id, $next->id], array_column($from->json('data'), 'id'));
        $this->assertSame(3, $from->json('meta.total'));

        $to = $this->getJson('/api/v1/admin/orders?date_to=2026-02-03')->assertOk();
        $this->assertEqualsCanonicalizing([$previous->id, $start->id, $end->id], array_column($to->json('data'), 'id'));
        $this->assertSame(3, $to->json('meta.total'));

        $both = $this->getJson('/api/v1/admin/orders?date_from=2026-02-03&date_to=2026-02-03')->assertOk();
        $this->assertSame([$end->id, $start->id], array_column($both->json('data'), 'id'));
        $this->assertSame(2, $both->json('meta.total'));
    }

    public function test_total_minimum_uses_decimal_bound_and_excludes_lower_decoy(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $target = $this->order($admin, ['total' => '47.25']);
        $this->order($admin, ['total' => '20.00']);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/orders?total_min=47.25')->assertOk();
        $this->assertSame([$target->id], array_column($response->json('data'), 'id'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_total_maximum_uses_decimal_bound_and_excludes_higher_decoy(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $target = $this->order($admin, ['total' => '47.25']);
        $this->order($admin, ['total' => '80.00']);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/orders?total_max=47.25')->assertOk();
        $this->assertSame([$target->id], array_column($response->json('data'), 'id'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_total_range_combines_bounds_before_pagination(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $target = $this->order($admin, ['total' => '47.25']);
        $this->order($admin, ['total' => '20.00']);
        $this->order($admin, ['total' => '80.00']);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/orders?total_min=40.00&total_max=50.00')->assertOk();
        $this->assertSame([$target->id], array_column($response->json('data'), 'id'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_filter_ranges_and_noncanonical_values_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/orders?total_min=20.01&total_max=20.00')->assertUnprocessable();
        $this->getJson('/api/v1/admin/orders?date_from=2026-02-04&date_to=2026-02-03')->assertUnprocessable();
        $this->getJson('/api/v1/admin/orders?fulfillment_status=processing')->assertUnprocessable();
        $this->getJson('/api/v1/admin/orders?payment_method=unknown')->assertUnprocessable();
        $this->getJson('/api/v1/admin/orders?payment_status=unknown')->assertUnprocessable();
        $this->getJson('/api/v1/admin/orders?reservation_status=unknown')->assertUnprocessable();
        $this->getJson('/api/v1/admin/orders?delivery_type=unknown')->assertUnprocessable();
        $this->getJson('/api/v1/admin/orders?order_id=01')->assertUnprocessable();
        $this->getJson('/api/v1/admin/orders?warehouse_id[]=1')->assertUnprocessable();
        $this->getJson('/api/v1/admin/orders?customer[]=test')->assertUnprocessable();
        $this->getJson('/api/v1/admin/orders?search[]=test')->assertUnprocessable();
        $this->getJson('/api/v1/admin/orders?date_from=2026-2-03')->assertUnprocessable();
        $this->getJson('/api/v1/admin/orders?unapproved=value')->assertUnprocessable();
    }

    public function test_raw_query_parameter_names_are_exact_unique_and_decoded_once(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true, 'name' => 'Cliente%20Literal']);
        $target = $this->order($customer);
        Sanctum::actingAs($admin);

        foreach ([
            'dot alias' => 'order.id=1',
            'encoded dot alias' => 'order%2Eid=1',
            'plus in name' => 'order+id=1',
            'space in name' => 'order%20id=1',
            'array suffix' => 'order_id%5B%5D=1',
            'nested key' => 'filter%5Bsearch%5D=x',
            'unknown name' => 'not_allowed=x',
            'empty name' => '=x',
            'duplicate name' => 'search=a&search=b',
            'encoded duplicate name' => 'search=a&%73earch=b',
            'malformed percent encoding in key' => 'order%2Gid=1',
            'malformed percent encoding in value' => 'search=%ZZ',
        ] as $case => $rawQuery) {
            $this->getJson('/api/v1/admin/orders?'.$rawQuery)
                ->assertUnprocessable();
        }

        $canonical = $this->getJson('/api/v1/admin/orders?order_id='.$target->id)->assertOk();
        $this->assertSame([$target->id], array_column($canonical->json('data'), 'id'));

        $encodedCanonicalName = $this->getJson('/api/v1/admin/orders?%73earch=Cliente%2520Literal')->assertOk();
        $this->assertSame([$target->id], array_column($encodedCanonicalName->json('data'), 'id'));
        $this->assertSame(1, $encodedCanonicalName->json('meta.total'));

        $inputSeparators = (string) ini_get('arg_separator.input');
        if (str_contains($inputSeparators, ';')) {
            $this->getJson('/api/v1/admin/orders?search=x;not_allowed=y')->assertUnprocessable();
        }
    }

    public function test_unfiltered_and_filtered_empty_states_report_exact_server_total(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/orders?search=sin-resultados-sintetico')
            ->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0)->assertJsonPath('meta.last_page', 1);
    }

    public function test_non_admin_cannot_read_admin_order_list(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'customer', 'is_active' => true]));

        $this->getJson('/api/v1/admin/orders')->assertForbidden();
    }

    public static function supportedPageSizes(): array
    {
        return ['15' => ['15'], '25' => ['25'], '50' => ['50'], '100' => ['100']];
    }

    public static function invalidPageSizes(): array
    {
        return [
            'leading zero 15' => ['015'], 'leading zero 25' => ['025'], 'leading zero 100' => ['0100'],
            'positive sign' => ['+15'], 'negative sign' => ['-15'], 'decimal' => ['15.0'],
            'scientific 15' => ['15e0'], 'scientific 100' => ['1e2'], 'leading whitespace' => [' 15'],
            'trailing whitespace' => ['15 '], 'trailing newline' => ["15\n"], 'zero' => ['0'],
            'one' => ['1'], 'fourteen' => ['14'], 'sixteen' => ['16'], 'ten' => ['10'],
            'twenty' => ['20'], 'over maximum' => ['101'], 'empty' => [''], 'null string' => ['null'],
        ];
    }

    public static function invalidPages(): array
    {
        return ['zero' => ['0'], 'negative' => ['-1'], 'plus sign' => ['+1'], 'decimal' => ['1.5'], 'scientific' => ['1e2'], 'leading zero' => ['01'], 'overflow' => [str_repeat('9', strlen((string) PHP_INT_MAX) + 1)]];
    }

    private function order(User $user, array $overrides = []): Order
    {
        $timestamp = $overrides['created_at'] ?? now();
        $order = Order::create(array_merge([
            'user_id' => $user->id,
            'status' => 'pending',
            'fulfillment_status' => Order::FULFILLMENT_RESERVED,
            'total' => '20.00',
            'shipping_info' => ['address' => 'Prueba de paginación'],
            'payment_method' => 'transferencia',
            'payment_status' => 'pending',
            'delivery_type' => 'delivery',
            'tracking_status' => 'pending',
            'created_at' => $timestamp,
            'updated_at' => $overrides['updated_at'] ?? $timestamp,
        ], $overrides));

        if (array_key_exists('created_at', $overrides) || array_key_exists('updated_at', $overrides)) {
            DB::table('orders')->where('id', $order->id)->update([
                'created_at' => ($overrides['created_at'] ?? $timestamp)->format('Y-m-d H:i:s'),
                'updated_at' => ($overrides['updated_at'] ?? $timestamp)->format('Y-m-d H:i:s'),
            ]);
            $order->refresh();
        }

        return $order;
    }

    private function assertRejectedWithoutOrderQueries(string $uri)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson($uri)->assertUnprocessable();
        $orderQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/\borders\b/i', $query['query']) === 1);
        DB::disableQueryLog();

        $this->assertCount(0, $orderQueries);

        return $response;
    }

    private function orderWithReservedItems(User $admin, Product $product, int $warehouseId, int $index): void
    {
        $order = $this->order($admin);
        for ($itemIndex = 0; $itemIndex < 3; $itemIndex++) {
            $item = OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
                'quantity' => 1,
                'price' => '10.00',
                'subtotal' => '10.00',
            ]);
            InventoryReservation::unguarded(fn () => InventoryReservation::create([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'reservation_sequence' => 1,
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
                'quantity' => 1,
                'status' => $itemIndex === 0 ? InventoryReservation::ACTIVE : InventoryReservation::CONSUMED,
                'expires_at' => now()->addHour(),
                'consumed_at' => $itemIndex === 0 ? null : now(),
                'released_at' => null,
                'idempotency_key' => 'admin-list-'.$index.'-'.$itemIndex.'-'.Str::uuid(),
                'metadata' => ['synthetic' => true],
            ]));
        }
    }

    private function attachListingItem(Order $order, int $warehouseId, string $reservationStatus): void
    {
        $product = Product::create([
            'name' => 'Filtro sintético '.Str::random(8),
            'slug' => 'filtro-sintetico-'.Str::uuid(),
            'sku' => 'FS-'.Str::upper(Str::random(10)),
            'price' => '47.25',
            'is_active' => true,
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouseId,
            'quantity' => 1,
            'price' => '47.25',
            'subtotal' => '47.25',
        ]);
        InventoryReservation::unguarded(fn () => InventoryReservation::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'reservation_sequence' => 1,
            'product_id' => $product->id,
            'warehouse_id' => $warehouseId,
            'quantity' => 1,
            'status' => $reservationStatus,
            'expires_at' => now()->addHour(),
            'idempotency_key' => 'filter-'.Str::uuid(),
            'metadata' => ['synthetic' => true],
        ]));
    }

    private function measureOrderListQueries(): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/admin/orders?per_page=15')->assertOk();
        $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/\b(orders|users|order_items|warehouses|inventory_reservations|payment_submissions)\b/i', $query['query']) === 1);
        $reservationQueries = $queries->filter(fn (array $query): bool => preg_match('/\binventory_reservations\b/i', $query['query']) === 1);
        DB::disableQueryLog();

        return [
            'relevant_queries' => $queries->count(),
            'reservation_queries' => $reservationQueries->count(),
            'row_count' => count($response->json('data')),
        ];
    }
}
