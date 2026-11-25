<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\FrontendUrl;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    /**
     * `$url` overrides the link built from the current request host: the central domain sends
     * resets for a tenant user, whose link must point at the tenant's own host.
     */
    public function __construct(
        #[\SensitiveParameter]
        string $token,
        private readonly ?string $url = null,
    ) {
        parent::__construct($token);
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $request = request();
        $email = method_exists($notifiable, 'getEmailForPasswordReset')
            ? $notifiable->getEmailForPasswordReset()
            : (string) $notifiable->email;

        $url = $this->url ?? FrontendUrl::passwordReset($request, $this->token, $email);

        $expireMinutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject('Reset your password')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset password', $url)
            ->line("This password reset link will expire in {$expireMinutes} minutes.")
            ->line('If you did not request a password reset, no further action is required.');
    }
}
