<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Messages\EmailVerificationMailMessage;
use App\Support\EmailVerificationMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyCustomerEmail extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $expiration = (int) config('email_verification.expire');
        $expiresAt = now()->addMinutes($expiration);
        $verificationUrl = URL::temporarySignedRoute(
            'email.verification.confirm',
            $expiresAt,
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1((string) $notifiable->email),
            ]
        );

        return (new EmailVerificationMailMessage)
            ->usingMailer(EmailVerificationMailer::configuredName())
            ->from(config('mail.from.address'), config('mail.from.name'))
            ->subject('Verifica tu correo electrónico')
            ->view([
                'html' => 'emails.customer-email-verification',
                'text' => 'emails.customer-email-verification-text',
            ], [
                'customerName' => $notifiable->name,
                'verificationUrl' => $verificationUrl,
                'expirationMinutes' => $expiration,
            ]);
    }
}
