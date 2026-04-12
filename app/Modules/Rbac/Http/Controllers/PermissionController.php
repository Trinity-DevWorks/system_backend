<?php

namespace App\Modules\Rbac\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Rbac\DTOs\PermissionCatalogResponseData;
use App\Modules\Rbac\Models\Permission;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = Permission::query()->orderBy('resource_key')->get();

        return ApiResponse::success(
            PermissionCatalogResponseData::collectionToArray($rows),
            'Permissions fetched successfully.'
        );
    }
}
