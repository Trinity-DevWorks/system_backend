<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Branch\Services\BranchContextService;
use Illuminate\Http\JsonResponse;

class MeController extends Controller
{
    public function __construct(
        private readonly BranchContextService $branchContext,
    ) {}

    public function __invoke(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $user->loadMissing(['role:id,name', 'branches:id,name']);

        $branchContext = $this->branchContext->contextPayload($user);

        return ApiResponse::success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'active' => (bool) $user->active,
            'role' => $user->role_id !== null
                ? ['id' => (int) $user->role_id, 'name' => $user->role?->name]
                : null,
            'branches' => $user->branches
                ->map(fn ($b): array => [
                    'id' => (int) $b->id,
                    'name' => (string) $b->name,
                ])
                ->values()
                ->all(),
            'branch_ids' => $user->branches->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'branch_context' => $branchContext,
        ], 'Current user fetched successfully.');
    }
}
