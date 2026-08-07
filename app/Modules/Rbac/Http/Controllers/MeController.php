<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Branch\Services\BranchContextService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;

class MeController extends Controller
{
    public function __construct(
        private readonly BranchContextService $branchContext,
        private readonly PermissionService $permissionService,
    ) {}

    public function __invoke(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $user->load(['branches' => fn ($q) => $q->select('branches.id', 'branches.name')]);

        $branchContext = $this->branchContext->contextPayload($user);
        $activeBranchId = $branchContext['active_branch_id'] ?? null;
        $effectiveRole = $this->permissionService->resolveEffectiveRole(
            $user,
            is_int($activeBranchId) ? $activeBranchId : null
        );

        $branches = $user->branches
            ->map(fn ($b): array => [
                'id' => (int) $b->id,
                'name' => (string) $b->name,
                'role_id' => $b->pivot?->role_id !== null ? (int) $b->pivot->role_id : null,
            ])
            ->values()
            ->all();

        return ApiResponse::success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'active' => (bool) $user->active,
            'role' => $effectiveRole,
            'branches' => $branches,
            'branch_ids' => $user->branches->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'branch_assignments' => array_values(array_filter(
                array_map(
                    static fn (array $b): ?array => $b['role_id'] !== null
                        ? ['branch_id' => $b['id'], 'role_id' => $b['role_id']]
                        : null,
                    $branches
                )
            )),
            'branch_context' => $branchContext,
        ], 'Current user fetched successfully.');
    }
}
