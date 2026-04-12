<?php

namespace App\Modules\Rbac\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Rbac\Http\Requests\UpdateUserRoleRequest;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;

class UserRoleController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissionService
    ) {}

    public function update(UpdateUserRoleRequest $request, User $user): JsonResponse
    {
        $user->update(['role_id' => $request->validated('role_id')]);

        $this->permissionService->invalidateCacheForUser($user->fresh());

        return ApiResponse::success([
            'id' => $user->id,
            'role_id' => $user->role_id,
        ], 'User role updated successfully.');
    }
}
