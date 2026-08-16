<?php

declare(strict_types=1);

namespace App\Modules\Notification\Services;

use App\Models\User;
use App\Modules\Notification\Notifications\BusinessNotification;
use App\Modules\Notification\Support\NotificationChannels;
use App\Modules\Notification\Support\NotificationTypeConfig;
use App\Modules\Notification\Support\RecipientQuery;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Single entry point to fan out a business notification to recipients.
 *
 * What: Validates type, resolves recipients, applies preferences, queues Laravel notifications.
 * Used for: DomainNotificationPublisher and any future domain event listeners.
 * Solves: Domain services call one API instead of knowing about channels, prefs, or queues.
 */
class NotificationDispatcher
{
    public function __construct(
        private readonly RecipientResolver $recipients,
        private readonly NotificationPreferenceService $preferences,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  Must include params; optional action_path, resource_*, mail_lines, severity.
     * @param  list<string>|null  $forceChannels  Override registry defaults before preference filter.
     */
    public function dispatch(
        string $type,
        array $payload,
        RecipientQuery $query,
        ?string $exceptUserId = null,
        ?array $forceChannels = null,
    ): void {
        // Types use dotted keys (e.g. branch.user_assigned); must not use config('a.b.c') lookup.
        if (! NotificationTypeConfig::exists($type)) {
            throw new InvalidArgumentException("Unknown notification type [{$type}].");
        }

        $severity = $payload['severity']
            ?? NotificationTypeConfig::get($type, 'severity', 'info');

        $payload = array_merge($payload, [
            'type' => $type,
            'severity' => $severity,
            'params' => is_array($payload['params'] ?? null) ? $payload['params'] : [],
        ]);

        $send = function () use ($type, $payload, $query, $exceptUserId, $forceChannels): void {
            $users = $this->recipients->resolve($query);
            if ($exceptUserId !== null) {
                $users = $users->reject(fn (User $u): bool => (string) $u->id === $exceptUserId);
            }

            $defaults = $forceChannels
                ?? NotificationTypeConfig::get($type, 'default_channels', [NotificationChannels::DATABASE]);
            if (! is_array($defaults)) {
                $defaults = [NotificationChannels::DATABASE];
            }
            // Preferences only cover database + mail; broadcast is appended when database is on.
            $defaults = array_values(array_intersect($defaults, NotificationChannels::preferenceChannels()));

            foreach ($users as $user) {
                $channels = array_values(array_intersect(
                    $this->preferences->channelsFor($user, $type),
                    $defaults
                ));

                // Preference service may enable extras; keep only channels allowed for this dispatch.
                if ($forceChannels !== null) {
                    $channels = array_values(array_intersect(
                        $this->preferences->channelsFor($user, $type),
                        array_values(array_intersect($forceChannels, NotificationChannels::preferenceChannels()))
                    ));
                }

                // Realtime mirrors the in-app inbox — no separate preference toggle.
                if (in_array(NotificationChannels::DATABASE, $channels, true)) {
                    $channels[] = NotificationChannels::BROADCAST;
                }

                if ($channels === []) {
                    continue;
                }

                $user->notify(new BusinessNotification($type, $payload, $channels));
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($send);

            return;
        }

        $send();
    }
}
