<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\DTOs\Central\TenantUsageResponseData;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Central\TenantAdminService;
use App\Services\Central\TenantUsageService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Central Usage page: every tenant's usage against its plan limits (collected hourly; refreshable).
 */
class TenantUsageController extends Controller
{
    public function __construct(
        private readonly TenantUsageService $usage,
        private readonly TenantAdminService $tenants,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        return ListPagination::json(
            $this->usage->paginate(
                ListPagination::search($request),
                is_string($status) && $status !== '' ? $status : null,
                ListPagination::perPage($request)
            ),
            fn (Tenant $tenant): array => TenantUsageResponseData::fromModel($tenant),
            'Tenant usage fetched successfully.'
        );
    }

    /** Recalculates every tenant now (also done hourly by `tenants:refresh-usage`). */
    public function refreshAll(): JsonResponse
    {
        return ApiResponse::success($this->usage->refreshAll(), 'Tenant usage refreshed.');
    }

    public function refresh(string $tenant): JsonResponse
    {
        $model = $this->tenants->find($tenant);
        $this->usage->refresh($model);

        return ApiResponse::success(
            TenantUsageResponseData::fromModel($model->load(['domains', 'plan', 'usage'])),
            'Tenant usage refreshed.'
        );
    }
}
