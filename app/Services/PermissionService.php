<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class PermissionService
{
    public function userHas(string $resourceKey, string $action, User $user): bool
    {
        $actionFlag = match ($action) {
            'view' => 'can_view',
            'add' => 'can_add',
            'edit' => 'can_edit',
            'delete' => 'can_delete',
            'import' => 'can_import',
            'export' => 'can_export',
            default => null,
        };

        if (! $actionFlag) {
            return false;
        }

        return DB::table('users')
            ->join('roles', 'users.role_id', '=', 'roles.id')
            ->join('role_permissions', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->where('users.id', $user->id)
            ->where('permissions.resource_key', $resourceKey)
            ->where("role_permissions.{$actionFlag}", true)
            ->exists();
    }

    public function invalidateCacheForUser(User $user): void
    {
        // No application cache; permissions are read from DB each request.
    }

    public function invalidateCacheForAllUsers(): void
    {
        // No application cache.
    }
}
