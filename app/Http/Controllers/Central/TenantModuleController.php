<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\DTOs\Central\ModuleResponseData;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Central\TenantAdminService;
use App\Services\ModuleEntitlementService;
use Illuminate\Http\JsonResponse;

/**
 * Read-only: a tenant's modules come from its plan (change them on the plan or switch plans).
 */
class TenantModuleController extends Controller
{
    public function __construct(
        private readonly TenantAdminService $tenants,
        private readonly ModuleEntitlementService $modules,
    ) {}

    public function show(string $tenant): JsonResponse
    {
        $model = $this->tenants->find($tenant);

        return ApiResponse::success([
            'tenant_id' => $model->id,
            'modules' => $this->tenants->modules($model),
            'available' => ModuleResponseData::collectionToArray($this->modules->catalog()),
        ], 'Tenant modules fetched successfully.');
    }
}
