<?php

namespace App\Modules\Rbac\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Branch\Services\BranchContextService;
use App\Modules\Rbac\Http\Requests\LoginRequest;
use App\Services\AuditWriter;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function __construct(
        private readonly AuditWriter $auditWriter,
        private readonly BranchContextService $branchContext,
        private readonly PermissionService $permissionService,
    ) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $email = (string) $request->validated('email');
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            // Security trail: failed attempt (actor may be null; auditable is user or tenant).
            $this->auditWriter->write(
                event: 'login_failed',
                auditable: $user,
                user: null,
                newValues: ['email' => $email],
                tags: 'auth,security',
            );

            return ApiResponse::error('Invalid credentials.', 422, null, [], null, null, 'INVALID_CREDENTIALS');
        }

        if (! $user->active) {
            $this->auditWriter->write(
                event: 'login_failed',
                auditable: $user,
                user: null,
                newValues: ['email' => $email, 'reason' => 'inactive'],
                tags: 'auth,security',
            );

            return ApiResponse::forbidden('Account is inactive.', 'ACCOUNT_INACTIVE');
        }

        $plainToken = $user->createToken('tenant')->plainTextToken;

        $this->auditWriter->write(
            event: 'login',
            auditable: $user,
            user: $user,
            tags: 'auth,security',
        );

        $user->load(['branches' => fn ($q) => $q->select('branches.id', 'branches.name', 'branches.shortcut_name', 'branches.is_default')]);

        $branchContext = $this->branchContext->contextPayload($user);
        $activeBranchId = $branchContext['active_branch_id'] ?? null;
        $effectiveRole = $this->permissionService->resolveEffectiveRole(
            $user,
            is_int($activeBranchId) ? $activeBranchId : null
        );

        $branches = $user->branches
            ->map(fn ($branch): array => [
                'id' => (int) $branch->id,
                'name' => (string) $branch->name,
                'role_id' => $branch->pivot?->role_id !== null ? (int) $branch->pivot->role_id : null,
            ])
            ->values()
            ->all();

        return ApiResponse::success([
            'access_token' => $plainToken,
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $effectiveRole,
                'branch_ids' => $user->branches->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                'branches' => $branches,
                'branch_assignments' => array_values(array_filter(
                    array_map(
                        static fn (array $b): ?array => $b['role_id'] !== null
                            ? ['branch_id' => $b['id'], 'role_id' => $b['role_id']]
                            : null,
                        $branches
                    )
                )),
            ],
            'branch_context' => $branchContext,
        ], 'Logged in successfully.');
    }
}
