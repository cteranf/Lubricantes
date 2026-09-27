<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\PaymentReceivingAccount;
use App\Models\PaymentSubmission;
use App\Models\PaymentSubmissionHistory;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerTransferPaymentOptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_read_only_operational_default_options_without_mutations(): void
    {
        Storage::fake('treasury_qr');
        [$owner, $order] = $this->order();
        $yape = $this->account('YAPE-01', 'yape', true, true);
        Storage::disk('treasury_qr')->put('receiving-accounts/yape.png', 'png');
        $yape->update(['qr_path' => 'receiving-accounts/yape.png', 'qr_disk' => 'treasury_qr']);
        $plin = $this->account('PLIN-01', 'plin', true, true);
        Storage::disk('treasury_qr')->put('receiving-accounts/plin.png', 'png');
        $plin->update(['qr_path' => 'receiving-accounts/plin.png', 'qr_disk' => 'treasury_qr']);
        $bank = $this->account('BANK-01', 'bank_transfer', true, true);
        $this->account('SECONDARY', 'bank_transfer', true, false);
        $this->account('INACTIVE', 'yape', false, false);
        $before = [$order->fresh()->updated_at->toIso8601String(), $order->fresh()->reserved_until->toIso8601String(), InventoryReservation::whereOrderId($order->id)->first()->expires_at->toIso8601String()];
        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/v1/orders/'.$order->id.'/payment-options')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Pragma', 'no-cache');
        $response->assertJsonCount(3, 'data')->assertJsonMissingPath('data.0.phone')->assertJsonMissingPath('data.0.qr_path');
        $this->assertSame($before[0], $order->fresh()->updated_at->toIso8601String());
        $this->assertSame($before[1], $order->fresh()->reserved_until->toIso8601String());
        $this->assertSame($before[2], InventoryReservation::whereOrderId($order->id)->first()->expires_at->toIso8601String());
        $this->get('/api/v1/orders/'.$order->id.'/payment-options/'.$yape->id.'/qr')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/api/v1/orders/'.$order->id.'/payment-options/'.$bank->id.'/qr')->assertNotFound();
    }

    public function test_access_and_ineligible_reservations_are_rejected(): void
    {
        [$owner, $order] = $this->order();
        $this->getJson('/api/v1/orders/'.$order->id.'/payment-options')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/orders/'.$order->id.'/payment-options')->assertNotFound();
        Sanctum::actingAs($owner);
        InventoryReservation::whereOrderId($order->id)->update(['status' => InventoryReservation::EXPIRED]);
        $this->getJson('/api/v1/orders/'.$order->id.'/payment-options')->assertUnprocessable()->assertJsonPath('code', 'reservation_expired');
    }

    public function test_inactive_and_privileged_non_owners_cannot_discover_an_order(): void
    {
        [$owner, $order] = $this->order();
        Sanctum::actingAs(User::factory()->create(['is_active' => false]));
        $this->getJson('/api/v1/orders/'.$order->id.'/payment-options')->assertForbidden()->assertJsonPath('code', 'account_inactive');
        foreach (['treasury', 'admin'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/v1/orders/'.$order->id.'/payment-options')->assertNotFound();
        }
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/orders/999999/payment-options')->assertNotFound();
    }

    /** @dataProvider ineligibleOrderStates */
    public function test_ineligible_order_or_reservation_is_rejected_by_options(string $field, mixed $value): void
    {
        [$owner, $order] = $this->order();
        $value = $value === 'past' ? now()->subMinute() : $value;

        if (str_starts_with($field, 'reservation.')) {
            InventoryReservation::whereOrderId($order->id)->update([substr($field, 12) => $value]);
        } elseif ($field === 'no_active_reservation') {
            InventoryReservation::whereOrderId($order->id)->update(['status' => InventoryReservation::RELEASED]);
        } else {
            $order->update([$field => $value]);
        }

        if ($field === 'reserved_until') {
            $this->assertTrue($order->fresh()->reserved_until->isPast());
        }
        if ($field === 'reservation.expires_at') {
            $this->assertTrue(InventoryReservation::whereOrderId($order->id)->firstOrFail()->expires_at->isPast());
        }

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/orders/'.$order->id.'/payment-options')->assertUnprocessable();
    }

    public static function ineligibleOrderStates(): array
    {
        return [
            'other method' => ['payment_method', 'card'], 'approved' => ['payment_status', 'approved'], 'rejected payment' => ['payment_status', 'rejected'], 'canceled' => ['status', 'canceled'], 'delivered' => ['status', 'delivered'], 'picked up' => ['tracking_status', 'picked_up'], 'expired order reservation' => ['reserved_until', 'past'], 'released reservation' => ['reservation.status', InventoryReservation::RELEASED], 'consumed reservation' => ['reservation.status', InventoryReservation::CONSUMED], 'expired active reservation' => ['reservation.expires_at', 'past'], 'no active reservation' => ['no_active_reservation', null],
        ];
    }

    public function test_qr_uses_the_same_order_eligibility_guard(): void
    {
        Storage::fake('treasury_qr');
        [$owner, $order] = $this->order();
        $yape = $this->account('YAPE-QR', 'yape', true, true);
        Storage::disk('treasury_qr')->put('receiving-accounts/yape-qr.png', 'png');
        $yape->update([
            'qr_path' => 'receiving-accounts/yape-qr.png',
            'qr_disk' => 'treasury_qr',
        ]);

        Sanctum::actingAs($owner);
        $this->get('/api/v1/orders/'.$order->id.'/payment-options/'.$yape->id.'/qr')->assertOk();

        $order->update(['payment_status' => 'approved']);
        $this->getJson('/api/v1/orders/'.$order->id.'/payment-options/'.$yape->id.'/qr')
            ->assertUnprocessable();
    }

    public function test_operational_yape_and_plin_qrs_are_private_and_stream_the_stored_bytes(): void
    {
        Storage::fake('treasury_qr');
        [$owner, $order] = $this->order();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $yape = $this->account('YAPE-PNG', 'yape', true, true);
        $plin = $this->account('PLIN-PNG', 'plin', true, true);
        foreach ([$yape, $plin] as $account) {
            $path = 'receiving-accounts/'.$account->code.'.png';
            Storage::disk('treasury_qr')->put($path, $png);
            $account->update(['qr_path' => $path, 'qr_disk' => 'treasury_qr']);
        }

        Sanctum::actingAs($owner);
        foreach ([$yape, $plin] as $account) {
            $response = $this->get('/api/v1/orders/'.$order->id.'/payment-options/'.$account->id.'/qr')
                ->assertOk()
                ->assertHeader('Content-Type', 'image/png')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader('Pragma', 'no-cache');
            $this->assertSame($png, $response->streamedContent());
        }
    }

    public function test_qr_rejects_accounts_that_are_not_current_customer_payment_options_without_leaking_details(): void
    {
        Storage::fake('treasury_qr');
        [$owner, $order] = $this->order();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $cases = [
            'bank transfer' => fn () => $this->account('BANK-QR', 'bank_transfer', true, true),
            'secondary yape' => fn () => $this->account('YAPE-SECONDARY', 'yape', true, false),
            'inactive plin' => fn () => $this->account('PLIN-INACTIVE', 'plin', false, false),
            'yape without path' => fn () => $this->account('YAPE-NO-PATH', 'yape', true, true),
            'missing stored file' => fn () => $this->account('PLIN-MISSING', 'plin', true, true),
            'unsupported stored mime' => fn () => $this->account('YAPE-TEXT', 'yape', true, true),
            'non-PEN yape' => fn () => $this->account('YAPE-USD', 'yape', true, true, 'USD'),
        ];
        Sanctum::actingAs($owner);
        foreach ($cases as $name => $makeAccount) {
            PaymentReceivingAccount::query()->delete();
            $account = $makeAccount();
            if ($name !== 'yape without path') {
                $path = 'receiving-accounts/'.$account->code.($name === 'unsupported stored mime' ? '.txt' : '.png');
                if ($name !== 'missing stored file') {
                    Storage::disk('treasury_qr')->put($path, $name === 'unsupported stored mime' ? 'not an image' : $png);
                }
                $account->update(['qr_path' => $path, 'qr_disk' => 'treasury_qr']);
            }
            $response = $this->getJson('/api/v1/orders/'.$order->id.'/payment-options/'.$account->id.'/qr')->assertNotFound();
            $content = $response->getContent();
            foreach (['qr_path', 'qr_disk', 'receiving-accounts', 'SQL', 'stack trace'] as $sensitive) {
                $this->assertStringNotContainsString($sensitive, $content);
            }
        }
    }

    public function test_options_and_qr_are_strictly_read_only(): void
    {
        Storage::fake('treasury_qr');
        [$owner, $order] = $this->order();
        $account = $this->account('YAPE-READ-ONLY', 'yape', true, true);
        $path = 'receiving-accounts/read-only.png';
        Storage::disk('treasury_qr')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $account->update(['qr_path' => $path, 'qr_disk' => 'treasury_qr']);
        $reservation = InventoryReservation::whereOrderId($order->id)->firstOrFail();
        $inventory = WarehouseInventory::firstOrFail();
        $before = [
            'order' => $this->orderReadState($order),
            'reservation' => $this->reservationReadState($reservation),
            'inventory' => $inventory->fresh()->only(['quantity', 'reserved_quantity']),
            'counts' => [
                PaymentSubmission::count(),
                PaymentSubmissionHistory::count(),
                PaymentTransaction::count(),
                InventoryMovement::count(),
            ],
        ];

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/orders/'.$order->id.'/payment-options')->assertOk();
        $this->get('/api/v1/orders/'.$order->id.'/payment-options/'.$account->id.'/qr')->assertOk();

        $this->assertSame($before['order'], $this->orderReadState($order));
        $this->assertSame($before['reservation'], $this->reservationReadState($reservation));
        $this->assertSame($before['inventory'], $inventory->fresh()->only(['quantity', 'reserved_quantity']));
        $this->assertSame($before['counts'], [
            PaymentSubmission::count(),
            PaymentSubmissionHistory::count(),
            PaymentTransaction::count(),
            InventoryMovement::count(),
        ]);
    }

    private function order(): array
    {
        $owner = User::factory()->create();
        $branch = Branch::create(['code' => 'B1', 'name' => 'B', 'address' => 'Dirección', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'W1', 'name' => 'W', 'is_active' => true, 'is_default' => false]);
        $product = Product::create(['name' => 'P', 'slug' => 'p', 'sku' => 'P1', 'price' => 10, 'sale_price' => 10, 'is_active' => true]);
        WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 2, 'reserved_quantity' => 1]);
        $order = Order::create(['user_id' => $owner->id, 'status' => 'pending', 'total' => '10.00', 'shipping_info' => ['recipient_name' => 'Cliente'], 'payment_method' => 'transferencia', 'payment_status' => 'pending', 'reserved_until' => now()->addHour(), 'delivery_type' => 'delivery', 'tracking_status' => 'pending']);
        $item = $order->items()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'price' => '10.00', 'subtotal' => '10.00']);
        InventoryReservation::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'status' => 'active', 'expires_at' => now()->addHour(), 'idempotency_key' => 'r-'.$order->id]);

        return [$owner, $order];
    }

    private function account(string $code, string $channel, bool $active, bool $default, string $currency = 'PEN'): PaymentReceivingAccount
    {
        return PaymentReceivingAccount::create(['code' => $code, 'channel' => $channel, 'display_name' => $code, 'holder_name' => 'Titular', 'currency' => $currency, 'phone' => in_array($channel, ['yape', 'plin']) ? '999999999' : null, 'bank_name' => $channel === 'bank_transfer' ? 'Banco' : null, 'account_number' => $channel === 'bank_transfer' ? '123456' : null, 'is_active' => $active, 'is_default' => $default]);
    }

    private function orderReadState(Order $order): array
    {
        $order = $order->fresh();

        return [
            'payment_status' => $order->payment_status,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'status' => $order->status,
            'reserved_until' => $order->reserved_until?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];
    }

    private function reservationReadState(InventoryReservation $reservation): array
    {
        $reservation = $reservation->fresh();

        return [
            'status' => $reservation->status,
            'expires_at' => $reservation->expires_at?->toIso8601String(),
            'updated_at' => $reservation->updated_at?->toIso8601String(),
        ];
    }
}
