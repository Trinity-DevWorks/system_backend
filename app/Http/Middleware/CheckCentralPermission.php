<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\CentralPermissionService;
use Closure;
use Illuminate\Http\Request;

/**
 * Central route gate: `check.central.permission:<resource_key>,<action>`.
 *
 * `<action>` must be listed for that resource in config/central_rbac.php.
 */
class CheckCentralPermission
{
    public function __construct(private readonly CentralPermissionService $permissionService) {}

    public function handle(Request $request, Closure $next, ?string $resourceKey = null, ?string $action = null): mixed
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return ApiResponse::error('Unauthenticated.', 401, null, [], null, null, 'UNAUTHORIZED');
        }

        if (! $resourceKey || ! $action) {
            return ApiResponse::forbidden('Forbidden. Missing permission metadata.', 'FORBIDDEN');
        }

        if (! $this->permissionService->userHas($resourceKey, $action, $user)) {
            return ApiResponse::forbidden('Forbidden.', 'FORBIDDEN');
        }

        return $next($request);
    }
}
