<?php

namespace App\Notifications\Messages;

use Illuminate\Notifications\Messages\MailMessage;

final class EmailVerificationMailMessage extends MailMessage
{
    public function usingMailer(string $mailer): self
    {
        $this->mailer($mailer);

        return $this;
    }
}
