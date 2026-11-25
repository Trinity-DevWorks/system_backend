<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Contracts\Mail\Mailer;

/**
 * Builds the mailer for a typed-in mailbox. A class (not a static call) so tests can swap it
 * for Laravel's in-memory `array` mailer: Mail::fake() does not cover Mail::build().
 */
class MailboxMailerFactory
{
    public function make(string $host, int $port, string $encryption, ?string $username, ?string $password): Mailer
    {
        return SmtpMailbox::build($host, $port, $encryption, $username, $password);
    }
}
