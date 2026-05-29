<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Rbac\DTOs\UserResponseData;
use App\Modules\Rbac\Http\Requests\StoreUserRequest;
use App\Modules\Rbac\Http\Requests\UpdateUserRequest;
use App\Modules\Rbac\Services\UserService;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $userService
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            UserResponseData::collectionToArray($this->userService->list()),
            'Users fetched successfully.'
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->validated());

        return ApiResponse::created(
            UserResponseData::fromModel($user)->toArray(),
            'User created successfully.'
        );
    }

    public function show(User $user): JsonResponse
    {
        return ApiResponse::success(
            UserResponseData::fromModel($this->userService->find($user))->toArray(),
            'User fetched successfully.'
        );
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $updated = $this->userService->update($user, $request->validated());

        return ApiResponse::success(
            UserResponseData::fromModel($updated)->toArray(),
            'User updated successfully.'
        );
    }

    public function destroy(User $user): JsonResponse
    {
        $this->userService->delete($user);

        return ApiResponse::success(null, 'User deleted successfully.');
    }
}
