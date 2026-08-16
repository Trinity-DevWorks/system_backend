<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Notification\DTOs\NotificationResponseData;
use App\Modules\Notification\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Modules\Notification\Services\NotificationInboxService;
use App\Modules\Notification\Services\NotificationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant API for the authenticated user's notification inbox and preferences.
 *
 * What: REST endpoints for list, unread count, read/clear actions, and preference matrix.
 * Used for: Header bell (Phase 1 polling) and notification settings UI.
 * Solves: Exposes a secure, user-scoped inbox without granting access to other users' notifications.
 */
class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationInboxService $inbox,
        private readonly NotificationPreferenceService $preferences,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $perPage = (int) $request->integer('per_page', 20);
        $unreadOnly = $request->boolean('unread');

        $page = $this->inbox->paginate($user, $perPage, $unreadOnly);

        return ApiResponse::success(
            [
                'items' => NotificationResponseData::collectionToArray(collect($page->items())),
                'unread_count' => $this->inbox->unreadCount($user),
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'last_page' => $page->lastPage(),
                ],
            ],
            'Notifications fetched successfully.',
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(
            ['unread_count' => $this->inbox->unreadCount($user)],
            'Unread notification count fetched successfully.'
        );
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $row = $this->inbox->markRead($user, $notification);

        if ($row === null) {
            return ApiResponse::notFound('Notification not found.', 'NOTIFICATION_NOT_FOUND');
        }

        return ApiResponse::success(
            array_merge(
                NotificationResponseData::fromModel($row)->toArray(),
                ['unread_count' => $this->inbox->unreadCount($user)]
            ),
            'Notification marked as read.',
        );
    }

    public function markAllRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $count = $this->inbox->markAllRead($user);

        return ApiResponse::success(
            ['marked' => $count, 'unread_count' => 0],
            'All notifications marked as read.'
        );
    }

    public function clearRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $count = $this->inbox->clearRead($user);

        return ApiResponse::success(
            ['deleted' => $count, 'unread_count' => $this->inbox->unreadCount($user)],
            'Read notifications cleared.'
        );
    }

    public function clearAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $count = $this->inbox->clearAll($user);

        return ApiResponse::success(
            ['deleted' => $count, 'unread_count' => 0],
            'All notifications cleared.'
        );
    }

    public function preferences(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(
            $this->preferences->matrixForUser($user),
            'Notification preferences fetched successfully.'
        );
    }

    public function updatePreferences(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $matrix = $this->preferences->syncForUser($user, $request->validated('preferences'));

        return ApiResponse::success(
            $matrix,
            'Notification preferences updated successfully.'
        );
    }
}
