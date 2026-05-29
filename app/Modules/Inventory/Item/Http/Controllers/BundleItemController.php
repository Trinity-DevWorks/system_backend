<?php

namespace App\Modules\Inventory\Item\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Item\DTOs\BundleItemResponseData;
use App\Modules\Inventory\Item\Http\Requests\StoreBundleItemRequest;
use App\Modules\Inventory\Item\Http\Requests\SyncBundleItemsRequest;
use App\Modules\Inventory\Item\Http\Requests\UpdateBundleItemRequest;
use App\Modules\Inventory\Item\Models\BundleItem;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Services\BundleItemService;
use Illuminate\Http\JsonResponse;

class BundleItemController extends Controller
{
    public function __construct(
        private readonly BundleItemService $bundleItemService
    ) {}

    public function index(Item $item): JsonResponse
    {
        return ApiResponse::success(
            BundleItemResponseData::collectionToArray($this->bundleItemService->listForBundle($item)),
            'Bundle components fetched successfully.'
        );
    }

    public function store(StoreBundleItemRequest $request, Item $item): JsonResponse
    {
        $row = $this->bundleItemService->addComponent($item, $request->validated());

        return ApiResponse::created(
            BundleItemResponseData::fromModel($row),
            'Bundle component added successfully.'
        );
    }

    public function sync(SyncBundleItemsRequest $request, Item $item): JsonResponse
    {
        $rows = $this->bundleItemService->sync($item, $request->validated('components'));

        return ApiResponse::success(
            BundleItemResponseData::collectionToArray($rows),
            'Bundle components synced successfully.'
        );
    }

    public function update(UpdateBundleItemRequest $request, Item $item, BundleItem $bundleItem): JsonResponse
    {
        $row = $this->bundleItemService->updateQuantity(
            $item,
            $bundleItem,
            (string) $request->validated('quantity')
        );

        return ApiResponse::success(
            BundleItemResponseData::fromModel($row),
            'Bundle component updated successfully.'
        );
    }

    public function destroy(Item $item, BundleItem $bundleItem): JsonResponse
    {
        $this->bundleItemService->removeComponent($item, $bundleItem);

        return ApiResponse::success(null, 'Bundle component removed successfully.');
    }
}
