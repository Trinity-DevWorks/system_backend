<?php

declare(strict_types=1);

namespace App\Modules\Salesman\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Salesman\DTOs\SalesmanData;
use App\Modules\Salesman\DTOs\SalesmanResponseData;
use App\Modules\Salesman\Http\Requests\StoreSalesmanRequest;
use App\Modules\Salesman\Http\Requests\UpdateSalesmanRequest;
use App\Modules\Salesman\Models\Salesman;
use App\Modules\Salesman\Services\SalesmanService;
use Illuminate\Http\JsonResponse;

class SalesmanController extends Controller
{
    public function __construct(
        private readonly SalesmanService $salesmanService
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            SalesmanResponseData::collectionToArray($this->salesmanService->list()),
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
        $salesman->loadMissing(['warehouse:id,name', 'user:id,name']);

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
