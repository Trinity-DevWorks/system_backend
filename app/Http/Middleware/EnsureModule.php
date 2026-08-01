<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Services\ModuleEntitlementService;
use Closure;
use Illuminate\Http\Request;

class EnsureModule
{
    public function __construct(private readonly ModuleEntitlementService $modules) {}

    public function handle(Request $request, Closure $next, string $moduleCode): mixed
    {
        $tenantId = tenant('id');

        if ($tenantId === null || $tenantId === '') {
            return ApiResponse::forbidden('Tenant context required.', 'TENANT_REQUIRED');
        }

        if (! $this->modules->tenantHas((string) $tenantId, $moduleCode)) {
            return ApiResponse::forbidden(
                "Module '{$moduleCode}' is not enabled for this tenant.",
                'MODULE_NOT_ENTITLED'
            );
        }

        return $next($request);
    }
}
