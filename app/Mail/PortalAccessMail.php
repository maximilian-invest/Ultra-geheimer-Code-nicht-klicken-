<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PortalAccessMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $owner,
        public string $loginEmail,
        public string $initialPassword,
        public string $loginUrl,
        public array $broker,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->owner['email'],
            subject: 'Ihr Zugang zum SR-Homes Kundenportal',
            replyTo: [$this->broker['email'] ?? 'office@sr-homes.at'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.portal-access',
            with: [
                'owner' => $this->owner,
                'loginEmail' => $this->loginEmail,
                'initialPassword' => $this->initialPassword,
                'loginUrl' => $this->loginUrl,
                'broker' => $this->broker,
            ],
        );
    }
}
