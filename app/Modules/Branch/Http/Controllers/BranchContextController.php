<?php

declare(strict_types=1);

namespace App\Modules\Branch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Branch\Services\BranchContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchContextController extends Controller
{
    public function __construct(
        private readonly BranchContextService $branchContext,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success(
            $this->branchContext->contextPayload(),
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

        $accessible = $this->branchContext->accessibleBranches();
        $active = $accessible->first(fn ($b): bool => (int) $b->id === $branchId);

        $payload = [
            'active_branch_id' => $branchId,
            'active_branch' => $active ? [
                'id' => (int) $active->id,
                'name' => (string) $active->name,
                'shortcut_name' => $active->shortcut_name,
                'is_default' => (bool) $active->is_default,
            ] : null,
            'accessible_branches' => $accessible
                ->map(fn ($b): array => [
                    'id' => (int) $b->id,
                    'name' => (string) $b->name,
                    'shortcut_name' => $b->shortcut_name,
                    'is_default' => (bool) $b->is_default,
                ])
                ->values()
                ->all(),
            'is_owner' => $this->branchContext->isOwner(),
        ];

        return ApiResponse::success($payload, 'Branch switched successfully.')
            ->header(BranchContextService::HEADER, (string) $branchId);
    }
}
