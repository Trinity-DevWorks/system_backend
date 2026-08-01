<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\ModuleEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantModuleController extends Controller
{
    public function __construct(private readonly ModuleEntitlementService $modules) {}

    public function show(string $tenant): JsonResponse
    {
        $model = Tenant::query()->whereKey($tenant)->first();

        if ($model === null) {
            return ApiResponse::notFound('Tenant not found.', 'TENANT_NOT_FOUND');
        }

        return ApiResponse::success([
            'tenant_id' => $model->id,
            'modules' => $this->modules->codesForTenant($model->id),
        ], 'Tenant modules fetched successfully.');
    }

    public function update(Request $request, string $tenant): JsonResponse
    {
        $model = Tenant::query()->whereKey($tenant)->first();

        if ($model === null) {
            return ApiResponse::notFound('Tenant not found.', 'TENANT_NOT_FOUND');
        }

        $validated = $request->validate([
            'modules' => ['required', 'array'],
            'modules.*' => ['string', 'max:64'],
        ]);

        $codes = $this->modules->syncTenantModules($model, $validated['modules']);

        return ApiResponse::success([
            'tenant_id' => $model->id,
            'modules' => $codes,
        ], 'Tenant modules updated successfully.');
    }
}
