<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Http\Requests\Central\MakeTenantOwnerRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Central\TenantAdminService;
use App\Services\Central\TenantUserSupportService;
use Illuminate\Http\JsonResponse;

/**
 * Central support for a tenant's users: read-only list, password-reset links, ownership.
 */
class TenantUserController extends Controller
{
    public function __construct(
        private readonly TenantAdminService $tenants,
        private readonly TenantUserSupportService $support,
    ) {}

    public function index(string $tenant): JsonResponse
    {
        return ApiResponse::success(
            $this->support->list($this->tenants->find($tenant)),
            'Tenant users fetched successfully.'
        );
    }

    public function passwordReset(string $tenant, string $user): JsonResponse
    {
        return ApiResponse::success(
            $this->support->sendResetLink($this->tenants->find($tenant), $user),
            'Password reset link created.'
        );
    }

    public function makeOwner(MakeTenantOwnerRequest $request, string $tenant, string $user): JsonResponse
    {
        $model = $this->tenants->find($tenant);
        $this->support->makeOwner($model, $user, (bool) $request->validated('demote_others'));

        return ApiResponse::success(
            ['users' => $this->support->list($model), 'owner' => $this->tenants->owner($model)],
            'Tenant owner updated.'
        );
    }
}
