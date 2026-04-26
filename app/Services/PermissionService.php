<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\TenantReferenceCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PermissionService
{
    private const MATRIX_CACHE_PREFIX = 'rbac.permission_matrix';

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

        $matrix = $this->cachedPermissionMatrix($user);

        return (bool) ($matrix[$resourceKey][$actionFlag] ?? false);
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function cachedPermissionMatrix(User $user): array
    {
        if (! $user->role_id) {
            return [];
        }

        $cacheKey = $this->permissionMatrixCacheKey($user);

        return Cache::remember(
            TenantReferenceCache::scoped($cacheKey),
            (int) config('cache.rbac_matrix_ttl_seconds', 600),
            fn (): array => $this->loadPermissionMatrix($user)
        );
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function loadPermissionMatrix(User $user): array
    {
        $rows = DB::table('users')
            ->join('roles', 'users.role_id', '=', 'roles.id')
            ->join('role_permissions', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->where('users.id', $user->id)
            ->select([
                'permissions.resource_key',
                'role_permissions.can_view',
                'role_permissions.can_add',
                'role_permissions.can_edit',
                'role_permissions.can_delete',
                'role_permissions.can_import',
                'role_permissions.can_export',
            ])
            ->get();

        $matrix = [];
        foreach ($rows as $row) {
            $rk = $row->resource_key;
            if (! isset($matrix[$rk])) {
                $matrix[$rk] = [];
            }
            foreach (['can_view', 'can_add', 'can_edit', 'can_delete', 'can_import', 'can_export'] as $flag) {
                if ($row->{$flag}) {
                    $matrix[$rk][$flag] = true;
                }
            }
        }

        return $matrix;
    }

    private function permissionMatrixCacheKey(User $user): string
    {
        $globalToken = (string) Cache::get(TenantReferenceCache::scoped('rbac.invalidate.global'), '0');
        $userToken = (string) Cache::get(TenantReferenceCache::scoped('rbac.invalidate.user.'.$user->id), '0');

        return self::MATRIX_CACHE_PREFIX.":{$user->id}:{$globalToken}:{$userToken}";
    }

    public function invalidateCacheForUser(User $user): void
    {
        Cache::forever(TenantReferenceCache::scoped('rbac.invalidate.user.'.$user->id), (string) hrtime(true));
    }

    public function invalidateCacheForAllUsers(): void
    {
        Cache::forever(TenantReferenceCache::scoped('rbac.invalidate.global'), (string) hrtime(true));
    }
}
