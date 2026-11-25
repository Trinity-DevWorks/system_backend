<?php

declare(strict_types=1);

namespace App\Services\Central;

use App\Mail\PlatformActionMail;
use App\Models\Central\PlatformEmailSetting;
use App\Support\Mail\MailboxMailerFactory;
use App\Support\Mail\SmtpMailbox;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;

/**
 * The platform mailbox (central Settings → Email): settings, test email, and the account emails
 * (password resets) that always go through it. When it is not set up, nothing is sent and callers
 * offer the link to copy instead.
 */
final class PlatformEmailService
{
    public function __construct(
        private readonly PlatformBrandingService $branding,
        private readonly PlatformEmailTemplateService $templates,
        private readonly MailboxMailerFactory $mailers,
    ) {}

    public function settings(): PlatformEmailSetting
    {
        return PlatformEmailSetting::singleton();
    }

    public function isReady(): bool
    {
        return $this->settings()->isReady();
    }

    /**
     * Settings for the screen. The password never leaves the server.
     *
     * @return array<string, mixed>
     */
    public function payload(PlatformEmailSetting $s): array
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
            'verified_at' => $s->verified_at?->toIso8601String(),
            'ready' => $s->isReady(),
        ];
    }

    /**
     * A blank password keeps the saved one. Changing the server or login clears "verified".
     *
     * @param  array<string, mixed>  $data
     */
    public function update(array $data): PlatformEmailSetting
    {
        $settings = $this->settings();

        if (filled($data['host'] ?? null)) {
            SmtpMailbox::assertHostAllowed((string) $data['host'], (int) ($data['port'] ?? $settings->port));
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
     * Sends a short test email now (not queued) so the admin sees right away whether the settings work.
     */
    public function sendTest(string $to): void
    {
        $settings = $this->settings();
        if (! $settings->isComplete()) {
            abort(422, 'Fill in the mail server and the sender address first.', ['X-Error-Code' => 'EMAIL_NOT_CONFIGURED']);
        }

        $platform = $this->platformName();

        try {
            $this->mailer($settings)->raw(
                (string) __('platform_emails.test_body', ['platform' => $platform]),
                function (Message $message) use ($settings, $to, $platform): void {
                    $message->to($to)
                        ->from((string) $settings->from_address, $settings->from_name ?: $platform)
                        ->subject((string) __('platform_emails.test_subject', ['platform' => $platform]));
                    if (filled($settings->reply_to)) {
                        $message->replyTo((string) $settings->reply_to);
                    }
                },
            );
        } catch (\Throwable $e) {
            abort(422, SmtpMailbox::cleanError($e, $settings->password), ['X-Error-Code' => 'EMAIL_TEST_FAILED']);
        }

        $settings->forceFill(['verified_at' => now()])->save();
    }

    /**
     * Emails a password-reset link through the platform mailbox.
     * Returns false (and logs why) when the mailbox is not ready or the send fails.
     */
    public function sendPasswordReset(string $to, string $name, string $url, string $locale): bool
    {
        $settings = $this->settings();
        if (! $settings->isReady()) {
            Log::warning('Password reset email not sent: the platform mailbox is not configured.');

            return false;
        }

        $locale = in_array($locale, PlatformEmailTemplateService::LOCALES, true) ? $locale : 'en';
        $platform = $this->platformName();
        $text = $this->templates->get('password_reset', $locale);
        $values = [
            'name' => $name !== '' ? $name : $to,
            'platform' => $platform,
            'minutes' => (string) (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
        ];

        $mail = (new PlatformActionMail(
            subjectLine: PlatformEmailTemplateService::fill($text['subject'], $values),
            bodyText: PlatformEmailTemplateService::fill($text['body'], $values),
            actionText: (string) __('platform_emails.action_password_reset', [], $locale),
            actionUrl: $url,
            platformName: $platform,
            locale: $locale,
        ))
            ->to($to)
            ->from((string) $settings->from_address, $settings->from_name ?: $platform);
        if (filled($settings->reply_to)) {
            $mail->replyTo((string) $settings->reply_to);
        }

        try {
            $this->mailer($settings)->send($mail);
        } catch (\Throwable $e) {
            Log::warning('Password reset email failed', ['error' => SmtpMailbox::cleanError($e, $settings->password)]);

            return false;
        }

        return true;
    }

    private function mailer(PlatformEmailSetting $s): Mailer
    {
        return $this->mailers->make((string) $s->host, $s->port, (string) $s->encryption, $s->username, $s->password);
    }

    private function platformName(): string
    {
        return (string) $this->branding->get()->name;
    }
}
