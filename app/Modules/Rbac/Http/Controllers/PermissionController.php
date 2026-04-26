<?php

namespace App\Modules\Rbac\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Rbac\DTOs\PermissionCatalogResponseData;
use App\Modules\Rbac\Services\PermissionCatalogService;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    public function __construct(
        private readonly PermissionCatalogService $permissionCatalogService
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->permissionCatalogService->allOrdered();

        return ApiResponse::success(
            PermissionCatalogResponseData::collectionToArray($rows),
            'Permissions fetched successfully.'
        );
    }
}
