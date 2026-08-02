<?php

namespace App\Mail;

use App\Models\ContactInquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewContactInquiryMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(public ContactInquiry $inquiry) {}

    public function build(): self
    {
        return $this->from((string) config('mail.from.address'), (string) config('contact.from_name', config('mail.from.name')))
            ->replyTo($this->inquiry->email, $this->inquiry->name)
            ->subject('Nueva consulta LubriStore — '.$this->inquiry->public_id)
            ->markdown('mail.contact-inquiry-received', [
                'adminUrl' => url('/admin/contact-inquiries?open='.$this->inquiry->public_id),
            ]);
    }
}
