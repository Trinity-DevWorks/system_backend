<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Central (landlord) API guard: only platform operators may manage tenants and module entitlements.
 * Must run after auth:sanctum.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_super_admin) {
            return ApiResponse::forbidden('Only platform administrators can access this resource.', 'CENTRAL_FORBIDDEN');
        }

        return $next($request);
    }
}
