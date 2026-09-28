<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Central\CentralOverviewService;
use Illuminate\Http\JsonResponse;

class CentralOverviewController extends Controller
{
    public function __construct(
        private readonly CentralOverviewService $overview,
    ) {}

    public function __invoke(): JsonResponse
    {
        return ApiResponse::success($this->overview->summary(), 'Overview fetched successfully.');
    }
}
