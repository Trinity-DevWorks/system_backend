<?php

namespace App\Modules\Inventory\Item\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesListSection;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Item\DTOs\ItemData;
use App\Modules\Inventory\Item\DTOs\ItemResponseData;
use App\Modules\Inventory\Item\Http\Requests\StoreItemRequest;
use App\Modules\Inventory\Item\Http\Requests\UpdateItemRequest;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Services\ItemService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    use ResolvesListSection;

    public function __construct(
        private readonly ItemService $itemService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $names = $this->namesResponse($request, function () {
            return $this->itemService->names()->map(fn (Item $item): array => [
                'id' => $item->id,
                'item_code' => $item->item_code,
                'name' => $item->name,
                'track_inventory' => (bool) $item->track_inventory,
                'track_lots' => (bool) $item->track_lots,
                'allow_purchase' => (bool) $item->allow_purchase,
                'is_active' => (bool) $item->is_active,
                'base_uom' => $item->baseUom
                    ? [
                        'id' => $item->baseUom->id,
                        'code' => $item->baseUom->code,
                        'name' => $item->baseUom->name,
                    ]
                    : null,
                'item_type' => $item->itemType
                    ? [
                        'id' => $item->itemType->id,
                        'code' => $item->itemType->code,
                        'name' => $item->itemType->name,
                    ]
                    : null,
            ])->values()->all();
        }, 'Item names fetched successfully.');
        if ($names) {
            return $names;
        }

        return ListPagination::jsonMapped(
            $this->itemService->paginateForTable(
                ListPagination::search($request),
                ListPagination::perPage($request)
            ),
            fn ($items) => ItemResponseData::collectionToArray($items),
            'Items fetched successfully.'
        );
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $item = $this->itemService->create(ItemData::fromStoreRequest($request));

        return ApiResponse::created(
            ItemResponseData::fromModel($item)->toArray(),
            'Item created successfully.'
        );
    }

    public function show(Item $item): JsonResponse
    {
        $item->load(['itemType', 'category', 'brand', 'unitGroup', 'baseUom']);

        return ApiResponse::success(
            ItemResponseData::fromModel($item)->toArray(),
            'Item fetched successfully.'
        );
    }

    public function update(UpdateItemRequest $request, Item $item): JsonResponse
    {
        $updated = $this->itemService->update($item, ItemData::fromUpdateRequest($request, $item));

        return ApiResponse::success(
            ItemResponseData::fromModel($updated)->toArray(),
            'Item updated successfully.'
        );
    }

    public function destroy(Item $item): JsonResponse
    {
        $this->itemService->delete($item);

        return ApiResponse::success(null, 'Item deleted successfully.');
    }
}
