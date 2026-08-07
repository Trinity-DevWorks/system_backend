<?php

declare(strict_types=1);

namespace App\Modules\Branch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Branch\Services\BranchContextService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchContextController extends Controller
{
    public function __construct(
        private readonly BranchContextService $branchContext,
        private readonly PermissionService $permissionService,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success(
            $this->payloadWithEffectiveRole(),
            'Branch context fetched successfully.'
        );
    }

    public function switch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        $branchId = (int) $validated['branch_id'];

        if (! $this->branchContext->canAccessBranch($branchId)) {
            return ApiResponse::forbidden(
                'You cannot switch to this branch.',
                'BRANCH_ACCESS_DENIED'
            );
        }

        $payload = $this->payloadWithEffectiveRole($branchId);

        return ApiResponse::success($payload, 'Branch switched successfully.')
            ->header(BranchContextService::HEADER, (string) $branchId);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadWithEffectiveRole(?int $forceBranchId = null): array
    {
        $payload = $this->branchContext->contextPayload();

        if ($forceBranchId !== null) {
            $accessible = $this->branchContext->accessibleBranches();
            $active = $accessible->first(fn ($b): bool => (int) $b->id === $forceBranchId);

            $payload['active_branch_id'] = $forceBranchId;
            $payload['active_branch'] = $active ? [
                'id' => (int) $active->id,
                'name' => (string) $active->name,
                'shortcut_name' => $active->shortcut_name,
                'is_default' => (bool) $active->is_default,
            ] : null;
            $payload['accessible_branches'] = $accessible
                ->map(fn ($b): array => [
                    'id' => (int) $b->id,
                    'name' => (string) $b->name,
                    'shortcut_name' => $b->shortcut_name,
                    'is_default' => (bool) $b->is_default,
                ])
                ->values()
                ->all();
        }

        $user = auth()->user();
        $activeBranchId = $payload['active_branch_id'] ?? null;
        $payload['effective_role'] = $user instanceof User
            ? $this->permissionService->resolveEffectiveRole(
                $user,
                is_int($activeBranchId) ? $activeBranchId : null
            )
            : null;

        return $payload;
    }
}
