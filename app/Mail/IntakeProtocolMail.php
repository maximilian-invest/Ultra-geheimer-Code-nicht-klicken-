<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class IntakeProtocolMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $property,
        public array $owner,
        public array $broker,
        public array $missingDocs,
        public string $protocolPdfPath,
        public ?string $vermittlungsauftragPdfPath = null,
        public ?string $customSubject = null,
        public ?string $customBody = null,
    ) {}

    public function envelope(): Envelope
    {
        $refId = $this->property['ref_id'] ?? 'neu';
        $defaultSubject = count($this->missingDocs) > 0
            ? "Ihr Aufnahmeprotokoll · {$refId} — noch fehlende Unterlagen"
            : "Ihr Aufnahmeprotokoll · {$refId}";

        return new Envelope(
            to: $this->owner['email'],
            subject: $this->customSubject ?: $defaultSubject,
            replyTo: [$this->broker['email'] ?? 'office@sr-homes.at'],
        );
    }

    public function content(): Content
    {
        if ($this->customBody) {
            return new Content(
                view: 'emails.intake-protocol-custom',
                with: [
                    'body' => $this->customBody,
                    'owner' => $this->owner,
                    'broker' => $this->broker,
                ],
            );
        }

        $view = count($this->missingDocs) > 0
            ? 'emails.intake-protocol-missing-docs'
            : 'emails.intake-protocol-complete';

        return new Content(
            view: $view,
            with: [
                'property' => $this->property,
                'owner' => $this->owner,
                'broker' => $this->broker,
                'missingDocs' => $this->missingDocs,
            ],
        );
    }

    public function attachments(): array
    {
        $out = [];
        if (is_file($this->protocolPdfPath)) {
            $out[] = Attachment::fromPath($this->protocolPdfPath)
                ->as('Aufnahmeprotokoll-' . ($this->property['ref_id'] ?? 'objekt') . '.pdf')
                ->withMime('application/pdf');
        }
        if ($this->vermittlungsauftragPdfPath && is_file($this->vermittlungsauftragPdfPath)) {
            $out[] = Attachment::fromPath($this->vermittlungsauftragPdfPath)
                ->as('Vermittlungsauftrag-' . ($this->property['ref_id'] ?? 'objekt') . '.pdf')
                ->withMime('application/pdf');
        }
        return $out;
    }
}
