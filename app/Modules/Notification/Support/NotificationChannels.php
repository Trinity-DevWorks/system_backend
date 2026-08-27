<?php

declare(strict_types=1);

namespace App\Modules\Notification\Support;

/**
 * Delivery channel name constants.
 *
 * What: Stable string keys matching Laravel notification `via()` channels.
 * Used for: Preferences (database + mail), dispatcher, and config/notifications.php.
 * Solves: Avoids magic strings when filtering and persisting channel choices.
 *
 * Broadcast is not a user preference — it mirrors the database (in-app) channel.
 */
final class NotificationChannels
{
    public const DATABASE = 'database';

    public const MAIL = 'mail';

    public const BROADCAST = 'broadcast';

    /**
     * Channels shown in the preferences UI and stored as preference rows.
     *
     * @return list<string>
     */
    public static function preferenceChannels(): array
    {
        return [
            self::DATABASE,
            self::MAIL,
        ];
    }

    /**
     * All Laravel `via()` channels including realtime.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::DATABASE,
            self::MAIL,
            self::BROADCAST,
        ];
    }
}
