<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Reports\Services\OverviewService;
use Illuminate\Http\JsonResponse;

class OverviewController extends Controller
{
    public function __construct(
        private readonly OverviewService $overview,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->overview->snapshot(), 'Overview fetched successfully.');
    }
}
