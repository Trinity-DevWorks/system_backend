<?php

declare(strict_types=1);

namespace App\Modules\Branch\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesListSection;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Branch\DTOs\BranchData;
use App\Modules\Branch\DTOs\BranchResponseData;
use App\Modules\Branch\Http\Requests\StoreBranchRequest;
use App\Modules\Branch\Http\Requests\UpdateBranchRequest;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    use ResolvesListSection;

    public function __construct(
        private readonly BranchService $branchService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $names = $this->namesResponse(
            $request,
            fn () => BranchResponseData::collectionToArray($this->branchService->list()),
            'Branch names fetched successfully.'
        );
        if ($names) {
            return $names;
        }

        return ListPagination::json(
            $this->branchService->paginateForTable(
                ListPagination::search($request),
                ListPagination::perPage($request)
            ),
            fn (Branch $branch): array => BranchResponseData::fromModel($branch)->toArray(),
            'Branches fetched successfully.'
        );
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        $branch = $this->branchService->create(
            BranchData::fromStoreRequest($request)
        );

        return ApiResponse::created(
            BranchResponseData::fromModel($branch)->toArray(),
            'Branch created successfully.'
        );
    }

    public function show(Branch $branch): JsonResponse
    {
        $branch->loadMissing(['manager:id,name']);

        return ApiResponse::success(
            BranchResponseData::fromModel($branch)->toArray(),
            'Branch fetched successfully.'
        );
    }

    public function update(UpdateBranchRequest $request, Branch $branch): JsonResponse
    {
        $updated = $this->branchService->update(
            $branch,
            BranchData::fromUpdateRequest($request)
        );

        return ApiResponse::success(
            BranchResponseData::fromModel($updated)->toArray(),
            'Branch updated successfully.'
        );
    }

    public function destroy(Branch $branch): JsonResponse
    {
        $this->branchService->delete($branch);

        return ApiResponse::success(null, 'Branch deleted successfully.');
    }
}
