<?php

namespace App\Modules\Rbac\Services;

use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Models\RolePermission;
use App\Services\PermissionService;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RoleService
{
    private const CACHE_LIST = 'roles.list';

    public function __construct(
        private readonly PermissionService $permissionService
    ) {}

    public function list(): Collection
    {
        return TenantReferenceCache::rememberModels(
            self::CACHE_LIST,
            Role::class,
            fn (): Collection => Role::query()->orderBy('name')->get()
        );
    }

    /**
     * @param  array{permissions: array<int, array<string, mixed>>}  $data
     */
    public function create(array $data): Role
    {
        return DB::transaction(function () use ($data): Role {
            $role = Role::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'active' => $data['active'],
                'created_by' => auth()->id,
            ]);

            $this->syncPermissions($role, $data['permissions']);
            $this->permissionService->invalidateCacheForAllUsers();
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $role->load('permissions');
        });
    }

    /**
     * @param  array{permissions: array<int, array<string, mixed>>}  $data
     */
    public function update(Role $role, array $data): Role
    {
        return DB::transaction(function () use ($role, $data): Role {
            if (in_array($role->name, ['Owner', 'Admin'], true) && $data['name'] !== $role->name) {
                abort(422, 'Cannot rename system role.', ['X-Error-Code' => 'ROLE_SYSTEM_RENAME_FORBIDDEN']);
            }

            $role->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'active' => $data['active'],
            ]);

            $this->syncPermissions($role, $data['permissions']);
            $this->permissionService->invalidateCacheForAllUsers();
            TenantReferenceCache::forget(self::CACHE_LIST);

            return $role->refresh()->load('permissions');
        });
    }

    public function delete(Role $role): void
    {
        if (in_array($role->name, ['Owner', 'Admin'], true)) {
            abort(422, 'Cannot delete system role.', ['X-Error-Code' => 'ROLE_SYSTEM_DELETE_FORBIDDEN']);
        }

        if ($role->users()->exists()) {
            abort(409, 'Cannot delete role while users are assigned.', ['X-Error-Code' => 'ROLE_DELETE_HAS_ASSIGNED_USERS']);
        }

        $role->delete();
        $this->permissionService->invalidateCacheForAllUsers();
        TenantReferenceCache::forget(self::CACHE_LIST);
    }

    /**
     * @param  array<int, array<string, mixed>>  $permissionRows
     */
    private function syncPermissions(Role $role, array $permissionRows): void
    {
        $role->permissions()->detach();

        foreach ($permissionRows as $row) {
            RolePermission::query()->create([
                'role_id' => $role->id,
                'permission_id' => (int) $row['permission_id'],
                'can_view' => (bool) $row['can_view'],
                'can_add' => (bool) $row['can_add'],
                'can_edit' => (bool) $row['can_edit'],
                'can_delete' => (bool) $row['can_delete'],
                'can_import' => (bool) $row['can_import'],
                'can_export' => (bool) $row['can_export'],
            ]);
        }
    }
}
