<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\DTOs\Central\PlanResponseData;
use App\Http\Controllers\Concerns\ResolvesListSection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Central\StorePlanRequest;
use App\Http\Requests\Central\UpdatePlanRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Central\Plan;
use App\Services\Central\PlanService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    use ResolvesListSection;

    public function __construct(
        private readonly PlanService $plans,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $names = $this->namesResponse(
            $request,
            fn (): array => PlanResponseData::collectionToArray($this->plans->list()),
            'Plan names fetched successfully.'
        );
        if ($names) {
            return $names;
        }

        return ListPagination::json(
            $this->plans->paginateForTable(
                ListPagination::search($request),
                ListPagination::perPage($request)
            ),
            fn (Plan $plan): array => PlanResponseData::fromModel($plan)->toArray(),
            'Plans fetched successfully.'
        );
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        return ApiResponse::created(
            PlanResponseData::fromModel($this->plans->create($request->validated()))->toArray(),
            'Plan created successfully.'
        );
    }

    public function show(Plan $plan): JsonResponse
    {
        return ApiResponse::success(
            PlanResponseData::fromModel($plan->load('modules')->loadCount('tenants'))->toArray(),
            'Plan fetched successfully.'
        );
    }

    public function update(UpdatePlanRequest $request, Plan $plan): JsonResponse
    {
        return ApiResponse::success(
            PlanResponseData::fromModel($this->plans->update($plan, $request->validated()))->toArray(),
            'Plan updated successfully.'
        );
    }

    public function destroy(Plan $plan): JsonResponse
    {
        $this->plans->delete($plan);

        return ApiResponse::success(null, 'Plan deleted successfully.');
    }
}
