<?php

namespace App\Modules\Rbac\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Rbac\DTOs\RoleResponseData;
use App\Modules\Rbac\Http\Requests\StoreRoleRequest;
use App\Modules\Rbac\Http\Requests\UpdateRoleRequest;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Services\RoleService;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function __construct(
        private readonly RoleService $roleService
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            RoleResponseData::collectionToArray($this->roleService->list()),
            'Roles fetched successfully.'
        );
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roleService->create($request->validated());

        return ApiResponse::created(
            RoleResponseData::fromModel($role, true)->toArray(),
            'Role created successfully.'
        );
    }

    public function show(Role $role): JsonResponse
    {
        return ApiResponse::success(
            RoleResponseData::fromModel($role, true)->toArray(),
            'Role fetched successfully.'
        );
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $updated = $this->roleService->update($role, $request->validated());

        return ApiResponse::success(
            RoleResponseData::fromModel($updated, true)->toArray(),
            'Role updated successfully.'
        );
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->roleService->delete($role);

        return ApiResponse::success(null, 'Role deleted successfully.');
    }
}
