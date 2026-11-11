<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CustomerEmailVerificationService;
use App\Support\AuditEventContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function __construct(
        private AuditService $audit,
        private CustomerEmailVerificationService $verification
    ) {}

    public function register(Request $request)
    {
        $payload = $request->all();
        $payload['email'] = mb_strtolower(trim((string) ($payload['email'] ?? '')));
        $validator = Validator::make($payload, [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = DB::transaction(function () use ($request, $payload) {
            $user = User::create([
                'name' => $request->name,
                'email' => $payload['email'],
                'password' => Hash::make($payload['password']),
                'role' => 'customer',
            ]);
            $this->audit->recordUser(
                AuditEventContract::USER_CREATED,
                null,
                AuditEventContract::RESULT_SUCCEEDED,
                auditableType: 'user',
                auditableId: (string) $user->id,
                newValues: ['role' => 'customer', 'is_active' => true],
                request: $request
            );

            return $user;
        });

        $this->verification->queueForUser($user);

        return response()->json([
            'message' => 'Registro completado. Revisa tu correo para verificar la cuenta.',
            'code' => 'email_verification_required',
        ], 201);
    }

    public function login(Request $request)
    {
        $emailInput = $request->input('email');
        $passwordInput = $request->input('password');
        $email = is_string($emailInput) ? mb_strtolower(trim($emailInput)) : '';
        $password = is_string($passwordInput) ? $passwordInput : '';

        $authenticated = DB::transaction(function () use ($email, $password, $request): ?array {
            $user = User::whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();

            if (! $user
                || mb_strtolower(trim((string) $user->email)) !== $email
                || ! $user->is_active
                || ! Hash::check($password, $user->password)) {
                return null;
            }

            if ($user->role === 'customer' && $user->email_verified_at === null) {
                return ['unverified' => $user];
            }

            $token = $user->createToken('auth_token')->plainTextToken;
            $this->audit->recordUser(
                AuditEventContract::AUTH_LOGIN_SUCCEEDED,
                $user,
                AuditEventContract::RESULT_SUCCEEDED,
                auditableType: 'user',
                auditableId: (string) $user->getAuthIdentifier(),
                request: $request
            );

            return ['user' => $user, 'token' => $token];
        });

        if ($authenticated === null) {
            $this->audit->recordFailureOpen(
                AuditEventContract::AUTH_LOGIN_FAILED,
                null,
                AuditEventContract::RESULT_FAILED,
                AuditEventContract::FAILURE_INVALID_CREDENTIALS,
                request: $request
            );

            return response()->json([
                'message' => 'Invalid login details',
            ], 401);
        }

        if (isset($authenticated['unverified'])) {
            /** @var User $unverified */
            $unverified = $authenticated['unverified'];
            $this->audit->recordFailureOpen(
                AuditEventContract::AUTH_LOGIN_UNVERIFIED_REJECTED,
                $unverified,
                AuditEventContract::RESULT_DENIED,
                AuditEventContract::FAILURE_EMAIL_VERIFICATION_REQUIRED,
                auditableType: 'user',
                auditableId: (string) $unverified->getAuthIdentifier(),
                request: $request
            );

            return response()->json([
                'message' => 'Debes verificar tu correo antes de iniciar sesión.',
                'code' => 'email_verification_required',
            ], 403);
        }

        /** @var User $user */
        $user = $authenticated['user'];
        $token = $authenticated['token'];

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => (new UserResource($user))->resolve($request),
        ]);
    }

    public function logout(Request $request)
    {
        DB::transaction(function () use ($request): void {
            $user = $request->user();
            $user->currentAccessToken()->delete();
            $this->audit->recordUser(
                AuditEventContract::AUTH_LOGOUT,
                $user,
                AuditEventContract::RESULT_SUCCEEDED,
                auditableType: 'user',
                auditableId: (string) $user->id,
                request: $request
            );
        });

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function user(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
