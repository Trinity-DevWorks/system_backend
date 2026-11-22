<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Accounting\FixedAsset\Http\Requests\DepreciationRunRequest;
use App\Modules\Accounting\FixedAsset\Services\DepreciationRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepreciationRunController extends Controller
{
    public function __construct(
        private readonly DepreciationRunService $runs,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->runs->list(), 'Depreciation runs fetched successfully.');
    }

    public function preview(DepreciationRunRequest $request): JsonResponse
    {
        $branch = $request->validated('branch_id');

        return ApiResponse::success(
            $this->runs->preview((string) $request->validated('month'), $branch !== null ? (int) $branch : null),
            'Depreciation preview fetched successfully.',
        );
    }

    public function store(DepreciationRunRequest $request): JsonResponse
    {
        $branch = $request->validated('branch_id');

        return ApiResponse::success(
            $this->runs->post((string) $request->validated('month'), $this->userId($request), $branch !== null ? (int) $branch : null),
            'Depreciation posted successfully.',
        );
    }

    public function destroy(string $month): JsonResponse
    {
        if (preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $month) !== 1) {
            abort(404, 'Depreciation run not found.');
        }
        $branch = request()->query('branch_id');
        $this->runs->undo($month, is_numeric($branch) ? (int) $branch : null, $this->userId(request()));

        return ApiResponse::success(null, 'Depreciation run undone successfully.');
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }
}
