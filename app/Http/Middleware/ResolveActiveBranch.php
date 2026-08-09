<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Branch\Services\BranchContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveActiveBranch
{
    public function __construct(
        private readonly BranchContextService $branchContext,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $activeBranchId = $this->branchContext->resolveActiveBranchId();
        $request->attributes->set('active_branch_id', $activeBranchId);

        if ($activeBranchId !== null) {
            // Expose resolved branch so clients can sync when header was missing/invalid.
            $response = $next($request);
            $response->headers->set(BranchContextService::HEADER, (string) $activeBranchId);

            return $response;
        }

        return $next($request);
    }
}
