<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Central\CentralRole;
use App\Models\User;
use App\Modules\Rbac\RbacResourceCatalog;
use App\Support\CentralRbacResourceCatalog;
use App\Support\TenantReferenceCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Central (platform) permission matrix. Mirrors {@see PermissionService} without branches:
 * each central user has a single `central_role_id`.
 */
class CentralPermissionService
{
    private const MATRIX_CACHE_PREFIX = 'central.rbac.permission_matrix';

    public function userHas(string $resourceKey, string $action, User $user): bool
    {
        if (! CentralRbacResourceCatalog::allows($resourceKey, $action)) {
            return false;
        }

        $actionFlag = RbacResourceCatalog::ACTION_FLAGS[$action] ?? null;
        if (! $actionFlag) {
            return false;
        }

        $matrix = $this->matrixForUser($user);

        return (bool) ($matrix[$resourceKey][$actionFlag] ?? false);
    }

    public function resolveRoleModel(User $user): ?CentralRole
    {
        $roleId = $user->central_role_id;
        if ($roleId === null) {
            return null;
        }

        return CentralRole::query()->whereKey((int) $roleId)->first(['id', 'name', 'is_active', 'is_system']);
    }

    /**
     * @return array{id: int, name: string, is_system: bool}|null
     */
    public function resolveRole(User $user): ?array
    {
        $role = $this->resolveRoleModel($user);
        if ($role === null) {
            return null;
        }

        return [
            'id' => (int) $role->id,
            'name' => (string) $role->name,
            'is_system' => (bool) $role->is_system,
        ];
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function matrixForUser(User $user): array
    {
        $role = $this->resolveRoleModel($user);
        if ($role === null || ! $role->is_active) {
            return [];
        }

        if ($role->isSuperAdmin()) {
            return CentralRbacResourceCatalog::fullMatrix();
        }

        return Cache::remember(
            TenantReferenceCache::scoped($this->permissionMatrixCacheKey($user, (int) $role->id)),
            (int) config('cache.rbac_matrix_ttl_seconds', 600),
            fn (): array => $this->loadPermissionMatrix((int) $role->id)
        );
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function loadPermissionMatrix(int $roleId): array
    {
        $rows = DB::connection(config('tenancy.database.central_connection'))
            ->table('central_role_permissions')
            ->join('central_permissions', 'central_role_permissions.permission_id', '=', 'central_permissions.id')
            ->where('central_role_permissions.role_id', $roleId)
            ->select([
                'central_permissions.resource_key',
                'central_role_permissions.can_view',
                'central_role_permissions.can_add',
                'central_role_permissions.can_edit',
                'central_role_permissions.can_delete',
                'central_role_permissions.can_import',
                'central_role_permissions.can_export',
                'central_role_permissions.can_reverse',
            ])
            ->get();

        $matrix = [];
        foreach ($rows as $row) {
            $clamped = CentralRbacResourceCatalog::clampFlags((string) $row->resource_key, (array) $row);
            foreach ($clamped as $flag => $on) {
                if ($on) {
                    $matrix[$row->resource_key][$flag] = true;
                }
            }
        }

        return $matrix;
    }

    private function permissionMatrixCacheKey(User $user, int $roleId): string
    {
        $globalToken = (string) Cache::get(TenantReferenceCache::scoped('central.rbac.invalidate.global'), '0');
        $userToken = (string) Cache::get(TenantReferenceCache::scoped('central.rbac.invalidate.user.'.$user->id), '0');

        return self::MATRIX_CACHE_PREFIX.":{$user->id}:{$roleId}:{$globalToken}:{$userToken}";
    }

    public function invalidateCacheForUser(User $user): void
    {
        Cache::forever(TenantReferenceCache::scoped('central.rbac.invalidate.user.'.$user->id), (string) hrtime(true));
    }

    public function invalidateCacheForAllUsers(): void
    {
        Cache::forever(TenantReferenceCache::scoped('central.rbac.invalidate.global'), (string) hrtime(true));
    }
}
