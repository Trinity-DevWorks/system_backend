<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Rbac\Http\Requests\LoginRequest;
use App\Services\AuditWriter;
use App\Services\CentralPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class CentralAuthController extends Controller
{
    public function __construct(
        private readonly AuditWriter $auditWriter,
        private readonly CentralPermissionService $permissionService,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $email = (string) $request->validated('email');
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($request->validated('password'), (string) $user->password)) {
            $this->auditWriter->write(
                event: 'login_failed',
                auditable: $user,
                user: null,
                newValues: ['email' => $email, 'scope' => 'central'],
                tags: 'auth,security,central',
            );

            return ApiResponse::error('Invalid credentials.', 422, null, [], null, null, 'INVALID_CREDENTIALS');
        }

        if (! $user->is_active) {
            $this->auditWriter->write(
                event: 'login_failed',
                auditable: $user,
                user: null,
                newValues: ['email' => $email, 'reason' => 'inactive', 'scope' => 'central'],
                tags: 'auth,security,central',
            );

            return ApiResponse::forbidden('Account is inactive.', 'ACCOUNT_INACTIVE');
        }

        $plainToken = $user->createToken('central')->plainTextToken;

        $this->auditWriter->write(
            event: 'login',
            auditable: $user,
            user: $user,
            tags: 'auth,security,central',
        );

        return ApiResponse::success([
            'access_token' => $plainToken,
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $this->permissionService->resolveRole($user),
            ],
            'permissions' => (object) $this->permissionService->matrixForUser($user),
        ], 'Logged in successfully.');
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user instanceof User) {
            $token = $user->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            $this->auditWriter->write(
                event: 'logout',
                auditable: $user,
                user: $user,
                tags: 'auth,security,central',
            );
        }

        return ApiResponse::success(null, 'Logged out successfully.');
    }
}
