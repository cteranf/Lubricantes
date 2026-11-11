<?php

namespace App\Jobs;

use App\Services\CustomerEmailVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendCustomerEmailVerification implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly ?int $userId,
        public readonly ?string $normalizedEmail = null,
        public readonly bool $recordSentEvent = true,
    ) {}

    public function handle(CustomerEmailVerificationService $verification): void
    {
        $verification->deliver($this->userId, $this->normalizedEmail, $this->recordSentEvent);
    }
}
