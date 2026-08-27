<?php

declare(strict_types=1);

namespace App\Modules\Salesman\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesListSection;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Salesman\DTOs\SalesmanData;
use App\Modules\Salesman\DTOs\SalesmanResponseData;
use App\Modules\Salesman\Http\Requests\StoreSalesmanRequest;
use App\Modules\Salesman\Http\Requests\UpdateSalesmanRequest;
use App\Modules\Salesman\Models\Salesman;
use App\Modules\Salesman\Services\SalesmanService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesmanController extends Controller
{
    use ResolvesListSection;

    public function __construct(
        private readonly SalesmanService $salesmanService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $names = $this->namesResponse(
            $request,
            fn () => SalesmanResponseData::collectionToArray($this->salesmanService->list()),
            'Salesman names fetched successfully.'
        );
        if ($names) {
            return $names;
        }

        return ListPagination::json(
            $this->salesmanService->paginateForTable(
                ListPagination::search($request),
                ListPagination::perPage($request)
            ),
            fn (Salesman $salesman): array => SalesmanResponseData::fromModel($salesman)->toArray(),
            'Salesmen fetched successfully.'
        );
    }

    public function store(StoreSalesmanRequest $request): JsonResponse
    {
        $salesman = $this->salesmanService->create(
            SalesmanData::fromStoreRequest($request)
        );

        return ApiResponse::created(
            SalesmanResponseData::fromModel($salesman)->toArray(),
            'Salesman created successfully.'
        );
    }

    public function show(Salesman $salesman): JsonResponse
    {
        $this->salesmanService->assertVisible($salesman);
        $salesman->loadMissing(['branch:id,name', 'warehouse:id,name', 'user:id,name']);

        return ApiResponse::success(
            SalesmanResponseData::fromModel($salesman)->toArray(),
            'Salesman fetched successfully.'
        );
    }

    public function update(UpdateSalesmanRequest $request, Salesman $salesman): JsonResponse
    {
        $updated = $this->salesmanService->update(
            $salesman,
            SalesmanData::fromUpdateRequest($request)
        );

        return ApiResponse::success(
            SalesmanResponseData::fromModel($updated)->toArray(),
            'Salesman updated successfully.'
        );
    }

    public function destroy(Salesman $salesman): JsonResponse
    {
        $this->salesmanService->delete($salesman);

        return ApiResponse::success(null, 'Salesman deleted successfully.');
    }
}
