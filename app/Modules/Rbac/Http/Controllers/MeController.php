<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Branch\Services\BranchContextService;
use App\Modules\Rbac\DTOs\UserResponseData;
use App\Modules\Rbac\Http\Requests\UpdateMeRequest;
use App\Modules\Rbac\Services\UserService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;

class MeController extends Controller
{
    public function __construct(
        private readonly BranchContextService $branchContext,
        private readonly PermissionService $permissionService,
        private readonly UserService $userService,
    ) {}

    public function show(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        return ApiResponse::success(
            $this->payload($user),
            'Current user fetched successfully.'
        );
    }

    public function update(UpdateMeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $updated = $this->userService->updateMe($user, $request->validated());

        return ApiResponse::success(
            $this->payload($updated),
            'Profile updated successfully.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user): array
    {
        $user->load(['branches' => fn ($q) => $q->select('branches.id', 'branches.name')]);
        $user->loadMissing('avatarAttachment');

        $branchContext = $this->branchContext->contextPayload($user);
        $activeBranchId = $branchContext['active_branch_id'] ?? null;
        $effectiveRole = $this->permissionService->resolveEffectiveRole(
            $user,
            is_int($activeBranchId) ? $activeBranchId : null
        );

        $base = UserResponseData::fromModel($user)->toArray();
        $base['role'] = $effectiveRole;
        $base['branch_context'] = $branchContext;

        return $base;
    }
}
