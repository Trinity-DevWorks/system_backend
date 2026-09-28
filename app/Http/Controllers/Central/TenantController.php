<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\DTOs\Central\TenantResponseData;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Central\StoreTenantRequest;
use App\Http\Requests\Central\UpdateTenantRequest;
use App\Http\Requests\Central\UpdateTenantStatusRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Central\TenantAdminService;
use App\Services\Central\TenantProvisioningService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function __construct(
        private readonly TenantProvisioningService $provisioning,
        private readonly TenantAdminService $tenants,
    ) {}

    public function lookupByName(string $name): JsonResponse
    {
        $name = strtolower(trim($name));

        if (! TenantProvisioningService::isValidSlug($name)) {
            return ApiResponse::notFound('Tenant not found.', 'TENANT_NOT_FOUND');
        }

        $tenant = Tenant::query()->whereKey($name)->first();

        if ($tenant === null) {
            return ApiResponse::notFound('Tenant not found.', 'TENANT_NOT_FOUND');
        }

        $payload = [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
        ];

        $response = ApiResponse::success($payload, 'Tenant found.');
        $decoded = json_decode($response->content(), true);
        if (is_array($decoded)) {
            // inventory-management-backend compatibility: flat `tenant` id string for middleware / clients
            $decoded['tenant'] = $tenant->id;
        }

        return response()->json($decoded, $response->status());
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        return ListPagination::json(
            $this->tenants->paginateForTable(
                ListPagination::search($request),
                is_string($status) && $status !== '' ? $status : null,
                ListPagination::perPage($request)
            ),
            fn (Tenant $tenant): array => TenantResponseData::fromModel($tenant)->toArray(),
            'Tenants fetched successfully.'
        );
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        $result = $this->provisioning->provision($request->validated());

        return ApiResponse::created(
            TenantResponseData::fromModel($result['tenant'], null, $result['owner'])->toArray(),
            'Tenant created successfully.'
        );
    }

    public function show(string $tenant): JsonResponse
    {
        $model = $this->tenants->find($tenant);

        return ApiResponse::success(
            TenantResponseData::fromModel(
                $model,
                $this->tenants->modules($model),
                $this->tenants->owner($model)
            )->toArray(),
            'Tenant fetched successfully.'
        );
    }

    public function update(UpdateTenantRequest $request, string $tenant): JsonResponse
    {
        $model = $this->tenants->update(
            $this->tenants->find($tenant),
            (string) $request->validated('name')
        );

        return ApiResponse::success(
            TenantResponseData::fromModel($model, $this->tenants->modules($model))->toArray(),
            'Tenant updated successfully.'
        );
    }

    public function updateStatus(UpdateTenantStatusRequest $request, string $tenant): JsonResponse
    {
        $model = $this->tenants->updateStatus(
            $this->tenants->find($tenant),
            TenantStatus::from((string) $request->validated('status')),
            $request->validated('reason')
        );

        return ApiResponse::success(
            TenantResponseData::fromModel($model, $this->tenants->modules($model))->toArray(),
            'Tenant status updated successfully.'
        );
    }
}
