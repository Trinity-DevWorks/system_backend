<?php

declare(strict_types=1);

namespace App\Modules\Notification\Services;

use App\Models\User;
use App\Modules\Notification\Models\NotificationPreference;
use App\Modules\Notification\Support\NotificationChannels;
use App\Modules\Notification\Support\NotificationTypeConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Loads and saves per-user notification type×channel preferences.
 *
 * What: Preference CRUD + effective channel resolution against config defaults.
 * Used for: NotificationDispatcher and preferences API.
 * Solves: Missing preference rows fall back to config defaults without forcing every user to opt in manually.
 */
class NotificationPreferenceService
{
    /**
     * Effective channels for one user + business type (intersection of defaults and prefs).
     *
     * @return list<string>
     */
    public function channelsFor(User $user, string $type): array
    {
        $defaults = NotificationTypeConfig::get($type, 'default_channels', [NotificationChannels::DATABASE]);
        if (! is_array($defaults)) {
            $defaults = [NotificationChannels::DATABASE];
        }
        $defaults = array_values(array_intersect($defaults, NotificationChannels::preferenceChannels()));

        /** @var Collection<string, NotificationPreference> $prefs */
        $prefs = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->get()
            ->keyBy('channel');

        $channels = [];
        foreach ($defaults as $channel) {
            $pref = $prefs->get($channel);
            if ($pref === null || $pref->enabled) {
                $channels[] = $channel;
            }
        }

        // Explicitly enabled channels that were not in defaults (rare; allow user override on).
        foreach ($prefs as $channel => $pref) {
            if (
                $pref->enabled
                && in_array($channel, NotificationChannels::preferenceChannels(), true)
                && ! in_array($channel, $channels, true)
            ) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    /**
     * Matrix for UI: every known type × channel with effective enabled flag.
     *
     * @return list<array{type: string, channel: string, enabled: bool, default: bool}>
     */
    public function matrixForUser(User $user): array
    {
        $types = array_keys(config('notifications.types', []));
        $prefs = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy(fn (NotificationPreference $p): string => $p->type.'|'.$p->channel);

        $rows = [];
        foreach ($types as $type) {
            $defaults = NotificationTypeConfig::get($type, 'default_channels', [NotificationChannels::DATABASE]);
            if (! is_array($defaults)) {
                $defaults = [NotificationChannels::DATABASE];
            }
            foreach (NotificationChannels::preferenceChannels() as $channel) {
                $key = $type.'|'.$channel;
                $pref = $prefs->get($key);
                $defaultOn = in_array($channel, $defaults, true);
                $enabled = $pref !== null ? (bool) $pref->enabled : $defaultOn;

                $rows[] = [
                    'type' => $type,
                    'channel' => $channel,
                    'enabled' => $enabled,
                    'default' => $defaultOn,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{type: string, channel: string, enabled: bool}>  $rows
     * @return list<array{type: string, channel: string, enabled: bool, default: bool}>
     */
    public function syncForUser(User $user, array $rows): array
    {
        $knownTypes = array_keys(config('notifications.types', []));
        $knownChannels = NotificationChannels::preferenceChannels();

        DB::transaction(function () use ($user, $rows, $knownTypes, $knownChannels): void {
            foreach ($rows as $row) {
                $type = (string) ($row['type'] ?? '');
                $channel = (string) ($row['channel'] ?? '');
                if (! in_array($type, $knownTypes, true) || ! in_array($channel, $knownChannels, true)) {
                    continue;
                }

                NotificationPreference::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'type' => $type,
                        'channel' => $channel,
                    ],
                    [
                        'enabled' => (bool) ($row['enabled'] ?? false),
                    ]
                );
            }
        });

        return $this->matrixForUser($user);
    }
}
