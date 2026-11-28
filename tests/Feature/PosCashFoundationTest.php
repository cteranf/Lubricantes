<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\PosAuditEvent;
use App\Models\PosCashMovement;
use App\Models\PosCashSession;
use App\Models\PosRegister;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class PosCashFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_configures_register_and_explicit_assignment_is_independent_of_treasury_role(): void
    {
        [$branch, $warehouse] = $this->warehouse();
        $admin = $this->user('admin');
        $cashier = $this->user('treasury');
        $unassignedTreasury = $this->user('treasury');
        $register = $this->createRegister($admin, $warehouse);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/pos/registers/{$register->id}/cashiers/{$cashier->id}")->assertCreated();
        $this->getJson('/api/v1/admin/pos/registers')->assertOk()->assertJsonPath('data.0.branch.id', $branch->id);

        Sanctum::actingAs($unassignedTreasury);
        $this->getJson('/api/v1/pos/registers')->assertForbidden();

        Sanctum::actingAs($cashier);
        $this->getJson('/api/v1/pos/registers')->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotSame('admin', $cashier->role);
    }

    public function test_customer_cannot_be_assigned_and_admin_only_routes_remain_protected(): void
    {
        [, $warehouse] = $this->warehouse();
        $admin = $this->user('admin');
        $customer = $this->user('customer');
        $register = $this->createRegister($admin, $warehouse);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/pos/registers/{$register->id}/cashiers/{$customer->id}")->assertUnprocessable();

        Sanctum::actingAs($customer);
        $this->postJson('/api/v1/admin/pos/registers', ['warehouse_id' => $warehouse->id, 'code' => 'BLOCKED', 'name' => 'Blocked'])->assertForbidden();
        $this->getJson('/api/v1/pos/registers')->assertForbidden();
    }

    public function test_open_close_difference_reason_and_retries_preserve_actor_time_and_immutable_history(): void
    {
        [, $warehouse] = $this->warehouse();
        $admin = $this->user('admin');
        $cashier = $this->user('admin');
        $register = $this->createRegister($admin, $warehouse);
        $this->assign($admin, $register, $cashier);
        Sanctum::actingAs($cashier);

        $openKey = (string) Str::uuid();
        $opening = ['opening_cash' => '100.00', 'idempotency_key' => $openKey];
        $opened = $this->postJson("/api/v1/pos/registers/{$register->id}/sessions", $opening)->assertCreated()->json('data');
        $session = PosCashSession::findOrFail($opened['id']);
        $originalOpenedAt = $session->opened_at->toISOString();
        $this->postJson("/api/v1/pos/registers/{$register->id}/sessions", $opening)->assertOk()->assertJsonPath('data.id', $session->id);
        $this->assertSame($originalOpenedAt, $session->fresh()->opened_at->toISOString());
        $this->assertSame(1, PosCashMovement::where('session_id', $session->id)->count());
        $this->postJson("/api/v1/pos/registers/{$register->id}/sessions", ['opening_cash' => '50.00', 'idempotency_key' => (string) Str::uuid()])->assertConflict();

        $closeKey = (string) Str::uuid();
        $closePath = "/api/v1/pos/sessions/{$session->id}/close";
        $this->postJson($closePath, ['counted_cash' => '95.00', 'idempotency_key' => $closeKey])->assertUnprocessable();
        $this->assertSame(PosCashSession::OPEN, $session->fresh()->status);
        $this->assertDatabaseHas('pos_register_open_sessions', ['register_id' => $register->id, 'session_id' => $session->id]);

        $closePayload = ['counted_cash' => '95.00', 'closing_reason' => 'Conteo QA de diferencia', 'idempotency_key' => $closeKey];
        $closed = $this->postJson($closePath, $closePayload)->assertOk()->json('data');
        $session->refresh();
        $closedAt = $session->closed_at->toISOString();
        $this->assertSame('100.00', $session->expected_cash);
        $this->assertSame('95.00', $session->counted_cash);
        $this->assertSame('-5.00', $session->difference);
        $this->assertSame('Conteo QA de diferencia', $session->closing_reason);
        $this->assertSame($cashier->id, $session->closed_by);
        $this->assertSame($closedAt, $closed['closed_at']);
        $this->assertDatabaseMissing('pos_register_open_sessions', ['register_id' => $register->id]);
        $this->assertDatabaseMissing('pos_cashier_open_sessions', ['cashier_user_id' => $cashier->id]);

        $this->travel(5)->seconds();
        $this->postJson($closePath, $closePayload)->assertOk()->assertJsonPath('data.closed_at', $closedAt);
        $this->assertSame($closedAt, $session->fresh()->closed_at->toISOString());
        $this->postJson($closePath, ['counted_cash' => '100.00', 'closing_reason' => null, 'idempotency_key' => (string) Str::uuid()])->assertConflict();
        $this->assertSame(2, PosAuditEvent::where('session_id', $session->id)->count());

        $this->expectException(LogicException::class);
        $session->fresh()->update(['closing_reason' => 'retrospective']);
    }

    public function test_zero_difference_closes_without_reason_and_web_orders_default_to_web_with_optional_user(): void
    {
        [, $warehouse] = $this->warehouse();
        $admin = $this->user('admin');
        $cashier = $this->user('billing');
        $register = $this->createRegister($admin, $warehouse);
        $this->assign($admin, $register, $cashier);
        Sanctum::actingAs($cashier);
        $session = $this->postJson("/api/v1/pos/registers/{$register->id}/sessions", ['opening_cash' => '0.00', 'idempotency_key' => (string) Str::uuid()])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/pos/sessions/{$session}/close", ['counted_cash' => '0.00', 'idempotency_key' => (string) Str::uuid()])->assertOk();

        $webOrder = Order::create(['total' => '0.00', 'shipping_info' => [], 'status' => 'pending']);
        $this->assertSame('web', $webOrder->sales_channel);
        $posOrder = Order::create([
            'user_id' => null, 'sales_channel' => 'pos', 'pos_sold_by' => $cashier->id,
            'pos_register_id' => $register->id, 'pos_cash_session_id' => $session,
            'pos_buyer_snapshot' => ['name' => 'Comprador ocasional'], 'total' => '0.00',
            'shipping_info' => [], 'status' => 'delivered',
        ]);
        $this->assertNull($posOrder->user_id);
        $this->assertSame('pos', $posOrder->sales_channel);
        $this->assertSame('Comprador ocasional', $posOrder->pos_buyer_snapshot['name']);
        $this->assertSame($cashier->id, $posOrder->posSeller->id);
    }

    public function test_disabled_register_and_inactive_cashier_cannot_open(): void
    {
        [, $warehouse] = $this->warehouse();
        $admin = $this->user('admin');
        $cashier = $this->user('admin');
        $register = $this->createRegister($admin, $warehouse);
        $this->assign($admin, $register, $cashier);
        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/admin/pos/registers/{$register->id}", ['is_active' => false])->assertOk();
        Sanctum::actingAs($cashier);
        $this->postJson("/api/v1/pos/registers/{$register->id}/sessions", ['opening_cash' => '1.00', 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_cashier_access_is_scoped_to_assignment_warehouse_branch_and_responsible_user(): void
    {
        [$branchA, $warehouseA] = $this->warehouse();
        [$branchB, $warehouseB] = $this->warehouse();
        $admin = $this->user('admin');
        $cashierA = $this->user('admin');
        $cashierB = $this->user('treasury');
        $registerA = $this->createRegister($admin, $warehouseA);
        $registerB = $this->createRegister($admin, $warehouseB);
        $this->assign($admin, $registerA, $cashierA);
        $this->assign($admin, $registerB, $cashierB);

        Sanctum::actingAs($cashierA);
        $this->getJson("/api/v1/pos/registers/{$registerB->id}/session")->assertForbidden();
        $this->postJson("/api/v1/pos/registers/{$registerB->id}/sessions", [
            'opening_cash' => '0.00', 'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
        $sessionId = $this->postJson("/api/v1/pos/registers/{$registerA->id}/sessions", [
            'opening_cash' => '10.00', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($cashierB);
        $this->postJson("/api/v1/pos/sessions/{$sessionId}/close", [
            'counted_cash' => '10.00', 'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->getJson("/api/v1/pos/registers/{$registerA->id}/session")->assertForbidden();

        Sanctum::actingAs($admin);
        $branchB->update(['is_active' => false]);
        Sanctum::actingAs($cashierB);
        $this->postJson("/api/v1/pos/registers/{$registerB->id}/sessions", [
            'opening_cash' => '0.00', 'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable();
        $branchB->update(['is_active' => true]);
        $warehouseB->update(['is_active' => false]);
        $this->postJson("/api/v1/pos/registers/{$registerB->id}/sessions", [
            'opening_cash' => '0.00', 'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable();
        $this->assertTrue($branchA->fresh()->is_active);
        $this->assertTrue($warehouseA->fresh()->is_active);
    }

    public function test_pos_history_rejects_eloquent_bulk_updates_deletes_and_truncation(): void
    {
        [, $warehouse] = $this->warehouse();
        $admin = $this->user('admin');
        $cashier = $this->user('admin');
        $register = $this->createRegister($admin, $warehouse);
        $this->assign($admin, $register, $cashier);
        Sanctum::actingAs($cashier);
        $sessionId = $this->postJson("/api/v1/pos/registers/{$register->id}/sessions", [
            'opening_cash' => '20.00', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/pos/sessions/{$sessionId}/close", [
            'counted_cash' => '20.00', 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk();

        $this->assertEloquentMutationBlocked(fn () => PosCashSession::query()->whereKey($sessionId)->update(['status' => 'open']));
        $this->assertEloquentMutationBlocked(fn () => PosCashSession::query()->whereKey($sessionId)->delete());
        $this->assertEloquentMutationBlocked(fn () => PosCashMovement::query()->where('session_id', $sessionId)->update(['net_amount' => '0.00']));
        $this->assertEloquentMutationBlocked(fn () => PosCashMovement::query()->where('session_id', $sessionId)->delete());
        $this->assertEloquentMutationBlocked(fn () => PosAuditEvent::query()->where('session_id', $sessionId)->update(['event' => 'tampered']));
        $this->assertEloquentMutationBlocked(fn () => PosAuditEvent::query()->where('session_id', $sessionId)->delete());
        $this->assertEloquentMutationBlocked(fn () => PosAuditEvent::query()->truncate());

        $this->assertSame(PosCashSession::CLOSED, PosCashSession::findOrFail($sessionId)->status);
        $this->assertSame(1, PosCashMovement::where('session_id', $sessionId)->count());
        $this->assertSame(2, PosAuditEvent::where('session_id', $sessionId)->count());
    }

    private function assertEloquentMutationBlocked(callable $mutation): void
    {
        try {
            $mutation();
            $this->fail('Se esperaba que el builder Eloquent POS rechazara la mutación.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    private function warehouse(): array
    {
        $suffix = Str::lower(Str::random(8));
        $branch = Branch::create(['code' => 'POS-'.$suffix, 'name' => 'Sede POS QA', 'address' => 'Synthetic', 'is_active' => true]);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'code' => 'POS-'.$suffix, 'name' => 'Almacén POS QA', 'is_default' => false, 'is_active' => true]);

        return [$branch, $warehouse];
    }

    private function createRegister(User $admin, Warehouse $warehouse): PosRegister
    {
        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/v1/admin/pos/registers', [
            'warehouse_id' => $warehouse->id,
            'code' => 'POS-'.Str::upper(Str::random(8)),
            'name' => 'Caja QA',
        ])->assertCreated();

        return PosRegister::findOrFail($response->json('data.id'));
    }

    private function assign(User $admin, PosRegister $register, User $cashier): void
    {
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/pos/registers/{$register->id}/cashiers/{$cashier->id}")->assertCreated();
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }
}
