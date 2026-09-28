<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\DTOs\Central\CentralUserResponseData;
use App\Http\Controllers\Concerns\ResolvesListSection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Central\StoreCentralUserRequest;
use App\Http\Requests\Central\UpdateCentralUserRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Central\CentralUserService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CentralUserController extends Controller
{
    use ResolvesListSection;

    public function __construct(
        private readonly CentralUserService $users,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $names = $this->namesResponse($request, fn (): array => $this->users->names()
            ->map(fn (User $user): array => [
                'id' => (string) $user->id,
                'name' => (string) $user->name,
                'email' => $user->email,
            ])
            ->values()
            ->all(), 'User names fetched successfully.');
        if ($names) {
            return $names;
        }

        return ListPagination::json(
            $this->users->paginateForTable(
                ListPagination::search($request),
                ListPagination::perPage($request)
            ),
            fn (User $user): array => CentralUserResponseData::fromModel($user)->toArray(),
            'Users fetched successfully.'
        );
    }

    public function store(StoreCentralUserRequest $request): JsonResponse
    {
        $user = $this->users->create($request->validated());

        return ApiResponse::created(
            CentralUserResponseData::fromModel($user)->toArray(),
            'User created successfully.'
        );
    }

    public function show(User $user): JsonResponse
    {
        return ApiResponse::success(
            CentralUserResponseData::fromModel($user)->toArray(),
            'User fetched successfully.'
        );
    }

    public function update(UpdateCentralUserRequest $request, User $user): JsonResponse
    {
        $updated = $this->users->update($user, $request->validated());

        return ApiResponse::success(
            CentralUserResponseData::fromModel($updated)->toArray(),
            'User updated successfully.'
        );
    }

    public function destroy(User $user): JsonResponse
    {
        $this->users->delete($user);

        return ApiResponse::success(null, 'User deleted successfully.');
    }
}
