<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\DTOs\Central\CentralPermissionCatalogResponseData;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Central\CentralPermissionCatalogService;
use Illuminate\Http\JsonResponse;

class CentralPermissionController extends Controller
{
    public function __construct(
        private readonly CentralPermissionCatalogService $catalog,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            CentralPermissionCatalogResponseData::collectionToArray($this->catalog->allOrdered()),
            'Permissions fetched successfully.'
        );
    }
}
