<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Rbac\DTOs\UserResponseData;
use App\Modules\Rbac\Http\Requests\UpdateUserRoleRequest;
use App\Modules\Rbac\Services\UserService;
use Illuminate\Http\JsonResponse;

class UserRoleController extends Controller
{
    public function __construct(
        private readonly UserService $userService
    ) {}

    public function update(UpdateUserRoleRequest $request, User $user): JsonResponse
    {
        $updated = $this->userService->assignRole($user, (int) $request->validated('role_id'));

        return ApiResponse::success(
            UserResponseData::fromModel($updated)->toArray(),
            'User role updated successfully.'
        );
    }
}
