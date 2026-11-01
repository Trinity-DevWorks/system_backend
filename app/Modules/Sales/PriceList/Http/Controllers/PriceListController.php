<?php

declare(strict_types=1);

namespace App\Modules\Sales\PriceList\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesListSection;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Sales\PriceList\DTOs\PriceListResponseData;
use App\Modules\Sales\PriceList\Http\Requests\ResolvePricesRequest;
use App\Modules\Sales\PriceList\Http\Requests\SavePriceListRequest;
use App\Modules\Sales\PriceList\Models\PriceList;
use App\Modules\Sales\PriceList\Services\PriceListService;
use App\Modules\Sales\PriceList\Services\PriceResolver;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PriceListController extends Controller
{
    use ResolvesListSection;

    public function __construct(
        private readonly PriceListService $service,
        private readonly PriceResolver $resolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $names = $this->namesResponse(
            $request,
            fn () => $this->service->list()->map(fn (PriceList $l): array => PriceListResponseData::summary($l))->values()->all(),
            'Price list names fetched successfully.'
        );
        if ($names) {
            return $names;
        }

        return ListPagination::json(
            $this->service->paginateForTable(ListPagination::search($request), ListPagination::perPage($request)),
            fn (PriceList $list): array => PriceListResponseData::summary($list),
            'Price lists fetched successfully.'
        );
    }

    public function store(SavePriceListRequest $request): JsonResponse
    {
        return ApiResponse::created(
            PriceListResponseData::detail($this->service->create($request->validated())),
            'Price list created successfully.'
        );
    }

    public function show(PriceList $price_list): JsonResponse
    {
        return ApiResponse::success(
            PriceListResponseData::detail($this->service->find((int) $price_list->id)),
            'Price list fetched successfully.'
        );
    }

    public function update(SavePriceListRequest $request, PriceList $price_list): JsonResponse
    {
        return ApiResponse::success(
            PriceListResponseData::detail($this->service->update($price_list, $request->validated())),
            'Price list updated successfully.'
        );
    }

    public function destroy(PriceList $price_list): JsonResponse
    {
        $this->service->delete($price_list);

        return ApiResponse::success(null, 'Price list deleted successfully.');
    }

    /**
     * Default unit prices for document lines (customer / group / branch / default list, else catalog).
     */
    public function resolve(ResolvePricesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $branchId = $request->attributes->get('active_branch_id');

        return ApiResponse::success(
            $this->resolver->resolve(
                isset($data['customer_id']) ? (string) $data['customer_id'] : null,
                (int) $data['currency_id'],
                $data['exchange_rate'] ?? '1',
                (string) ($data['date'] ?? now()->toDateString()),
                $branchId !== null ? (int) $branchId : null,
                array_values($data['lines']),
            ),
            'Prices resolved successfully.'
        );
    }
}
