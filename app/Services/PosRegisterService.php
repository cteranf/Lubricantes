<?php

namespace App\Services;

use App\Models\PosAuditEvent;
use App\Models\PosCashierAssignment;
use App\Models\PosRegister;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class PosRegisterService
{
    public function create(array $data, User $actor): PosRegister
    {
        return DB::transaction(function () use ($data, $actor): PosRegister {
            $warehouse = Warehouse::query()->whereKey($data['warehouse_id'])->lockForUpdate()->firstOrFail();
            if (! $warehouse->is_active || ! $warehouse->branch()->where('is_active', true)->exists()) {
                throw new UnprocessableEntityHttpException('La caja requiere un almacén y una sede activos.');
            }
            $register = PosRegister::create([
                'warehouse_id' => $warehouse->id,
                'code' => mb_strtoupper(trim($data['code'])),
                'name' => trim($data['name']),
                'is_active' => true,
                'created_by' => $actor->id,
            ]);
            $this->audit($actor, $register, 'register_created', null, $this->registerSnapshot($register));

            return $register->load('warehouse.branch');
        });
    }

    public function setActive(PosRegister $register, bool $active, User $actor): PosRegister
    {
        return DB::transaction(function () use ($register, $active, $actor): PosRegister {
            $locked = PosRegister::query()->whereKey($register->id)->lockForUpdate()->firstOrFail();
            if (! $active && DB::table('pos_register_open_sessions')->where('register_id', $locked->id)->exists()) {
                throw new ConflictHttpException('Cierra la sesión abierta antes de desactivar esta caja.');
            }
            if ($active && ! $locked->warehouse()->where('is_active', true)->whereHas('branch', fn ($branch) => $branch->where('is_active', true))->exists()) {
                throw new UnprocessableEntityHttpException('La caja no se puede activar mientras su almacén o sede esté inactivo.');
            }
            $before = $this->registerSnapshot($locked);
            $locked->update(['is_active' => $active]);
            if ($locked->wasChanged('is_active')) {
                $this->audit($actor, $locked, $active ? 'register_activated' : 'register_deactivated', $before, $this->registerSnapshot($locked));
            }

            return $locked->load('warehouse.branch');
        });
    }

    public function assign(PosRegister $register, User $cashier, User $actor): PosCashierAssignment
    {
        return DB::transaction(function () use ($register, $cashier, $actor): PosCashierAssignment {
            $lockedRegister = PosRegister::query()->whereKey($register->id)->lockForUpdate()->firstOrFail();
            $lockedCashier = User::query()->whereKey($cashier->id)->lockForUpdate()->firstOrFail();
            if (! $lockedRegister->is_active || ! $lockedCashier->is_active) {
                throw new UnprocessableEntityHttpException('La caja y la cuenta asignada deben estar activas.');
            }
            if ($lockedCashier->role === 'customer') {
                throw new UnprocessableEntityHttpException('Una cuenta de cliente no puede recibir asignación de cajero.');
            }

            $assignment = PosCashierAssignment::query()->where('register_id', $lockedRegister->id)->where('user_id', $lockedCashier->id)->lockForUpdate()->first();
            if ($assignment?->is_active) {
                return $assignment->load('user');
            }
            if ($assignment) {
                $before = ['is_active' => false, 'revoked_at' => $assignment->revoked_at?->toISOString()];
                $assignment->update(['assigned_by' => $actor->id, 'is_active' => true, 'assigned_at' => now(), 'revoked_at' => null]);
            } else {
                $assignment = PosCashierAssignment::create([
                    'register_id' => $lockedRegister->id, 'user_id' => $lockedCashier->id,
                    'assigned_by' => $actor->id, 'is_active' => true, 'assigned_at' => now(),
                ]);
                $before = null;
            }
            $this->audit($actor, $lockedRegister, 'cashier_assigned', $before, ['user_id' => $lockedCashier->id, 'is_active' => true], $assignment->id);

            return $assignment->load('user');
        });
    }

    public function revoke(PosRegister $register, User $cashier, User $actor): PosCashierAssignment
    {
        return DB::transaction(function () use ($register, $cashier, $actor): PosCashierAssignment {
            $lockedRegister = PosRegister::query()->whereKey($register->id)->lockForUpdate()->firstOrFail();
            if (DB::table('pos_register_open_sessions')->where('register_id', $lockedRegister->id)->exists()) {
                $sessionCashier = DB::table('pos_register_open_sessions')->join('pos_cash_sessions', 'pos_cash_sessions.id', '=', 'pos_register_open_sessions.session_id')
                    ->where('pos_register_open_sessions.register_id', $lockedRegister->id)->value('pos_cash_sessions.cashier_user_id');
                if ((int) $sessionCashier === (int) $cashier->id) {
                    throw new ConflictHttpException('No se puede revocar al responsable mientras tenga una sesión abierta.');
                }
            }
            $assignment = PosCashierAssignment::query()->where('register_id', $lockedRegister->id)->where('user_id', $cashier->id)->lockForUpdate()->firstOrFail();
            if (! $assignment->is_active) {
                return $assignment->load('user');
            }
            $before = ['user_id' => (int) $assignment->user_id, 'is_active' => true];
            $assignment->update(['is_active' => false, 'revoked_at' => now()]);
            $this->audit($actor, $lockedRegister, 'cashier_revoked', $before, ['user_id' => (int) $assignment->user_id, 'is_active' => false], $assignment->id);

            return $assignment->load('user');
        });
    }

    private function registerSnapshot(PosRegister $register): array
    {
        return ['warehouse_id' => (int) $register->warehouse_id, 'code' => $register->code, 'name' => $register->name, 'is_active' => (bool) $register->is_active];
    }

    private function audit(User $actor, PosRegister $register, string $event, ?array $before, ?array $after, ?int $assignmentId = null): void
    {
        PosAuditEvent::create([
            'register_id' => $register->id, 'assignment_id' => $assignmentId, 'actor_user_id' => $actor->id,
            'event' => $event, 'before_values' => $before, 'after_values' => $after, 'created_at' => now(),
        ]);
    }
}
