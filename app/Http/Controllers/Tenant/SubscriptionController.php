<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\PlanLimitService;
use Illuminate\Http\JsonResponse;

/**
 * The tenant's own plan, limits (null = unlimited), current usage and subscription dates.
 * Read-only here; plans are managed from the central domain.
 */
class SubscriptionController extends Controller
{
    public function __construct(private readonly PlanLimitService $planLimits) {}

    public function __invoke(): JsonResponse
    {
        return ApiResponse::success(
            [...$this->planLimits->current(), 'usage' => $this->planLimits->usage()],
            'Subscription fetched successfully.'
        );
    }
}
