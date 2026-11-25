<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A platform (account) email: the platform name on top, the text, and one action button
 * (e.g. the password-reset link). Sent through the platform mailbox.
 */
class PlatformActionMail extends Mailable
{
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyText,
        public readonly string $actionText,
        public readonly string $actionUrl,
        public readonly string $platformName,
        string $locale,
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
            view: 'emails.platform-action',
            text: 'emails.platform-action-text',
            with: [
                'bodyText' => $this->bodyText,
                'actionText' => $this->actionText,
                'actionUrl' => $this->actionUrl,
                'platformName' => $this->platformName,
                'rtl' => $this->locale === 'ar',
            ],
        );
    }
}
