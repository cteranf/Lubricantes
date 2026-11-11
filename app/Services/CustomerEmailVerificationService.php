<?php

namespace App\Services;

use App\Jobs\SendCustomerEmailVerification;
use App\Models\User;
use App\Notifications\VerifyCustomerEmail;
use App\Support\AuditEventContract;
use App\Support\EmailVerificationMailer;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class CustomerEmailVerificationService
{
    private const ASYNC_QUEUE_DRIVERS = ['database', 'beanstalkd', 'sqs', 'redis'];

    public function __construct(private Dispatcher $notifications) {}

    public function queueForUser(User $user): void
    {
        if ($user->role !== 'customer' || $user->email_verified_at !== null) {
            return;
        }

        $this->assertAsynchronousQueue();
        SendCustomerEmailVerification::dispatch((int) $user->getKey())
            ->onConnection((string) config('email_verification.queue_connection'))
            ->onQueue((string) config('email_verification.queue'));
    }

    public function queueResend(string $normalizedEmail): void
    {
        $this->assertAsynchronousQueue();
        SendCustomerEmailVerification::dispatch(null, $normalizedEmail, false)
            ->onConnection((string) config('email_verification.queue_connection'))
            ->onQueue((string) config('email_verification.queue'));
    }

    public function deliver(?int $userId, ?string $normalizedEmail, bool $recordSentEvent): void
    {
        $user = $userId !== null
            ? User::query()->find($userId)
            : (is_string($normalizedEmail) ? User::query()->where('email', $normalizedEmail)->first() : null);

        if (! $user || ! $user->is_active || $user->role !== 'customer' || $user->email_verified_at !== null) {
            return;
        }

        try {
            EmailVerificationMailer::configuredName();
            $this->notifications->send($user, new VerifyCustomerEmail);
        } catch (Throwable) {
            $this->logGenericDeliveryFailure();

            return;
        }

        if ($recordSentEvent) {
            try {
                app(AuditService::class)->recordSystem(
                    AuditEventContract::EMAIL_VERIFICATION_SENT,
                    AuditEventContract::RESULT_SUCCEEDED,
                    auditableType: 'user',
                    auditableId: (string) $user->getKey()
                );
            } catch (Throwable) {
                $this->logGenericDeliveryFailure();
            }
        }
    }

    private function assertAsynchronousQueue(): void
    {
        $connectionName = config('email_verification.queue_connection');
        $connections = config('queue.connections', []);
        $connection = is_string($connectionName) ? ($connections[$connectionName] ?? null) : null;

        if (! is_array($connection)
            || ! is_string($connection['driver'] ?? null)
            || ! in_array($connection['driver'], self::ASYNC_QUEUE_DRIVERS, true)) {
            throw new InvalidArgumentException('Email verification queue must use an asynchronous connection.');
        }

        if (! is_string(config('email_verification.queue')) || config('email_verification.queue') === '') {
            throw new InvalidArgumentException('Email verification queue configuration is invalid.');
        }
    }

    private function logGenericDeliveryFailure(): void
    {
        try {
            Log::warning('Customer email verification delivery failed.');
        } catch (Throwable) {
            // Logging must not retry a notification that was already accepted by the mail transport.
        }
    }
}
