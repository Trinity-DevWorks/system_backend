<?php

declare(strict_types=1);

namespace App\Modules\Notification\Support;

/**
 * Safe reader for dotted notification type keys in config/notifications.php.
 *
 * What: Looks up type metadata by exact string key (e.g. branch.user_assigned).
 * Used for: NotificationDispatcher, PreferenceService, BusinessNotification.
 * Solves: Laravel's config('a.b.c') treats dots as nesting, so types like user.created
 * would be read as types[user][created] instead of types['user.created'].
 */
final class NotificationTypeConfig
{
    /**
     * @return array<string, mixed>|null
     */
    public static function definition(string $type): ?array
    {
        $types = config('notifications.types', []);
        if (! is_array($types) || ! array_key_exists($type, $types)) {
            return null;
        }

        $definition = $types[$type];

        return is_array($definition) ? $definition : null;
    }

    public static function exists(string $type): bool
    {
        return self::definition($type) !== null;
    }

    public static function get(string $type, string $key, mixed $default = null): mixed
    {
        $definition = self::definition($type);
        if ($definition === null) {
            return $default;
        }

        return $definition[$key] ?? $default;
    }
}
