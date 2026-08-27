<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesListSection;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Rbac\DTOs\UserResponseData;
use App\Modules\Rbac\Http\Requests\StoreUserRequest;
use App\Modules\Rbac\Http\Requests\UpdateUserRequest;
use App\Modules\Rbac\Services\UserService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use ResolvesListSection;

    public function __construct(
        private readonly UserService $userService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $names = $this->namesResponse($request, function () {
            return $this->userService->names()->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->values()->all();
        }, 'User names fetched successfully.');
        if ($names) {
            return $names;
        }

        return ListPagination::json(
            $this->userService->paginateForTable(
                ListPagination::search($request),
                ListPagination::perPage($request)
            ),
            fn (User $user): array => UserResponseData::fromModel($user)->toArray(),
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
