<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\DTOs\Central\CentralRoleResponseData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Central\UpdateCentralRolePermissionsRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Central\CentralRole;
use App\Services\Central\CentralRoleService;
use Illuminate\Http\JsonResponse;

/**
 * Central permission-matrix endpoints gated by the `permissions` resource (not `roles`).
 */
class CentralRolePermissionController extends Controller
{
    public function __construct(
        private readonly CentralRoleService $roles,
    ) {}

    public function roles(): JsonResponse
    {
        $rows = $this->roles->list()
            ->map(static fn (CentralRole $role): array => [
                'id' => (int) $role->id,
                'name' => (string) $role->name,
                'description' => $role->description,
                'is_active' => (bool) $role->is_active,
                'is_system' => (bool) $role->is_system,
            ])
            ->values()
            ->all();

        return ApiResponse::success($rows, 'Roles fetched successfully.');
    }

    public function show(CentralRole $role): JsonResponse
    {
        return ApiResponse::success(
            CentralRoleResponseData::fromModel($role, true)->toArray(),
            'Role permissions fetched successfully.'
        );
    }

    public function update(UpdateCentralRolePermissionsRequest $request, CentralRole $role): JsonResponse
    {
        $updated = $this->roles->updatePermissions($role, $request->validated('permissions'));

        return ApiResponse::success(
            CentralRoleResponseData::fromModel($updated, true)->toArray(),
            'Role permissions updated successfully.'
        );
    }
}
