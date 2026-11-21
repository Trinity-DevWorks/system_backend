<?php

declare(strict_types=1);

namespace App\Modules\Overview\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Overview\Services\TenantOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverviewController extends Controller
{
    public function __construct(
        private readonly TenantOverviewService $overview,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return ApiResponse::success(
            $this->overview->summary($user),
            'Overview fetched successfully.',
        );
    }
}
