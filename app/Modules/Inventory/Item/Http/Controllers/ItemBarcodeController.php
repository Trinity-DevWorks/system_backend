<?php

namespace App\Modules\Inventory\Item\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Item\Http\Requests\StoreItemBarcodeRequest;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Services\ItemBarcodeService;
use Illuminate\Http\JsonResponse;

class ItemBarcodeController extends Controller
{
    public function __construct(
        private readonly ItemBarcodeService $itemBarcodeService
    ) {}

    public function store(StoreItemBarcodeRequest $request, Item $item): JsonResponse
    {
        $barcode = $this->itemBarcodeService->store($item, $request->validated());

        return ApiResponse::created([
            'id' => $barcode->id,
            'barcode' => $barcode->barcode,
            'item_unit_of_measurement_id' => $barcode->item_unit_of_measurement_id,
            'is_primary' => (bool) $barcode->is_primary,
            'created_at' => (string) $barcode->created_at,
            'updated_at' => (string) $barcode->updated_at,
        ], 'Barcode created successfully.');
    }
}
