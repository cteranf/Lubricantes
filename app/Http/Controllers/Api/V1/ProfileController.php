<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfilePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CustomerEmailVerificationService;
use App\Support\AuditEventContract;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function __construct(
        private AuditService $audit,
        private CustomerEmailVerificationService $verification
    ) {}

    public function show(): UserResource
    {
        return new UserResource(request()->user());
    }

    public function update(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();
        $data = $request->validated();
        [$updated, $shouldSendVerification] = DB::transaction(function () use ($user, $data, $request): array {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $before = ['name' => $locked->name, 'email' => $locked->email, 'phone' => $locked->phone];
            $emailChanged = $before['email'] !== $data['email'];
            if ($emailChanged && $locked->role === 'customer') {
                $locked->email_verified_at = null;
            }
            $locked->fill(['name' => trim($data['name']), 'email' => $data['email'], 'phone' => $data['phone'] ?? null])->save();
            $changed = [];
            foreach (['name', 'email', 'phone'] as $field) {
                if ($before[$field] !== $locked->{$field}) {
                    $changed[] = $field;
                }
            }
            if ($changed !== []) {
                $this->audit->recordUser(
                    AuditEventContract::USER_UPDATED,
                    $locked,
                    AuditEventContract::RESULT_SUCCEEDED,
                    auditableType: 'user',
                    auditableId: (string) $locked->id,
                    metadata: ['changed_fields' => implode(',', $changed)],
                    request: $request
                );
            }

            return [$locked, $emailChanged && $locked->role === 'customer'];
        });

        if ($shouldSendVerification) {
            $this->verification->queueForUser($updated);
        }

        return new UserResource($updated->refresh());
    }

    public function password(UpdateProfilePasswordRequest $request)
    {
        $data = $request->validated();
        DB::transaction(function () use ($data, $request): void {
            $authenticatedUserId = $request->user()->getAuthIdentifier();
            $locked = User::whereKey($authenticatedUserId)->lockForUpdate()->firstOrFail();
            if ((string) $locked->getAuthIdentifier() !== (string) $authenticatedUserId) {
                throw (new ModelNotFoundException)->setModel(User::class, [$authenticatedUserId]);
            }
            if (! Hash::check($data['current_password'], $locked->password)) {
                throw ValidationException::withMessages(['current_password' => ['La contraseña actual es incorrecta.']]);
            }
            if (Hash::check($data['password'], $locked->password)) {
                throw ValidationException::withMessages(['password' => ['La nueva contraseña debe ser diferente a la actual.']]);
            }
            $locked->forceFill(['password' => Hash::make($data['password'])])->save();
            $revokedCount = $locked->tokens()->delete();
            $this->audit->recordUser(
                AuditEventContract::USER_PASSWORD_CHANGED,
                $locked,
                AuditEventContract::RESULT_SUCCEEDED,
                auditableType: 'user',
                auditableId: (string) $locked->id,
                request: $request
            );
            if ($revokedCount > 0) {
                $this->audit->recordUser(
                    AuditEventContract::AUTH_SESSION_REVOKED,
                    $locked,
                    AuditEventContract::RESULT_SUCCEEDED,
                    auditableType: 'user',
                    auditableId: (string) $locked->id,
                    metadata: ['reason' => 'password_changed', 'revoked_count' => $revokedCount],
                    request: $request
                );
            }
        });

        return response()->json(['message' => 'Contraseña actualizada. Inicia sesión nuevamente.', 'requires_login' => true]);
    }
}
