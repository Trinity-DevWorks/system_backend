<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContextService;
use App\Modules\Rbac\Models\Role;
use App\Support\TenantReferenceCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PermissionService
{
    private const MATRIX_CACHE_PREFIX = 'rbac.permission_matrix';

    private const OWNER_ROLE_NAME = 'Owner';

    public function __construct(
        private readonly BranchContextService $branchContext,
    ) {}

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
     * Effective role id for the user's active branch (Owner fallback when browsing unassigned branches).
     */
    public function resolveEffectiveRoleId(User $user, ?int $branchId = null): ?int
    {
        $branchId ??= $this->branchContext->resolveActiveBranchId($user);
        if ($branchId === null) {
            return null;
        }

        $assignedRoleId = $user->roleIdForBranch($branchId);
        if ($assignedRoleId !== null) {
            return $assignedRoleId;
        }

        if ($this->branchContext->isOwner($user)) {
            return $this->ownerRoleId();
        }

        return null;
    }

    /**
     * @return array{id: int, name: string}|null
     */
    public function resolveEffectiveRole(User $user, ?int $branchId = null): ?array
    {
        $roleId = $this->resolveEffectiveRoleId($user, $branchId);
        if ($roleId === null) {
            return null;
        }

        $role = Role::query()->whereKey($roleId)->first(['id', 'name']);
        if ($role === null) {
            return null;
        }

        return [
            'id' => (int) $role->id,
            'name' => (string) $role->name,
        ];
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function cachedPermissionMatrix(User $user): array
    {
        $branchId = $this->branchContext->resolveActiveBranchId($user);
        $roleId = $this->resolveEffectiveRoleId($user, $branchId);

        if ($roleId === null) {
            return [];
        }

        $cacheKey = $this->permissionMatrixCacheKey($user, $branchId, $roleId);

        return Cache::remember(
            TenantReferenceCache::scoped($cacheKey),
            (int) config('cache.rbac_matrix_ttl_seconds', 600),
            fn (): array => $this->loadPermissionMatrix($roleId)
        );
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function loadPermissionMatrix(int $roleId): array
    {
        $rows = DB::table('role_permissions')
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->where('role_permissions.role_id', $roleId)
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

    private function permissionMatrixCacheKey(User $user, ?int $branchId, int $roleId): string
    {
        $globalToken = (string) Cache::get(TenantReferenceCache::scoped('rbac.invalidate.global'), '0');
        $userToken = (string) Cache::get(TenantReferenceCache::scoped('rbac.invalidate.user.'.$user->id), '0');
        $branchKey = $branchId ?? 0;

        return self::MATRIX_CACHE_PREFIX.":{$user->id}:{$branchKey}:{$roleId}:{$globalToken}:{$userToken}";
    }

    public function invalidateCacheForUser(User $user): void
    {
        Cache::forever(TenantReferenceCache::scoped('rbac.invalidate.user.'.$user->id), (string) hrtime(true));
    }

    public function invalidateCacheForAllUsers(): void
    {
        Cache::forever(TenantReferenceCache::scoped('rbac.invalidate.global'), (string) hrtime(true));
    }

    private function ownerRoleId(): ?int
    {
        $id = Role::query()->where('name', self::OWNER_ROLE_NAME)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
