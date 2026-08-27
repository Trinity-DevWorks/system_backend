<?php

declare(strict_types=1);

namespace App\Modules\Notification\Notifications;

use App\Modules\Notification\Support\NotificationChannels;
use App\Modules\Notification\Support\NotificationTypeConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Generic queued Laravel notification driven by the type registry.
 *
 * What: One notification class for all business events (database + mail + broadcast).
 * Used for: NotificationDispatcher → $user->notify(...).
 * Solves: Avoids a separate Notification class per event while still using Laravel channels, queues, and Notifiable.
 */
class BusinessNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload  Stored in database `data` (+ used for mail interpolation).
     * @param  list<string>  $channels  Subset of database|mail|broadcast for this recipient.
     */
    public function __construct(
        public readonly string $businessType,
        public readonly array $payload,
        public readonly array $channels,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return array_values(array_intersect($this->channels, NotificationChannels::all()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return array_merge($this->payload, [
            'type' => $this->businessType,
        ]);
    }

    /**
     * Thin realtime ping — frontend invalidates React Query and refetches the inbox.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'type' => $this->businessType,
            'severity' => $this->payload['severity'] ?? 'info',
            'action_path' => $this->payload['action_path'] ?? null,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subjectTemplate = (string) NotificationTypeConfig::get(
            $this->businessType,
            'mail_subject',
            'Notification'
        );
        $subject = $this->interpolate($subjectTemplate, $this->payload['params'] ?? []);

        $lines = $this->payload['mail_lines'] ?? [];
        if (! is_array($lines) || $lines === []) {
            $lines = [
                'You have a new notification in your workspace.',
                'Open the app notification center for details.',
            ];
        }

        $mail = (new MailMessage)->subject($subject);
        foreach ($lines as $line) {
            if (is_string($line) && $line !== '') {
                $mail->line($this->interpolate($line, $this->payload['params'] ?? []));
            }
        }

        $actionPath = $this->payload['action_path'] ?? null;
        if (is_string($actionPath) && $actionPath !== '') {
            $mail->action('Open in app', $this->frontendUrl($actionPath));
        }

        return $mail;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function interpolate(string $template, array $params): string
    {
        $replacements = [];
        foreach ($params as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $replacements[':'.$key] = (string) $value;
            }
        }

        return strtr($template, $replacements);
    }

    private function frontendUrl(string $actionPath): string
    {
        $path = str_starts_with($actionPath, '/') ? $actionPath : '/'.$actionPath;
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

        // Prefer tenant frontend host from request when available.
        $request = request();
        if ($request !== null) {
            $origin = $request->headers->get('Origin') ?: $request->headers->get('Referer');
            if (is_string($origin) && $origin !== '') {
                $parts = parse_url($origin);
                if (! empty($parts['scheme']) && ! empty($parts['host'])) {
                    $port = isset($parts['port']) ? ':'.$parts['port'] : '';
                    $base = $parts['scheme'].'://'.$parts['host'].$port;
                }
            }
        }

        return $base.$path;
    }
}
