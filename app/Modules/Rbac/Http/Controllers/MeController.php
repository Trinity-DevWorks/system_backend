<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Branch\Services\BranchContextService;
use App\Modules\Rbac\DTOs\UserResponseData;
use App\Modules\Rbac\Http\Requests\UpdateMeRequest;
use App\Modules\Rbac\Services\ManagerApprovalService;
use App\Modules\Rbac\Services\UserService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * Sets or removes the user's own approval PIN (4–6 digits), after checking their password.
     */
    public function approvalPin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pin' => ['nullable', 'string', 'regex:/^[0-9]{4,6}$/'],
            'current_password' => ['required', 'string'],
        ]);
        /** @var User $user */
        $user = auth()->user();
        app(ManagerApprovalService::class)->setPin($user, $data['pin'] ?? null, (string) $data['current_password']);

        return ApiResponse::success($this->payload($user->fresh() ?? $user), 'Approval PIN saved.');
    }

    /**
     * People who can approve an action in a branch (they have the permission and a PIN set).
     */
    public function approvers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:'.ManagerApprovalService::DISCOUNT.','.ManagerApprovalService::CREDIT_LIMIT],
            'branch_id' => ['nullable', 'integer'],
        ]);

        return ApiResponse::success(
            app(ManagerApprovalService::class)->approvers((string) $data['action'], isset($data['branch_id']) ? (int) $data['branch_id'] : null),
            'Approvers fetched successfully.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user): array
    {
        $user->load([
            'branches' => fn ($q) => $q->select('branches.id', 'branches.name'),
            'avatarAttachment',
        ]);

        $branchContext = $this->branchContext->contextPayload($user);
        $activeBranchId = $branchContext['active_branch_id'] ?? null;
        $branchId = is_int($activeBranchId) ? $activeBranchId : null;

        $base = UserResponseData::fromModel($user)->toArray();
        $base['role'] = $this->permissionService->resolveEffectiveRole($user, $branchId);
        $base['permissions'] = $this->permissionService->matrixForUser($user, $branchId);
        $base['branch_context'] = $branchContext;
        $base['has_approval_pin'] = $user->approval_pin !== null;

        return $base;
    }
}
