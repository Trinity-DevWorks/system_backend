<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

/**
 * Blocks every tenant route (login included) while the tenant is suspended from central.
 * Must run after InitializeTenancyByDomain.
 */
class EnsureTenantActive
{
    public function handle(Request $request, Closure $next): mixed
    {
        $tenant = tenant();

        if ($tenant instanceof Tenant && $tenant->isSuspended()) {
            return ApiResponse::forbidden('This workspace is suspended.', 'TENANT_SUSPENDED');
        }

        return $next($request);
    }
}
