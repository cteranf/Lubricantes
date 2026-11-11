<?php

namespace Tests\Unit;

use App\Jobs\SendCustomerEmailVerification;
use App\Models\User;
use App\Notifications\VerifyCustomerEmail;
use App\Services\CustomerEmailVerificationService;
use App\Support\EmailVerificationMailer;
use App\Support\EmailVerificationSettings;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class EmailVerificationConfigurationTest extends TestCase
{
    public function test_expiration_is_clamped_to_the_approved_range(): void
    {
        $this->assertSame(1, EmailVerificationSettings::expiration(0));
        $this->assertSame(1, EmailVerificationSettings::expiration(-9));
        $this->assertSame(1440, EmailVerificationSettings::expiration(1441));
        $this->assertSame(75, EmailVerificationSettings::expiration(75));
        $this->assertSame(60, EmailVerificationSettings::expiration('invalid'));
    }

    public function test_email_verification_rejects_log_mailer(): void
    {
        config(['email_verification.mailer' => 'log']);

        try {
            EmailVerificationMailer::configuredName();
            $this->fail('The log mailer must not be accepted for verification links.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Email verification mailer configuration is invalid.', $exception->getMessage());
        }
    }

    public function test_email_verification_rejects_array_mailer(): void
    {
        $this->configureMailer('array_transport', ['array_transport' => ['transport' => 'array']]);

        $this->assertMailerRejected();
    }

    public function test_email_verification_rejects_resend_mailer(): void
    {
        $this->configureMailer('resend_transport', ['resend_transport' => ['transport' => 'resend']]);

        $this->assertMailerRejected();
    }

    public function test_email_verification_rejects_failover_containing_array(): void
    {
        $this->configureMailer('unsafe_failover', [
            'unsafe_failover' => [
                'transport' => 'failover',
                'mailers' => ['smtp', 'array_transport'],
            ],
            'smtp' => [
                'transport' => 'smtp',
            ],
            'array_transport' => [
                'transport' => 'array',
            ],
        ]);

        $this->assertMailerRejected();
    }

    public function test_email_verification_rejects_failover_containing_resend(): void
    {
        $this->configureMailer('unsafe_failover', [
            'unsafe_failover' => [
                'transport' => 'failover',
                'mailers' => ['smtp', 'resend_transport'],
            ],
            'smtp' => [
                'transport' => 'smtp',
            ],
            'resend_transport' => [
                'transport' => 'resend',
            ],
        ]);

        $this->assertMailerRejected();
    }

    public function test_email_verification_accepts_smtp_mailer(): void
    {
        $this->configureMailer('smtp', ['smtp' => ['transport' => 'smtp']]);

        $this->assertSame('smtp', EmailVerificationMailer::configuredName());
    }

    public function test_email_verification_accepts_sendmail_mailer(): void
    {
        $this->configureMailer('sendmail', ['sendmail' => ['transport' => 'sendmail']]);

        $this->assertSame('sendmail', EmailVerificationMailer::configuredName());
    }

    public function test_email_verification_accepts_failover_with_only_smtp_and_sendmail(): void
    {
        $this->configureMailer('safe_failover', [
            'safe_failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'sendmail']],
            'smtp' => ['transport' => 'smtp'],
            'sendmail' => ['transport' => 'sendmail'],
        ]);

        $this->assertSame('safe_failover', EmailVerificationMailer::configuredName());
    }

    public function test_email_verification_rejects_failover_that_reaches_log(): void
    {
        config([
            'email_verification.mailer' => 'verification_failover',
            'mail.mailers.verification_failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'nested_failover']],
            'mail.mailers.nested_failover' => ['transport' => 'failover', 'mailers' => ['log']],
        ]);

        try {
            EmailVerificationMailer::configuredName();
            $this->fail('A nested failover that reaches log must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Email verification mailer configuration is invalid.', $exception->getMessage());
        }
    }

    public function test_verification_notification_selects_the_dedicated_mailer(): void
    {
        config(['email_verification.mailer' => 'smtp']);
        $user = new User;
        $user->forceFill([
            'id' => 123,
            'name' => 'Synthetic',
            'email' => 'customer@example.test',
            'role' => 'customer',
        ]);
        $message = (new VerifyCustomerEmail)->toMail($user);

        $this->assertSame('smtp', $message->mailer);
    }

    public function test_verification_notification_renders_branded_html_and_plain_text_with_configured_sender(): void
    {
        $frozenAt = Carbon::parse('2026-11-11 10:00:00', 'America/Lima');
        Carbon::setTestNow($frozenAt);
        config([
            'email_verification.expire' => 75,
            'email_verification.mailer' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.from.address' => 'verification@example.test',
            'mail.from.name' => 'LubriStore',
        ]);

        try {
            $user = new User;
            $user->forceFill([
                'id' => 123,
                'name' => 'Ana <Cliente> & "Prueba" \'Segura\'',
                'email' => 'customer@example.test',
                'role' => 'customer',
            ]);
            $message = (new VerifyCustomerEmail)->toMail($user);
            $html = (string) $message->render();
            $visibleHtml = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $visibleHtml = preg_replace('/\s+/u', ' ', $visibleHtml);
            $plainText = view($message->view['text'], $message->data())->render();
            $url = $message->viewData['verificationUrl'];
            $urlParts = parse_url($url);
            parse_str($urlParts['query'] ?? '', $query);

            $this->assertSame('Verifica tu correo electrónico', $message->subject);
            $this->assertSame(['verification@example.test', 'LubriStore'], $message->from);
            $this->assertSame('smtp', $message->mailer);
            $this->assertSame('emails.customer-email-verification', $message->view['html']);
            $this->assertSame('emails.customer-email-verification-text', $message->view['text']);
            $this->assertSame($frozenAt->copy()->addMinutes(75)->timestamp, (int) ($query['expires'] ?? 0));
            $this->assertStringContainsString('LubriStore', $html);
            $this->assertStringContainsString('Hola, '.e($user->name), $html);
            $this->assertStringNotContainsString('Hola, '.$user->name, $html);
            $this->assertStringNotContainsString('<Cliente>', $html);
            $this->assertStringContainsString('Confirma tu correo para activar tu acceso como cliente.', $html);
            $this->assertStringContainsString('Confirmar correo', $html);
            $this->assertStringContainsString('vence en 75 minutos', $visibleHtml);
            $this->assertStringContainsString('<strong>75 minutos</strong>', $html);
            $this->assertStringContainsString(e($url), $html);
            $this->assertStringContainsString('Hola, Ana <Cliente> & "Prueba" \'Segura\'', $plainText);
            $this->assertStringNotContainsString('&lt;', $plainText);
            $this->assertStringNotContainsString('&gt;', $plainText);
            $this->assertStringNotContainsString('&amp;', $plainText);
            $this->assertStringContainsString('Confirmar correo', $plainText);
            $this->assertStringContainsString('vence en 75 minutos', $plainText);
            $this->assertStringContainsString('Confirmar correo: '.$url, $plainText);
            $this->assertStringContainsString('?expires=', $plainText);
            $this->assertStringContainsString('&signature=', $plainText);
            $this->assertStringNotContainsString('&amp;signature=', $plainText);
            $this->assertStringContainsString('Saludos, equipo de LubriStore', $html);
            $this->assertStringContainsString('Saludos, equipo de LubriStore', $plainText);

            foreach ([$html, $plainText] as $rendered) {
                foreach (['Laravel', 'Regards', 'If you', 'Click here', 'Powered by'] as $forbidden) {
                    $this->assertStringNotContainsString($forbidden, $rendered);
                }
            }
            foreach (['<script', '<img', 'src=', '@import'] as $forbiddenMarkup) {
                $this->assertStringNotContainsString($forbiddenMarkup, strtolower($html));
            }

            $example = file_get_contents(base_path('.env.example'));
            $this->assertStringContainsString('APP_NAME=LubriStore', $example);
            $this->assertStringContainsString('MAIL_FROM_ADDRESS="noreply@example.test"', $example);
            $this->assertStringContainsString('MAIL_FROM_NAME="LubriStore"', $example);
            $this->assertStringNotContainsString('hello@example.com', $example);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_job_payload_is_encrypted_and_does_not_expose_resend_email(): void
    {
        $email = 'private-queue-sentinel@example.test';
        $job = (new SendCustomerEmailVerification(null, $email, false))
            ->onConnection('email_verification')
            ->onQueue('email-verification');
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeEncrypted::class, $job);

        $queue = new class(DB::connection(), 'jobs') extends DatabaseQueue
        {
            public function payloadFor(object $job): string
            {
                return $this->createPayload($job, 'email-verification');
            }
        };
        $queue->setContainer(app());
        $payload = $queue->payloadFor($job);
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($email, $payload);
        $this->assertSame(SendCustomerEmailVerification::class, $decoded['data']['commandName']);
        $decrypted = Crypt::decryptString($decoded['data']['command']);
        $this->assertStringContainsString($email, $decrypted);
    }

    public function test_sync_queue_connection_is_rejected_before_dispatch(): void
    {
        config(['queue.connections.email_verification.driver' => 'sync']);
        $service = new CustomerEmailVerificationService($this->createMock(Dispatcher::class));

        try {
            $service->queueResend('private@example.test');
            $this->fail('The synchronous connection must not accept verification jobs.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Email verification queue must use an asynchronous connection.', $exception->getMessage());
        }
    }

    /** @param array<string, array<string, mixed>> $mailers */
    private function configureMailer(string $name, array $mailers): void
    {
        config([
            'email_verification.mailer' => $name,
            'mail.mailers' => $mailers,
        ]);
    }

    private function assertMailerRejected(): void
    {
        try {
            EmailVerificationMailer::configuredName();
            $this->fail('The unsupported verification mailer must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Email verification mailer configuration is invalid.', $exception->getMessage());
        }
    }
}
