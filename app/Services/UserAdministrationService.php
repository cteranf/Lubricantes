<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class UserAdministrationService
{
    /** @param array<string, mixed> $data */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            return User::create([
                'name' => trim($data['name']),
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'role' => $data['role'],
                'can_deliver' => (bool) ($data['can_deliver'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, User $user, array $data): User
    {
        return DB::transaction(function () use ($actor, $user, $data) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
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

            return $locked;
        });
    }

    public function changeStatus(User $actor, User $user, bool $isActive): User
    {
        return DB::transaction(function () use ($actor, $user, $isActive) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $isActive && $locked->is($actor)) {
                throw new ConflictHttpException('No puedes desactivar tu propia cuenta.');
            }
            if (! $isActive) {
                $this->ensureLastActiveAdminIsNotRemoved($locked, 'customer');
            }

            $locked->is_active = $isActive;
            $locked->save();
            if (! $isActive) {
                $locked->tokens()->delete();
            }

            return $locked;
        });
    }

    public function resetPassword(User $user, string $password): User
    {
        return DB::transaction(function () use ($user, $password) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['password' => Hash::make($password)])->save();
            $locked->tokens()->delete();

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
