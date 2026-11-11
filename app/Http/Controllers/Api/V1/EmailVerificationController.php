<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CustomerEmailVerificationService;
use App\Support\AuditEventContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class EmailVerificationController extends Controller
{
    private const RESEND_MESSAGE = 'Si la cuenta puede verificarse, enviaremos instrucciones al correo indicado.';

    public function __construct(
        private CustomerEmailVerificationService $verification,
        private AuditService $audit
    ) {}

    public function confirm(Request $request, string $id, string $hash)
    {
        if ($request->isMethod('GET')) {
            if (! $request->hasValidSignature()) {
                return redirect('/email/verification?result=invalid');
            }

            if (preg_match('/\A[1-9][0-9]*\z/D', $id) !== 1 || preg_match('/\A[a-f0-9]{40}\z/D', $hash) !== 1) {
                return redirect('/email/verification?result=invalid');
            }

            $user = User::query()
                ->select(['id', 'role', 'email', 'email_verified_at'])
                ->whereKey($id)
                ->first();

            if (! $user || $user->role !== 'customer' || ! hash_equals(sha1((string) $user->email), $hash)) {
                return redirect('/email/verification?result=invalid');
            }

            if ($user->email_verified_at !== null) {
                return redirect('/email/verification?result=verified');
            }

            // Opening a pending signed link only loads the SPA confirmation screen.
            return view('welcome');
        }

        if (! $request->isMethod('POST') || ! $request->hasValidSignature()) {
            return response()->json([
                'message' => 'El enlace de verificación no es válido o venció.',
                'code' => 'email_verification_link_invalid',
            ], 403);
        }

        if (preg_match('/\A[1-9][0-9]*\z/D', $id) !== 1 || preg_match('/\A[a-f0-9]{40}\z/D', $hash) !== 1) {
            return response()->json([
                'message' => 'El enlace de verificación no es válido o venció.',
                'code' => 'email_verification_link_invalid',
            ], 403);
        }

        $outcome = DB::transaction(function () use ($request, $id, $hash): string {
            $user = User::query()->whereKey($id)->lockForUpdate()->first();
            if (! $user || $user->role !== 'customer' || ! hash_equals(sha1((string) $user->email), $hash)) {
                return 'invalid';
            }

            if ($user->email_verified_at !== null) {
                return 'already_verified';
            }

            $user->forceFill(['email_verified_at' => now()])->save();
            $this->audit->recordSystem(
                AuditEventContract::EMAIL_VERIFICATION_COMPLETED,
                AuditEventContract::RESULT_SUCCEEDED,
                auditableType: 'user',
                auditableId: (string) $user->getKey(),
                request: $request
            );

            return 'verified';
        });

        if ($outcome === 'invalid') {
            return response()->json([
                'message' => 'El enlace de verificación no es válido o venció.',
                'code' => 'email_verification_link_invalid',
            ], 403);
        }

        return response()->json([
            'message' => 'El correo quedó verificado. Ya puedes iniciar sesión.',
            'code' => $outcome === 'verified' ? 'email_verified' : 'email_already_verified',
        ]);
    }

    public function resend(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);
        $email = mb_strtolower(trim($data['email']));
        $secret = (string) config('app.key');
        $ipKey = 'email-verification:ip:'.hash_hmac('sha256', (string) $request->ip(), $secret);
        $emailKey = 'email-verification:email:'.hash_hmac('sha256', $email, $secret);
        $decay = max(60, (int) config('email_verification.resend.decay_seconds', 3600));
        $ipLimit = max(1, (int) config('email_verification.resend.ip_max_attempts', 20));
        $emailLimit = max(1, (int) config('email_verification.resend.email_max_attempts', 3));
        $allowed = ! RateLimiter::tooManyAttempts($ipKey, $ipLimit)
            && ! RateLimiter::tooManyAttempts($emailKey, $emailLimit);

        RateLimiter::hit($ipKey, $decay);
        RateLimiter::hit($emailKey, $decay);

        $this->audit->recordSystem(
            AuditEventContract::EMAIL_VERIFICATION_RESEND_REQUESTED,
            AuditEventContract::RESULT_SUCCEEDED,
            request: $request
        );

        if ($allowed) {
            $this->verification->queueResend($email);
        }

        return response()->json(['message' => self::RESEND_MESSAGE], 202);
    }
}
