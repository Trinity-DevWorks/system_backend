<?php

declare(strict_types=1);

namespace App\Modules\Inventory\PriceChange\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\PriceChange\Http\Requests\BulkPriceChangeRequest;
use App\Modules\Inventory\PriceChange\Http\Requests\SavePriceChangeRequest;
use App\Modules\Inventory\PriceChange\Models\PriceChange;
use App\Modules\Inventory\PriceChange\Services\PriceChangeService;
use App\Modules\Inventory\PriceChange\Services\PriceReportService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PriceChangeController extends Controller
{
    public function __construct(
        private readonly PriceChangeService $service,
        private readonly PriceReportService $reports,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->service->paginate([
            'search' => ListPagination::search($request),
            'status' => $request->filled('status') ? (string) $request->query('status') : null,
        ], ListPagination::perPage($request));

        return ListPagination::json($page, fn (PriceChange $doc): array => $this->service->presentList($doc), 'Price changes fetched successfully.');
    }

    public function store(SavePriceChangeRequest $request): JsonResponse
    {
        $doc = $this->service->create($request->validated());

        return ApiResponse::created($this->service->present($doc), 'Price change created successfully.');
    }

    public function show(PriceChange $priceChange): JsonResponse
    {
        return ApiResponse::success($this->service->present($this->service->find((string) $priceChange->id)), 'Price change fetched successfully.');
    }

    public function update(SavePriceChangeRequest $request, PriceChange $priceChange): JsonResponse
    {
        $doc = $this->service->update($priceChange, $request->validated());

        return ApiResponse::success($this->service->present($doc), 'Price change updated successfully.');
    }

    public function destroy(PriceChange $priceChange): JsonResponse
    {
        $this->service->delete($priceChange);

        return ApiResponse::success(null, 'Price change deleted successfully.');
    }

    public function bulkPreview(BulkPriceChangeRequest $request): JsonResponse
    {
        return ApiResponse::success($this->service->previewBulk($request->validated()), 'Prices calculated successfully.');
    }

    public function apply(PriceChange $priceChange): JsonResponse
    {
        $result = $this->service->apply($priceChange);

        return ApiResponse::success(
            $this->service->present($result['document'], [
                'below_cost' => $result['below_cost'],
                'changed_item_ids' => $result['changed_item_ids'],
            ]),
            $result['document']->status->value === 'scheduled'
                ? 'Price change scheduled successfully.'
                : 'Price change applied successfully.',
        );
    }

    public function cancel(PriceChange $priceChange): JsonResponse
    {
        return ApiResponse::success($this->service->present($this->service->cancel($priceChange)), 'Price change cancelled successfully.');
    }

    public function itemHistory(Item $item): JsonResponse
    {
        return ApiResponse::success($this->reports->timeline((string) $item->id), 'Price history fetched successfully.');
    }
}
