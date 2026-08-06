<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class LogoutController extends Controller
{
    public function __construct(
        private readonly AuditWriter $auditWriter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user instanceof User) {
            $this->auditWriter->write(
                event: 'logout',
                auditable: $user,
                user: $user,
                tags: 'auth,security',
            );

            $token = $user->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }
        }

        return ApiResponse::success(null, 'Logged out successfully.');
    }
}
