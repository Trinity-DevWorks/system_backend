<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\ModuleEntitlementService;
use Illuminate\Http\JsonResponse;

class AssignedModuleController extends Controller
{
    public function __construct(private readonly ModuleEntitlementService $modules) {}

    public function __invoke(): JsonResponse
    {
        $tenantId = (string) tenant('id');

        return ApiResponse::success([
            'modules' => $this->modules->codesForTenant($tenantId),
        ], 'Assigned modules fetched successfully.');
    }
}
