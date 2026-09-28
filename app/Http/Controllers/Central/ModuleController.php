<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\DTOs\Central\ModuleResponseData;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Module;
use Illuminate\Http\JsonResponse;

class ModuleController extends Controller
{
    public function index(): JsonResponse
    {
        $modules = Module::query()
            ->withCount('tenants')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        return ApiResponse::success(
            ModuleResponseData::collectionToArray($modules),
            'Modules fetched successfully.'
        );
    }
}
