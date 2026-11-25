<?php

declare(strict_types=1);

namespace App\Modules\DocumentEmail\Services;

use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\DocumentEmail\Models\EmailSetting;
use App\Support\Mail\SmtpMailbox;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;

/**
 * The company's own mailbox (SMTP): settings, the mailer built from them and the test email.
 */
final class EmailSettingService
{
    public function settings(): EmailSetting
    {
        return EmailSetting::singleton();
    }

    /**
     * Settings for the screen. The password never leaves the server.
     *
     * @return array<string, mixed>
     */
    public function payload(EmailSetting $s): array
    {
        return [
            'enabled' => $s->enabled,
            'host' => $s->host,
            'port' => $s->port,
            'encryption' => $s->encryption,
            'username' => $s->username,
            'has_password' => filled($s->password),
            'from_address' => $s->from_address,
            'from_name' => $s->from_name,
            'reply_to' => $s->reply_to,
            'bcc_sender' => $s->bcc_sender,
            'verified_at' => $s->verified_at?->toIso8601String(),
            'ready' => $s->isReady(),
        ];
    }

    /**
     * A blank password keeps the saved one. Changing the server or login clears "verified".
     *
     * @param  array<string, mixed>  $data
     */
    public function update(array $data): EmailSetting
    {
        $settings = $this->settings();

        if (filled($data['host'] ?? null)) {
            $this->assertHostAllowed((string) $data['host'], (int) ($data['port'] ?? $settings->port));
        }

        $password = $data['password'] ?? null;
        unset($data['password']);
        if (is_string($password) && $password !== '') {
            $data['password'] = $password;
        }

        $settings->fill($data);
        if ($settings->isDirty(['host', 'port', 'encryption', 'username', 'password', 'from_address'])) {
            $settings->verified_at = null;
        }
        $settings->save();

        return $settings->refresh();
    }

    /**
     * Only real mail ports and no internal addresses (see {@see SmtpMailbox::assertHostAllowed()}).
     */
    public function assertHostAllowed(string $host, int $port): void
    {
        SmtpMailbox::assertHostAllowed($host, $port);
    }

    public function assertReady(EmailSetting $settings): void
    {
        if (! $settings->isReady()) {
            if ($settings->isComplete()) {
                abort(422, 'Email sending is turned off. Turn on "Send emails" in Settings → Email.', ['X-Error-Code' => 'EMAIL_SENDING_DISABLED']);
            }
            abort(422, 'Email sending is not set up yet. Fill in Settings → Email first.', ['X-Error-Code' => 'EMAIL_NOT_CONFIGURED']);
        }
    }

    public function mailer(EmailSetting $s): Mailer
    {
        return SmtpMailbox::build((string) $s->host, $s->port, (string) $s->encryption, $s->username, $s->password);
    }

    /**
     * Sends a short test email now (not queued) so the company sees right away whether the settings work.
     */
    public function sendTest(string $to): void
    {
        $settings = $this->settings();
        if (! $settings->isComplete()) {
            abort(422, 'Fill in the mail server and the sender address first.', ['X-Error-Code' => 'EMAIL_NOT_CONFIGURED']);
        }

        $company = $this->companyName();

        try {
            $this->mailer($settings)->raw(
                (string) __('document_emails.test_body', ['company' => $company]),
                function (Message $message) use ($settings, $to, $company): void {
                    $message->to($to)
                        ->from((string) $settings->from_address, $settings->from_name ?: $company)
                        ->subject((string) __('document_emails.test_subject', ['company' => $company]));
                    if (filled($settings->reply_to)) {
                        $message->replyTo((string) $settings->reply_to);
                    }
                },
            );
        } catch (\Throwable $e) {
            abort(422, $this->cleanError($e, $settings), ['X-Error-Code' => 'EMAIL_TEST_FAILED']);
        }

        $settings->forceFill(['verified_at' => now()])->save();
    }

    /** The mail server's error, short and without the password. */
    public function cleanError(\Throwable $e, EmailSetting $settings): string
    {
        return SmtpMailbox::cleanError($e, $settings->password);
    }

    public function companyName(): string
    {
        $profile = CompanyProfile::singleton();

        return (string) ($profile->company_name ?: (tenant('name') ?? config('app.name')));
    }
}
