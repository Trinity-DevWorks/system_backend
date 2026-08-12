<?php

declare(strict_types=1);

namespace App\Modules\Notification\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Read-side inbox operations for the authenticated user.
 *
 * What: List, unread count, mark one / mark all read against Laravel database notifications.
 * Used for: NotificationController (header bell + inbox API).
 * Solves: Encapsulates inbox queries so controllers stay thin and tenants never see other users' rows.
 */
class NotificationInboxService
{
    /**
     * @return LengthAwarePaginator<int, DatabaseNotification>
     */
    public function paginate(User $user, int $perPage = 20, bool $unreadOnly = false): LengthAwarePaginator
    {
        $query = $user->notifications()->latest();

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        return $query->paginate(max(1, min($perPage, 100)));
    }

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    public function markRead(User $user, string $notificationId): ?DatabaseNotification
    {
        /** @var DatabaseNotification|null $notification */
        $notification = $user->notifications()->whereKey($notificationId)->first();
        if ($notification === null) {
            return null;
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return $notification->fresh();
    }

    public function markAllRead(User $user): int
    {
        $unread = $user->unreadNotifications()->get();
        foreach ($unread as $notification) {
            $notification->markAsRead();
        }

        return $unread->count();
    }
}
