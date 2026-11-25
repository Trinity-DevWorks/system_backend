<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * Builds an SMTP mailer from mailbox settings typed in by a user (a company's mailbox in tenant
 * Settings → Email, or the platform mailbox in central Settings → Email), with the same safety rules.
 */
final class SmtpMailbox
{
    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    /** Ports a mailbox may use. Anything else is refused (keeps SMTP away from internal services). */
    public const ALLOWED_PORTS = [25, 465, 587, 2525];

    /**
     * Only real mail ports and no internal addresses, so the SMTP form can't be used to
     * reach services inside our network. Local development may use anything (e.g. Mailpit on 1025).
     */
    public static function assertHostAllowed(string $host, int $port): void
    {
        if (app()->environment('local', 'testing')) {
            return;
        }

        if (! in_array($port, self::ALLOWED_PORTS, true)) {
            abort(422, 'This port is not allowed for sending email.', ['X-Error-Code' => 'EMAIL_HOST_NOT_ALLOWED']);
        }

        $host = trim($host);
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                abort(422, 'This mail server address is not allowed.', ['X-Error-Code' => 'EMAIL_HOST_NOT_ALLOWED']);
            }
        }
    }

    public static function build(string $host, int $port, string $encryption, ?string $username, ?string $password): Mailer
    {
        self::assertHostAllowed($host, $port);

        return Mail::build([
            'transport' => 'smtp',
            // ssl = encrypted from the first byte (usually 465); tls = STARTTLS (usually 587).
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            // Per read/write on the connection. Uploading a ~1 MB PDF to Gmail on a slow line can take
            // well over 20s; a shorter limit cuts the upload ("Unable to write bytes on the wire").
            'timeout' => 60,
            'require_tls' => $encryption === 'tls',
            'auto_tls' => $encryption !== 'none',
        ]);
    }

    /** The mail server's error, short and without the password. */
    public static function cleanError(\Throwable $e, ?string $password): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '');
        if (filled($password)) {
            $text = str_replace((string) $password, '***', $text);
        }

        return mb_substr($text !== '' ? $text : class_basename($e), 0, 500);
    }
}
