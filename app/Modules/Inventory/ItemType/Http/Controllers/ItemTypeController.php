<?php

namespace App\Modules\Inventory\ItemType\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\ItemType\DTOs\ItemTypeResponseData;
use App\Modules\Inventory\ItemType\Services\ItemTypeCatalogService;
use Illuminate\Http\JsonResponse;

class ItemTypeController extends Controller
{
    public function __construct(
        private readonly ItemTypeCatalogService $itemTypeCatalogService
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            ItemTypeResponseData::collectionToArray($this->itemTypeCatalogService->allOrdered()),
            'Item types fetched successfully.'
        );
    }
}
