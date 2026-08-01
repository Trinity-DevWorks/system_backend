<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\ModuleEntitlementService;
use Illuminate\Http\JsonResponse;

class ModuleController extends Controller
{
    public function __construct(private readonly ModuleEntitlementService $modules) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            $this->modules->catalog(),
            'Modules fetched successfully.'
        );
    }
}
