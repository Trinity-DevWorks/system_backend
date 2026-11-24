<?php

declare(strict_types=1);

namespace App\Services\Central;

use App\Models\Central\CentralPermission;
use App\Models\Central\CentralRole;
use App\Models\Central\CentralRolePermission;
use App\Models\User;
use App\Services\CentralPermissionService;
use App\Support\CentralRbacResourceCatalog;
use App\Support\ListPagination;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class CentralRoleService
{
    private const CACHE_LIST = 'central.roles.list';

    public function __construct(
        private readonly CentralPermissionService $permissionService,
    ) {}

    public static function forgetListCache(): void
    {
        TenantReferenceCache::forget(self::CACHE_LIST);
    }

    /**
     * @return Collection<int, CentralRole>
     */
    public function list(): Collection
    {
        return TenantReferenceCache::rememberModels(
            self::CACHE_LIST,
            CentralRole::class,
            fn (): Collection => CentralRole::query()->orderBy('name')->get()
        );
    }

    public function paginateForTable(?string $search, int $perPage): LengthAwarePaginator
    {
        $query = CentralRole::query()->withCount('users')->orderBy('name');
        ListPagination::applySearch($query, $search, ['name', 'description']);

        return $query->paginate($perPage);
    }

    /**
     * @param  array{name: string, description?: string|null, is_active: bool, permissions?: array<int, array<string, mixed>>}  $data
     */
    public function create(array $data): CentralRole
    {
        return $this->transaction(function () use ($data): CentralRole {
            $role = CentralRole::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'],
                'is_system' => false,
                'created_by' => Auth::id(),
            ]);

            $this->syncPermissions($role, $data['permissions'] ?? $this->defaultDeniedPermissionRows());
            $this->permissionService->invalidateCacheForAllUsers();
            self::forgetListCache();

            return $role->load('permissions');
        });
    }

    /**
     * @param  array{name: string, description?: string|null, is_active: bool, permissions?: array<int, array<string, mixed>>}  $data
     */
    public function update(CentralRole $role, array $data): CentralRole
    {
        return $this->transaction(function () use ($role, $data): CentralRole {
            $this->assertNotSuperAdmin($role);

            $role->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'],
            ]);

            if (array_key_exists('permissions', $data) && is_array($data['permissions'])) {
                $this->syncPermissions($role, $data['permissions']);
            }
            $this->permissionService->invalidateCacheForAllUsers();
            self::forgetListCache();

            return $role->refresh()->load('permissions');
        });
    }

    public function delete(CentralRole $role): void
    {
        if ($role->is_system) {
            abort(422, 'Cannot delete system role.', ['X-Error-Code' => 'ROLE_SYSTEM_DELETE_FORBIDDEN']);
        }

        if (User::query()->where('central_role_id', $role->id)->exists()) {
            abort(409, 'Cannot delete role while users are assigned.', ['X-Error-Code' => 'ROLE_DELETE_HAS_ASSIGNED_USERS']);
        }

        $role->delete();
        $this->permissionService->invalidateCacheForAllUsers();
        self::forgetListCache();
    }

    /**
     * Replace a role's permission matrix without changing role metadata.
     *
     * @param  array<int, array<string, mixed>>  $permissionRows
     */
    public function updatePermissions(CentralRole $role, array $permissionRows): CentralRole
    {
        return $this->transaction(function () use ($role, $permissionRows): CentralRole {
            $this->assertNotSuperAdmin($role);

            $this->syncPermissions($role, $permissionRows);
            $this->permissionService->invalidateCacheForAllUsers();

            return $role->refresh()->load('permissions');
        });
    }

    private function assertNotSuperAdmin(CentralRole $role): void
    {
        if ($role->isSuperAdmin()) {
            abort(422, 'Cannot edit the Super Admin role or its permissions.', [
                'X-Error-Code' => 'CENTRAL_SUPER_ADMIN_EDIT_FORBIDDEN',
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultDeniedPermissionRows(): array
    {
        return CentralPermission::query()
            ->orderBy('resource_key')
            ->pluck('id')
            ->map(fn ($id): array => ['permission_id' => (int) $id])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $permissionRows
     */
    private function syncPermissions(CentralRole $role, array $permissionRows): void
    {
        $role->permissions()->detach();

        $permissionIds = array_map(static fn (array $row): int => (int) $row['permission_id'], $permissionRows);
        $keysById = CentralPermission::query()
            ->whereIn('id', $permissionIds)
            ->pluck('resource_key', 'id');

        foreach ($permissionRows as $row) {
            $permissionId = (int) $row['permission_id'];
            $resourceKey = (string) ($keysById[$permissionId] ?? '');
            if ($resourceKey === '') {
                continue;
            }

            CentralRolePermission::query()->create([
                'role_id' => $role->id,
                'permission_id' => $permissionId,
                ...CentralRbacResourceCatalog::clampFlags($resourceKey, $row),
            ]);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction($callback);
    }
}
