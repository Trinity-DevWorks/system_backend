<?php

declare(strict_types=1);

namespace App\Modules\DocumentEmail\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A document emailed to a customer or supplier: the typed message + the PDF.
 */
class DocumentMail extends Mailable
{
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyText,
        public readonly string $companyName,
        string $locale,
        private readonly string $pdf,
        private readonly string $filename,
    ) {
        $this->locale = $locale;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.document',
            text: 'emails.document-text',
            with: [
                'bodyText' => $this->bodyText,
                'companyName' => $this->companyName,
                'rtl' => $this->locale === 'ar',
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->pdf, $this->filename)->withMime('application/pdf'),
        ];
    }
}
