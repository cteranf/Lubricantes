<?php

namespace App\Services;

use App\Models\User;
use App\Support\AuditEventContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class UserAdministrationService
{
    public function __construct(private AuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor) {
            $user = User::create([
                'name' => trim($data['name']),
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'role' => $data['role'],
                'can_deliver' => (bool) ($data['can_deliver'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
            $this->audit->recordUser(
                AuditEventContract::USER_CREATED,
                $actor,
                AuditEventContract::RESULT_SUCCEEDED,
                auditableType: 'user',
                auditableId: (string) $user->id,
                newValues: ['role' => $user->role, 'is_active' => $user->is_active]
            );

            return $user;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, User $user, array $data): User
    {
        return DB::transaction(function () use ($actor, $user, $data) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $oldRole = $locked->role;
            $oldCanDeliver = $locked->can_deliver;
            $oldName = $locked->name;
            $oldEmail = $locked->email;
            $oldPhone = $locked->phone;
            if ($locked->is($actor) && $data['role'] !== 'admin') {
                throw new ConflictHttpException('No puedes degradar tu propia cuenta administrativa.');
            }
            $this->ensureLastActiveAdminIsNotRemoved($locked, $data['role']);
            $locked->fill([
                'name' => trim($data['name']),
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => $data['role'],
                'can_deliver' => array_key_exists('can_deliver', $data) ? (bool) $data['can_deliver'] : $locked->can_deliver,
            ])->save();

            if ($oldRole !== $locked->role) {
                $this->audit->recordUser(
                    AuditEventContract::USER_ROLE_CHANGED,
                    $actor,
                    AuditEventContract::RESULT_SUCCEEDED,
                    auditableType: 'user',
                    auditableId: (string) $locked->id,
                    oldValues: ['role' => $oldRole],
                    newValues: ['role' => $locked->role]
                );
            }
            $changedFields = [];
            foreach (['name' => [$oldName, $locked->name], 'email' => [$oldEmail, $locked->email], 'phone' => [$oldPhone, $locked->phone], 'can_deliver' => [$oldCanDeliver, $locked->can_deliver]] as $field => [$before, $after]) {
                if ($before !== $after) {
                    $changedFields[] = $field;
                }
            }
            if ($changedFields !== []) {
                $this->audit->recordUser(
                    AuditEventContract::USER_UPDATED,
                    $actor,
                    AuditEventContract::RESULT_SUCCEEDED,
                    auditableType: 'user',
                    auditableId: (string) $locked->id,
                    metadata: ['changed_fields' => implode(',', $changedFields)]
                );
            }

            return $locked;
        });
    }

    public function changeStatus(User $actor, User $user, bool $isActive): User
    {
        return DB::transaction(function () use ($actor, $user, $isActive) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $wasActive = $locked->is_active;
            if (! $isActive && $locked->is($actor)) {
                throw new ConflictHttpException('No puedes desactivar tu propia cuenta.');
            }
            if (! $isActive) {
                $this->ensureLastActiveAdminIsNotRemoved($locked, 'customer');
            }

            $locked->is_active = $isActive;
            $locked->save();
            $revokedCount = 0;
            if (! $isActive) {
                $revokedCount = $locked->tokens()->delete();
            }
            if ($wasActive !== $isActive) {
                $this->audit->recordUser(
                    AuditEventContract::USER_STATUS_CHANGED,
                    $actor,
                    AuditEventContract::RESULT_SUCCEEDED,
                    auditableType: 'user',
                    auditableId: (string) $locked->id,
                    oldValues: ['is_active' => $wasActive],
                    newValues: ['is_active' => $isActive]
                );
            }
            if ($revokedCount > 0) {
                $this->audit->recordUser(
                    AuditEventContract::AUTH_SESSION_REVOKED,
                    $actor,
                    AuditEventContract::RESULT_SUCCEEDED,
                    auditableType: 'user',
                    auditableId: (string) $locked->id,
                    metadata: ['reason' => 'user_deactivated', 'revoked_count' => $revokedCount]
                );
            }

            return $locked;
        });
    }

    public function resetPassword(User $actor, User $user, string $password): User
    {
        return DB::transaction(function () use ($actor, $user, $password) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['password' => Hash::make($password)])->save();
            $revokedCount = $locked->tokens()->delete();
            $this->audit->recordUser(AuditEventContract::USER_PASSWORD_RESET, $actor, AuditEventContract::RESULT_SUCCEEDED, auditableType: 'user', auditableId: (string) $locked->id);
            if ($revokedCount > 0) {
                $this->audit->recordUser(
                    AuditEventContract::AUTH_SESSION_REVOKED,
                    $actor,
                    AuditEventContract::RESULT_SUCCEEDED,
                    auditableType: 'user',
                    auditableId: (string) $locked->id,
                    metadata: ['reason' => 'password_reset', 'revoked_count' => $revokedCount]
                );
            }

            return $locked;
        });
    }

    private function ensureLastActiveAdminIsNotRemoved(User $user, string $nextRole): void
    {
        if ($user->role !== 'admin' || ! $user->is_active || $nextRole === 'admin') {
            return;
        }

        $activeAdmins = User::where('role', 'admin')->where('is_active', true)->lockForUpdate()->count();
        if ($activeAdmins <= 1) {
            throw new ConflictHttpException('Debe permanecer al menos un administrador activo.');
        }
    }
}
