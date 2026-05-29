<?php

namespace App\Modules\Inventory\Item\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Item\DTOs\ItemBarcodeResponseData;
use App\Modules\Inventory\Item\Http\Requests\StoreItemBarcodeRequest;
use App\Modules\Inventory\Item\Http\Requests\UpdateItemBarcodeRequest;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemBarcode;
use App\Modules\Inventory\Item\Services\ItemBarcodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemBarcodeController extends Controller
{
    public function __construct(
        private readonly ItemBarcodeService $itemBarcodeService
    ) {}

    public function lookup(Request $request): JsonResponse
    {
        $barcode = trim((string) $request->query('barcode', ''));
        if ($barcode === '') {
            return ApiResponse::error('Barcode is required.', 422, null, ['barcode' => ['Barcode is required.']], null, null, 'BARCODE_REQUIRED');
        }

        $result = $this->itemBarcodeService->lookup($barcode);
        if ($result === null) {
            return ApiResponse::error('No item found for this barcode.', 404, null, [], null, null, 'BARCODE_NOT_FOUND');
        }

        return ApiResponse::success($result, 'Barcode resolved successfully.');
    }

    public function index(Item $item): JsonResponse
    {
        return ApiResponse::success(
            ItemBarcodeResponseData::collectionToArray($this->itemBarcodeService->listForItem($item)),
            'Item barcodes fetched successfully.'
        );
    }

    public function store(StoreItemBarcodeRequest $request, Item $item): JsonResponse
    {
        $barcode = $this->itemBarcodeService->store($item, $request->validated());

        return ApiResponse::created(
            ItemBarcodeResponseData::fromModel($barcode),
            'Barcode created successfully.'
        );
    }

    public function update(UpdateItemBarcodeRequest $request, Item $item, ItemBarcode $itemBarcode): JsonResponse
    {
        $barcode = $this->itemBarcodeService->update($item, $itemBarcode, $request->validated());

        return ApiResponse::success(
            ItemBarcodeResponseData::fromModel($barcode),
            'Barcode updated successfully.'
        );
    }

    public function destroy(Item $item, ItemBarcode $itemBarcode): JsonResponse
    {
        $this->itemBarcodeService->delete($item, $itemBarcode);

        return ApiResponse::success(null, 'Barcode deleted successfully.');
    }
}
