<?php

declare(strict_types=1);

namespace App\Modules\Notification\Support;

/**
 * Delivery channel name constants for Phase 1.
 *
 * What: Stable string keys matching Laravel notification `via()` channels.
 * Used for: Preferences, dispatcher, and config/notifications.php.
 * Solves: Avoids magic strings when filtering and persisting channel choices.
 */
final class NotificationChannels
{
    public const DATABASE = 'database';

    public const MAIL = 'mail';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::DATABASE,
            self::MAIL,
        ];
    }
}
