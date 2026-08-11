<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Rbac\DTOs\RoleResponseData;
use App\Modules\Rbac\Http\Requests\UpdateRolePermissionsRequest;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Services\RoleService;
use Illuminate\Http\JsonResponse;

/**
 * Permission-matrix endpoints gated by the `permissions` resource (not `roles`).
 */
class RolePermissionController extends Controller
{
    public function __construct(
        private readonly RoleService $roleService
    ) {}

    /**
     * Lightweight role list for the permissions matrix role picker.
     */
    public function roles(): JsonResponse
    {
        $rows = $this->roleService->list()
            ->map(static fn (Role $role): array => [
                'id' => (int) $role->id,
                'name' => (string) $role->name,
                'description' => $role->description,
                'is_active' => (bool) $role->is_active,
            ])
            ->values()
            ->all();

        return ApiResponse::success($rows, 'Roles fetched successfully.');
    }

    public function show(Role $role): JsonResponse
    {
        return ApiResponse::success(
            RoleResponseData::fromModel($role, true)->toArray(),
            'Role permissions fetched successfully.'
        );
    }

    public function update(UpdateRolePermissionsRequest $request, Role $role): JsonResponse
    {
        $updated = $this->roleService->updatePermissions(
            $role,
            $request->validated('permissions')
        );

        return ApiResponse::success(
            RoleResponseData::fromModel($updated, true)->toArray(),
            'Role permissions updated successfully.'
        );
    }
}
