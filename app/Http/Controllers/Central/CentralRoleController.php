<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\DTOs\Central\CentralRoleResponseData;
use App\Http\Controllers\Concerns\ResolvesListSection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Central\StoreCentralRoleRequest;
use App\Http\Requests\Central\UpdateCentralRoleRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Central\CentralRole;
use App\Services\Central\CentralRoleService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CentralRoleController extends Controller
{
    use ResolvesListSection;

    public function __construct(
        private readonly CentralRoleService $roles,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $names = $this->namesResponse(
            $request,
            fn (): array => CentralRoleResponseData::collectionToArray($this->roles->list()),
            'Role names fetched successfully.'
        );
        if ($names) {
            return $names;
        }

        return ListPagination::json(
            $this->roles->paginateForTable(
                ListPagination::search($request),
                ListPagination::perPage($request)
            ),
            fn (CentralRole $role): array => CentralRoleResponseData::fromModel($role)->toArray(),
            'Roles fetched successfully.'
        );
    }

    public function store(StoreCentralRoleRequest $request): JsonResponse
    {
        $role = $this->roles->create($request->validated());

        return ApiResponse::created(
            CentralRoleResponseData::fromModel($role, true)->toArray(),
            'Role created successfully.'
        );
    }

    public function show(CentralRole $role): JsonResponse
    {
        return ApiResponse::success(
            CentralRoleResponseData::fromModel($role->loadCount('users'), true)->toArray(),
            'Role fetched successfully.'
        );
    }

    public function update(UpdateCentralRoleRequest $request, CentralRole $role): JsonResponse
    {
        $updated = $this->roles->update($role, $request->validated());

        return ApiResponse::success(
            CentralRoleResponseData::fromModel($updated->loadCount('users'), true)->toArray(),
            'Role updated successfully.'
        );
    }

    public function destroy(CentralRole $role): JsonResponse
    {
        $this->roles->delete($role);

        return ApiResponse::success(null, 'Role deleted successfully.');
    }
}
