<?php

namespace App\Modules\Rbac\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Rbac\Http\Requests\LoginRequest;
use App\Services\AuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function __construct(
        private readonly AuditWriter $auditWriter,
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

        $user->loadMissing(['role:id,name', 'branches:id,name,shortcut_name,is_default']);

        $branchContext = app(\App\Modules\Branch\Services\BranchContextService::class)
            ->contextPayload($user);

        return ApiResponse::success([
            'access_token' => $plainToken,
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role_id' => $user->role_id,
                'role' => $user->role_id !== null
                    ? ['id' => (int) $user->role_id, 'name' => $user->role?->name]
                    : null,
                'branch_ids' => $user->branches->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            ],
            'branch_context' => $branchContext,
        ], 'Logged in successfully.');
    }
}
