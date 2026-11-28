<?php

namespace App\Services;

use App\Models\PosAuditEvent;
use App\Models\PosCashierAssignment;
use App\Models\PosCashMovement;
use App\Models\PosCashSession;
use App\Models\PosRegister;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class PosCashSessionService
{
    public function __construct(private MoneyService $money) {}

    public function open(PosRegister $register, User $cashier, string $openingCash, string $idempotencyKey): PosCashSession
    {
        $openingMinor = $this->money->toMinor($openingCash);
        if ($openingMinor < 0) {
            throw new UnprocessableEntityHttpException('El fondo inicial no puede ser negativo.');
        }
        $requestHash = hash('sha256', implode('|', [(int) $register->id, (int) $cashier->id, $openingMinor]));

        try {
            return DB::transaction(function () use ($register, $cashier, $openingMinor, $idempotencyKey, $requestHash): PosCashSession {
                $lockedRegister = PosRegister::query()->whereKey($register->id)->lockForUpdate()->firstOrFail();
                if (! $lockedRegister->is_active || ! $lockedRegister->warehouse()->where('is_active', true)->whereHas('branch', fn ($branch) => $branch->where('is_active', true))->exists()) {
                    throw new UnprocessableEntityHttpException('La caja, el almacén o la sede están inactivos.');
                }
                $this->assertAssignedCashier($lockedRegister, $cashier, lock: true);

                $existing = PosCashSession::query()->where('opening_idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    if ((int) $existing->register_id !== (int) $lockedRegister->id
                        || (int) $existing->cashier_user_id !== (int) $cashier->id
                        || ! hash_equals($existing->opening_request_hash, $requestHash)) {
                        throw new ConflictHttpException('La clave de apertura ya se utilizó con otros datos.');
                    }

                    return $existing->load('register.warehouse.branch', 'cashier');
                }

                if (DB::table('pos_register_open_sessions')->where('register_id', $lockedRegister->id)->exists()) {
                    throw new ConflictHttpException('La caja ya tiene una sesión abierta.');
                }
                if (DB::table('pos_cashier_open_sessions')->where('cashier_user_id', $cashier->id)->exists()) {
                    throw new ConflictHttpException('El cajero ya tiene otra sesión abierta.');
                }

                $now = now();
                $session = PosCashSession::create([
                    'register_id' => $lockedRegister->id,
                    'cashier_user_id' => $cashier->id,
                    'status' => PosCashSession::OPEN,
                    'opening_cash' => $this->money->fromMinor($openingMinor),
                    'opened_at' => $now,
                    'opening_idempotency_key' => $idempotencyKey,
                    'opening_request_hash' => $requestHash,
                ]);
                DB::table('pos_register_open_sessions')->insert([
                    'register_id' => $lockedRegister->id, 'session_id' => $session->id, 'created_at' => $now,
                ]);
                DB::table('pos_cashier_open_sessions')->insert([
                    'cashier_user_id' => $cashier->id, 'register_id' => $lockedRegister->id,
                    'session_id' => $session->id, 'created_at' => $now,
                ]);
                PosCashMovement::create([
                    'session_id' => $session->id,
                    'actor_user_id' => $cashier->id,
                    'kind' => 'opening',
                    'net_amount' => $this->money->fromMinor($openingMinor),
                    'idempotency_key' => 'pos-session-'.$session->id.'-opening',
                    'created_at' => $now,
                ]);
                $this->audit('session_opened', $cashier, $lockedRegister, $session, null, $this->sessionSnapshot($session));

                return $session->load('register.warehouse.branch', 'cashier');
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            throw new ConflictHttpException('La caja o el cajero ya tiene una sesión abierta, o la clave de apertura ya existe.', $exception);
        }
    }

    public function close(PosCashSession $session, User $cashier, string $countedCash, ?string $reason, string $idempotencyKey): PosCashSession
    {
        $countedMinor = $this->money->toMinor($countedCash);
        if ($countedMinor < 0) {
            throw new UnprocessableEntityHttpException('El efectivo contado no puede ser negativo.');
        }
        $reason = $reason === null ? null : trim($reason);
        $requestHash = hash('sha256', implode('|', [(int) $session->id, (int) $cashier->id, $countedMinor, (string) $reason]));

        return DB::transaction(function () use ($session, $cashier, $countedMinor, $reason, $idempotencyKey, $requestHash): PosCashSession {
            $registerId = (int) PosCashSession::query()->whereKey($session->id)->value('register_id');
            $register = PosRegister::query()->whereKey($registerId)->lockForUpdate()->firstOrFail();
            $locked = PosCashSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->cashier_user_id !== (int) $cashier->id) {
                throw new AccessDeniedHttpException('Solo el cajero responsable puede cerrar esta sesión.');
            }

            if ($locked->status === PosCashSession::CLOSED) {
                if ($locked->closing_idempotency_key === $idempotencyKey
                    && $locked->closing_request_hash !== null
                    && hash_equals($locked->closing_request_hash, $requestHash)) {
                    return $locked->load('register.warehouse.branch', 'cashier', 'closedBy');
                }
                throw new ConflictHttpException('La sesión ya fue cerrada con otra solicitud.');
            }

            $this->assertAssignedCashier($register, $cashier, lock: true);
            if ((int) DB::table('pos_cashier_open_sessions')->where('cashier_user_id', $cashier->id)->value('session_id') !== (int) $locked->id
                || (int) DB::table('pos_register_open_sessions')->where('register_id', $register->id)->value('session_id') !== (int) $locked->id) {
                throw new ConflictHttpException('La sesión no coincide con los controles de sesión abierta.');
            }
            if ($reason !== null && mb_strlen($reason) > 1000) {
                throw new UnprocessableEntityHttpException('El motivo de diferencia no puede superar 1000 caracteres.');
            }

            $expectedMinor = $this->money->toMinor((string) DB::table('pos_cash_movements')->where('session_id', $locked->id)->sum('net_amount'));
            $differenceMinor = $countedMinor - $expectedMinor;
            if ($differenceMinor !== 0 && ($reason === null || $reason === '')) {
                throw new UnprocessableEntityHttpException('Indica el motivo cuando el efectivo contado no coincide con el esperado.');
            }
            if (DB::table('pos_cash_sessions')->where('closing_idempotency_key', $idempotencyKey)->where('id', '!=', $locked->id)->exists()) {
                throw new ConflictHttpException('La clave de cierre ya fue utilizada en otra sesión.');
            }

            $before = $this->sessionSnapshot($locked);
            $closedAt = now();
            $closingAttributes = [
                'status' => PosCashSession::CLOSED,
                'closed_by' => $cashier->id,
                'closed_at' => $closedAt,
                'expected_cash' => $this->money->fromMinor($expectedMinor),
                'counted_cash' => $this->money->fromMinor($countedMinor),
                'difference' => $this->money->fromMinor($differenceMinor),
                'closing_reason' => $differenceMinor === 0 ? null : $reason,
                'closing_idempotency_key' => $idempotencyKey,
                'closing_request_hash' => $requestHash,
                'updated_at' => $closedAt,
            ];
            $updated = DB::table('pos_cash_sessions')->where('id', $locked->id)->where('status', PosCashSession::OPEN)->update($closingAttributes);
            if ($updated !== 1) {
                throw new ConflictHttpException('La sesión dejó de estar abierta antes de completar el cierre.');
            }
            $locked->forceFill($closingAttributes);
            DB::table('pos_register_open_sessions')->where('register_id', $register->id)->where('session_id', $locked->id)->delete();
            DB::table('pos_cashier_open_sessions')->where('cashier_user_id', $cashier->id)->where('session_id', $locked->id)->delete();
            $this->audit('session_closed', $cashier, $register, $locked, $before, $this->sessionSnapshot($locked));

            return $locked->load('register.warehouse.branch', 'cashier', 'closedBy');
        });
    }

    /**
     * Primitive for the next POS phase. Call inside the sale transaction
     * before touching inventory so sale confirmation and close serialize on
     * register then session.
     */
    public function lockOpenSessionForSale(int $sessionId, User $cashier): PosCashSession
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('El bloqueo de sesión POS requiere una transacción exterior.');
        }
        $registerId = (int) PosCashSession::query()->whereKey($sessionId)->value('register_id');
        $register = PosRegister::query()->whereKey($registerId)->lockForUpdate()->firstOrFail();
        $session = PosCashSession::query()->whereKey($sessionId)->lockForUpdate()->firstOrFail();
        if ($session->status !== PosCashSession::OPEN || (int) $session->cashier_user_id !== (int) $cashier->id
            || (int) DB::table('pos_register_open_sessions')->where('register_id', $register->id)->value('session_id') !== (int) $session->id) {
            throw new ConflictHttpException('La sesión POS ya no está abierta para este cajero.');
        }
        $this->assertAssignedCashier($register, $cashier, lock: true);

        return $session;
    }

    public function assertAssignedCashier(PosRegister $register, User $cashier, bool $lock = false): PosCashierAssignment
    {
        if (! $cashier->is_active || $cashier->role === 'customer') {
            throw new AccessDeniedHttpException('La cuenta de cajero está inactiva.');
        }
        $query = PosCashierAssignment::query()
            ->where('register_id', $register->id)
            ->where('user_id', $cashier->id)
            ->where('is_active', true);
        if ($lock) {
            $query->lockForUpdate();
        }
        $assignment = $query->first();
        if (! $assignment) {
            throw new AccessDeniedHttpException('No tienes permiso de cajero asignado a esta caja.');
        }

        return $assignment;
    }

    private function audit(string $event, User $actor, PosRegister $register, ?PosCashSession $session, ?array $before, ?array $after, ?int $assignmentId = null): void
    {
        PosAuditEvent::create([
            'register_id' => $register->id,
            'session_id' => $session?->id,
            'assignment_id' => $assignmentId,
            'actor_user_id' => $actor->id,
            'event' => $event,
            'before_values' => $before,
            'after_values' => $after,
            'created_at' => now(),
        ]);
    }

    private function sessionSnapshot(PosCashSession $session): array
    {
        return [
            'status' => $session->status,
            'cashier_user_id' => (int) $session->cashier_user_id,
            'opening_cash' => (string) $session->opening_cash,
            'opened_at' => $session->opened_at?->toISOString(),
            'closed_at' => $session->closed_at?->toISOString(),
            'expected_cash' => $session->expected_cash === null ? null : (string) $session->expected_cash,
            'counted_cash' => $session->counted_cash === null ? null : (string) $session->counted_cash,
            'difference' => $session->difference === null ? null : (string) $session->difference,
        ];
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[1] ?? ''), ['19', '1062'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique constraint');
    }
}
