<?php

declare(strict_types=1);

namespace App\Modules\Notification\Services;

use App\Models\User;
use App\Modules\Notification\Support\RecipientQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves RecipientQuery into a unique set of User models.
 *
 * What: Turns targeting rules (user ids / RBAC permission / branch) into recipients.
 * Used for: NotificationDispatcher before preference filtering and notify().
 * Solves: Centralizes “who gets this event” so domain services never hand-roll SQL.
 */
class RecipientResolver
{
    /**
     * @return Collection<int, User>
     */
    public function resolve(RecipientQuery $query): Collection
    {
        $ids = collect($query->userIds ?? [])
            ->filter(fn ($id): bool => is_string($id) && $id !== '')
            ->values();

        if ($query->permissionResource !== null) {
            $ids = $ids->merge($this->userIdsWithPermission(
                $query->permissionResource,
                $query->permissionAction,
                $query->branchId,
            ));
        }

        $uniqueIds = $ids->unique()->values()->all();
        if ($uniqueIds === []) {
            return collect();
        }

        $users = User::query()->whereIn('id', $uniqueIds);

        if ($query->activeOnly) {
            $users->where('is_active', true);
        }

        return $users->get();
    }

    /**
     * @return list<string>
     */
    private function userIdsWithPermission(string $resourceKey, string $action, ?int $branchId): array
    {
        $flag = match ($action) {
            'view' => 'can_view',
            'add' => 'can_add',
            'edit' => 'can_edit',
            'delete' => 'can_delete',
            'import' => 'can_import',
            'export' => 'can_export',
            default => null,
        };

        if ($flag === null) {
            return [];
        }

        $q = DB::table('branch_user')
            ->join('role_permissions', 'branch_user.role_id', '=', 'role_permissions.role_id')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->join('users', 'branch_user.user_id', '=', 'users.id')
            ->where('permissions.resource_key', $resourceKey)
            ->where("role_permissions.{$flag}", true)
            ->where('users.is_active', true);

        if ($branchId !== null) {
            $q->where('branch_user.branch_id', $branchId);
        }

        return $q->distinct()->pluck('branch_user.user_id')->map(fn ($id): string => (string) $id)->all();
    }
}
