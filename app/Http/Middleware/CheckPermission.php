<?php

namespace App\Http\Middleware;

use App\Services\PermissionService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckPermission
{
    public function __construct(private readonly PermissionService $permissionService) {}

    public function handle(Request $request, Closure $next, ?string $resourceKey = null, ?string $action = null): mixed
    {
        $user = $request->user();

        if (! $user) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        if (! $action) {
            $action = match (strtoupper($request->method())) {
                'GET' => 'view',
                'POST' => 'add',
                'PUT', 'PATCH' => 'edit',
                'DELETE' => 'delete',
                default => null,
            };
        }

        if (! $resourceKey || ! $action) {
            return new JsonResponse(['message' => 'Forbidden. Missing permission metadata.'], 403);
        }

        if (! $this->permissionService->userHas($resourceKey, $action, $user)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
